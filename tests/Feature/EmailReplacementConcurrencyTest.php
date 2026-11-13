<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\EmailReplacementLinkNotification;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use App\Services\EmailReplacementService;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lost-mailbox replacement and recovery expiry under real concurrency, in separate PHP processes
 * against MariaDB, through the real routes. Runs only through scripts/Test-MariaDB.ps1, on the
 * throwaway enotary_phpunit database, rebuilt before and after each test. Synthetic data.
 *
 * - Two administrators starting a replacement for one account at once: one waiting replacement.
 * - The same link confirmed twice at once (a replay): one confirmation, one password link.
 * - A confirmation and a cancellation at once: exactly one of them takes effect.
 * - A confirmation and a registration of the new address at once: one holder of the address.
 * - An expired recovery request approved and closed at once: never approved, closed once.
 */
class EmailReplacementConcurrencyTest extends TestCase
{
    private User $x;

    private User $y;

    private SupportTicket $ticket;

    private string $dir;

    private int $n = 10;

    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB: run scripts\Test-MariaDB.ps1.');
        }

        // TestCase has already refused anything but enotary_phpunit on this machine.
        $this->assertSame('enotary_phpunit', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $this->x = $this->person('admin');
        $this->y = $this->person('admin');
        $this->ticket = SupportTicket::create(['ticket_number' => 'ST-RACE-1', 'user_id' => $this->person('public')->id, 'subject' => 'Lost mailbox']);

        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            if ($event->notification instanceof EmailReplacementLinkNotification) {
                $this->tokens[] = $event->notification->token;
            }
        });

        $this->dir = storage_path('framework/testing/replacement-race-'.getmypid());
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->artisan('migrate:fresh');
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function person(string $role, array $values = []): User
    {
        $n = ++$this->n;
        $user = User::create($values + ['name' => "Replace {$role} {$n}", 'email' => "repl-{$role}-{$n}@example.test", 'mobile' => '95500'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(['mobile_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user->fresh();
    }

    /** A restored account, locked until a new password is set. */
    private function locked(): User
    {
        $account = $this->person('public');
        $service = app(AccountDeletionService::class);
        $service->delete($this->x, $account, 'Synthetic deletion for testing.');
        $service->restore($this->x, $account->fresh(), 'Synthetic restore for testing.');

        return $account->fresh();
    }

    private function form(string $newEmail): array
    {
        return ['new_email' => $newEmail, 'support_ticket_id' => $this->ticket->id, 'identity_check' => 'video_call',
            'identity_note' => 'Video call, face matched the profile photo.', 'reason' => 'Owner lost the old mailbox; identity confirmed on video.', 'identity_confirmed' => '1'];
    }

    /** A waiting replacement made in this process, and its link token. */
    private function waiting(User $account, string $newEmail): array
    {
        $this->tokens = [];
        $replacement = app(EmailReplacementService::class)->start($this->x, $account, $this->form($newEmail));
        $this->assertCount(1, $this->tokens);

        return [$replacement, $this->tokens[0]];
    }

    private function start(string $name, string $action, bool $hold, int $actor, int $target, array $extra, float $startAt, string $waitFor = '-')
    {
        $out = "{$this->dir}/{$name}.json";
        @unlink($out);
        @unlink($out.'.locked');

        // Windows drops a variable set to an empty string, and .env would then fill the gap. So
        // the SMS key is switched off with a real value (MSG91_ENABLED=false), never just emptied.
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'MSG91_ENABLED' => 'false', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null',
        ]);

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/recovery-race-worker.php'), $action, $hold ? '1' : '0', (string) $actor, (string) $target,
                json_encode($extra), sprintf('%.6F', $startAt), '1500', $waitFor, $out],
            [0 => ['pipe', 'r'], 1 => ['file', "{$this->dir}/{$name}.stdout", 'w'], 2 => ['file', "{$this->dir}/{$name}.stderr", 'w']],
            $pipes, base_path(), $env,
        );

        fclose($pipes[0]);

        return [$process, $out];
    }

    private function finish(array $worker): array
    {
        [$process, $out] = $worker;
        $code = proc_close($process);

        $this->assertSame(0, $code, 'worker failed: '.@file_get_contents(str_replace('.json', '.stderr', $out)).@file_get_contents($out));

        return json_decode((string) file_get_contents($out), true);
    }

    private function overlapped(array $a, array $b): bool
    {
        return $a['began'] < $b['ended'] && $b['began'] < $a['ended'];
    }

    private function confirmed(array $result): bool
    {
        return $result['code'] === 302;
    }

    public function test_two_administrators_starting_at_once_make_one_waiting_replacement(): void
    {
        $wins = ['x' => 0, 'y' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            $account = $this->locked();
            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $a = $this->start("sx-{$round}", 'replace-start', $hold, $this->x->id, $account->id, $this->form("new-x-{$round}@example.test"), $startAt);
            $b = $this->start("sy-{$round}", 'replace-start', false, $this->y->id, $account->id, $this->form("new-y-{$round}@example.test"), $startAt, $hold ? "{$this->dir}/sx-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rb = $this->finish($b);
            $label = "round {$round}: ".json_encode([$ra, $rb]);

            $okA = str_starts_with((string) $ra['status'], 'Reference ');
            $okB = str_starts_with((string) $rb['status'], 'Reference ');
            $this->assertNotSame($okA, $okB, "{$label}: exactly one starts");
            $this->assertContains($okA ? $rb['error'] : $ra['error'], [
                'A new address is already waiting to be confirmed for this account. Cancel it first.',
                'That address is already proposed for another account, or a replacement is already waiting here. Nothing was changed.',
            ], $label);

            $this->assertSame(1, EmailReplacement::where('user_id', $account->id)->count(), $label);
            $this->assertSame(1, EmailReplacement::where('user_id', $account->id)->where('status', EmailReplacement::PENDING)->count(), $label);
            $this->assertSame(1, AuditLog::where('action', 'admin.email_replacement_started')->where('model_id', $account->id)->count(), $label);
            $this->assertSame($account->email, $account->fresh()->email, "{$label}: nothing changed yet");

            if ($hold) {
                $this->assertTrue($okA, "{$label}: the holder wins");
                $this->assertGreaterThan(1.2, $rb['ended'] - $rb['began'], "{$label}: the second did not wait");
            } elseif ($this->overlapped($ra, $rb)) {
                $overlapping++;
            }

            $wins[$okA ? 'x' : 'y']++;
        }

        fwrite(STDERR, sprintf("\n[replace start vs start] 15 rounds (5 hold, 10 same moment, %d overlapping); first won %d, second won %d\n", $overlapping, $wins['x'], $wins['y']));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_the_same_link_confirmed_twice_at_once_takes_effect_once(): void
    {
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            $account = $this->locked();
            $newEmail = "replay-{$round}@example.test";
            [$replacement, $token] = $this->waiting($account, $newEmail);
            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $a = $this->start("ca-{$round}", 'replace-confirm', $hold, 0, $replacement->id, ['token' => $token], $startAt);
            $b = $this->start("cb-{$round}", 'replace-confirm', false, 0, $replacement->id, ['token' => $token], $startAt, $hold ? "{$this->dir}/ca-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rb = $this->finish($b);
            $label = "round {$round}: ".json_encode([$ra, $rb]);

            $this->assertNotSame($this->confirmed($ra), $this->confirmed($rb), "{$label}: one confirmation");
            $this->assertSame(410, $this->confirmed($ra) ? $rb['code'] : $ra['code'], $label);

            $this->assertSame($newEmail, $account->fresh()->email, $label);
            $this->assertTrue($account->fresh()->mustResetPassword(), "{$label}: still locked");
            $this->assertSame(EmailReplacement::VERIFIED, $replacement->fresh()->status);
            $this->assertSame(1, AuditLog::where('action', 'account.email_replaced')->where('model_id', $account->id)->count(), $label);
            $this->assertSame(1, AuditLog::where('action', 'account.email_replacement_refused')->where('meta->reference', $replacement->reference)->where('meta->result', EmailReplacementService::USED)->count(), $label);
            $this->assertSame(1, DB::table(config('auth.passwords.users.table'))->where('email', $newEmail)->count(), "{$label}: one password link");

            if ($hold) {
                $this->assertTrue($this->confirmed($ra), "{$label}: the holder wins");
                $this->assertGreaterThan(1.2, $rb['ended'] - $rb['began'], "{$label}: the replay did not wait");
            } elseif ($this->overlapped($ra, $rb)) {
                $overlapping++;
            }
        }

        fwrite(STDERR, sprintf("\n[replace confirm vs confirm] 15 rounds (5 hold, 10 same moment, %d overlapping); exactly one confirmation each round\n", $overlapping));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_a_confirmation_and_a_cancellation_at_once_leave_exactly_one_outcome(): void
    {
        $tally = ['confirmed' => 0, 'cancelled' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            $account = $this->locked();
            $oldEmail = $account->email;
            $newEmail = "contest-{$round}@example.test";
            [$replacement, $token] = $this->waiting($account, $newEmail);
            $mode = $round <= 5 ? 'confirm first' : ($round <= 10 ? 'cancel first' : 'same moment');
            $startAt = microtime(true) + 3;

            $c = $this->start("cf-{$round}", 'replace-confirm', $mode === 'confirm first', 0, $replacement->id, ['token' => $token], $startAt, $mode === 'cancel first' ? "{$this->dir}/cn-{$round}.json.locked" : '-');
            $k = $this->start("cn-{$round}", 'replace-cancel', $mode === 'cancel first', $this->x->id, $replacement->id, [], $startAt, $mode === 'confirm first' ? "{$this->dir}/cf-{$round}.json.locked" : '-');

            $rc = $this->finish($c);
            $rk = $this->finish($k);
            $label = "round {$round} ({$mode}): ".json_encode([$rc, $rk]);

            $confirmed = $this->confirmed($rc);
            $cancelled = $rk['status'] === 'The replacement was cancelled. Its link no longer works.';
            $this->assertNotSame($confirmed, $cancelled, "{$label}: exactly one takes effect");

            $fresh = $replacement->fresh();
            $this->assertSame($confirmed ? EmailReplacement::VERIFIED : EmailReplacement::CANCELLED, $fresh->status, $label);
            $this->assertSame($confirmed ? $newEmail : $oldEmail, $account->fresh()->email, $label);
            $this->assertNull($fresh->open_key);

            if ($mode === 'confirm first') {
                $this->assertTrue($confirmed, $label);
                $this->assertSame('This replacement is no longer waiting, so it cannot be cancelled.', $rk['error'], $label);
            } elseif ($mode === 'cancel first') {
                $this->assertTrue($cancelled, $label);
                $this->assertSame(410, $rc['code'], $label);
            } elseif ($this->overlapped($rc, $rk)) {
                $overlapping++;
            }

            $tally[$confirmed ? 'confirmed' : 'cancelled']++;
        }

        fwrite(STDERR, sprintf("\n[replace confirm vs cancel] 15 rounds (5 confirm first, 5 cancel first, 5 same moment, %d overlapping); confirmed %d, cancelled %d\n", $overlapping, $tally['confirmed'], $tally['cancelled']));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_a_confirmation_and_a_registration_of_the_new_address_leave_one_holder(): void
    {
        $tally = ['confirmed' => 0, 'registered' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            $account = $this->locked();
            $oldEmail = $account->email;
            $newEmail = "claimed-{$round}@example.test";
            [$replacement, $token] = $this->waiting($account, $newEmail);
            $mode = $round <= 5 ? 'confirm first' : ($round <= 10 ? 'registration first' : 'same moment');
            $startAt = microtime(true) + 3;

            $c = $this->start("rc-{$round}", 'replace-confirm', $mode === 'confirm first', 0, $replacement->id, ['token' => $token], $startAt, $mode === 'registration first' ? "{$this->dir}/rr-{$round}.json.locked" : '-');
            $r = $this->start("rr-{$round}", 'register', $mode === 'registration first', 0, 0, ['email' => $newEmail, 'mobile' => '93200'.str_pad((string) $round, 5, '0', STR_PAD_LEFT)], $startAt, $mode === 'confirm first' ? "{$this->dir}/rc-{$round}.json.locked" : '-');

            $rc = $this->finish($c);
            $rr = $this->finish($r);
            $label = "round {$round} ({$mode}): ".json_encode([$rc, $rr]);

            $confirmed = $this->confirmed($rc);
            $registered = $rr['code'] === 200;
            $this->assertNotSame($confirmed, $registered, "{$label}: exactly one gets the address");
            $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [$newEmail])->count(), "{$label}: one holder of the new address");
            $this->assertSame($confirmed ? $newEmail : $oldEmail, $account->fresh()->email, $label);
            $this->assertTrue($account->fresh()->mustResetPassword(), "{$label}: still locked");

            if ($mode === 'confirm first') {
                $this->assertTrue($confirmed, $label);
            } elseif ($mode === 'registration first') {
                $this->assertTrue($registered, $label);
                $this->assertSame(410, $rc['code'], $label);
            } elseif ($this->overlapped($rc, $rr)) {
                $overlapping++;
            }

            $tally[$confirmed ? 'confirmed' : 'registered']++;
        }

        fwrite(STDERR, sprintf("\n[replace confirm vs register] 15 rounds (5 confirm first, 5 registration first, 5 same moment, %d overlapping); confirmation won %d, registration won %d\n", $overlapping, $tally['confirmed'], $tally['registered']));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_an_expired_request_approved_and_closed_at_once_is_never_approved(): void
    {
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            $old = $this->person('public');
            app(AccountDeletionService::class)->delete($this->x, $old, 'Synthetic deletion for testing.');
            $deletion = AccountDeletion::where('user_id', $old->id)->sole();
            $requester = $this->person('public', ['mobile' => $deletion->mobile]);
            DB::table('users')->where('id', $requester->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);
            $recovery = app(AccountRecoveryService::class)->submit($requester->fresh(), $deletion->id);
            // Made 31 days ago, by the application's clock.
            DB::table('account_recovery_requests')->where('id', $recovery->id)->update(['created_at' => now()->subDays(31)]);

            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $a = $this->start("ea-{$round}", 'approve', $hold, $this->x->id, $recovery->id, [], $startAt);
            $c = $this->start("ec-{$round}", 'cancel', false, $this->y->id, $recovery->id, [], $startAt, $hold ? "{$this->dir}/ea-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rc = $this->finish($c);
            $label = "round {$round}: ".json_encode([$ra, $rc]);

            $this->assertNull($ra['status'], "{$label}: never approved");
            $this->assertContains($ra['error'], [
                'This request is more than 30 days old, so its proof is too old to rely on. Close it: the person can then ask again with fresh proof.',
                'This request has already been decided.',
            ], $label);
            $this->assertSame('The request was cancelled.', $rc['status'], "{$label}: closed");

            $this->assertSame(AccountRecoveryRequest::EXPIRED, $recovery->fresh()->status, $label);
            $this->assertTrue($old->fresh()->isDeleted(), "{$label}: nothing restored");
            $this->assertSame(0, AuditLog::where('action', 'account.recovery_approved')->where('model_id', $recovery->id)->count(), $label);
            $this->assertSame(1, AuditLog::where('action', 'account.recovery_expired')->where('model_id', $recovery->id)->count(), $label);
            $this->assertSame(User::CHOICE_CHECK, $requester->fresh()->previous_account_choice, $label);

            if ($hold) {
                $this->assertGreaterThan(1.2, $rc['ended'] - $rc['began'], "{$label}: the closing did not wait");
            } elseif ($this->overlapped($ra, $rc)) {
                $overlapping++;
            }
        }

        fwrite(STDERR, sprintf("\n[expired: approve vs close] 15 rounds (5 hold, 10 same moment, %d overlapping); never approved, closed once each round\n", $overlapping));
        $this->assertGreaterThan(0, $overlapping);
    }
}
