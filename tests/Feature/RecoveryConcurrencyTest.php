<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 4 under real concurrency, in separate PHP processes against MariaDB, through the real
 * routes where there is one. SQLite has no row locks, so this runs only through
 * scripts/Test-MariaDB.ps1, on its throwaway enotary_phpunit database.
 *
 * - Two administrators approving one request at once: one approval, one restoration, one link.
 * - An approval and a stranger registering the same released email at once: exactly one active
 *   account holds the email afterwards, whichever comes first, and nothing is lost.
 * - Two people submitting for the same archived account at once: one open request.
 *
 * "hold" rounds fix the order, so they are deterministic; "same moment" rounds start both sides
 * together and report who won. The workers need committed rows, so this class does not use
 * RefreshDatabase; it rebuilds the throwaway schema before and after each test. Synthetic data.
 */
class RecoveryConcurrencyTest extends TestCase
{
    private const APPROVED = 'Recovery approved. The original account was restored and needs a new password.';

    private const CONFLICT = 'This request has an identity conflict. Confirm the resolution and give a reason.';

    private const TAKEN = 'This email or mobile number is already registered.';

    private User $x;

    private User $y;

    private string $dir;

    private int $n = 10;

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

        $this->dir = storage_path('framework/testing/recovery-race-'.getmypid());
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

        $user = User::create($values + [
            'name' => "Recovery {$role} {$n}", 'email' => "rec-{$role}-{$n}@example.test", 'mobile' => '94900'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now(), 'email_verification_method' => 'signed_link',
            'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user->fresh();
    }

    /** An archived Citizen with released identity, and a new account found by its number. */
    private function archivedWithRequester(): array
    {
        $old = $this->person('public');
        app(AccountDeletionService::class)->delete($this->x, $old, 'Synthetic deletion for testing.');
        $deletion = AccountDeletion::where('user_id', $old->id)->sole();

        $requester = $this->person('public', ['mobile' => $deletion->mobile]);
        DB::table('users')->where('id', $requester->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);

        return [$old->fresh(), $deletion, $requester->fresh()];
    }

    /** As after the code emailed to the previous address was checked. */
    private function openRequest(User $requester, AccountDeletion $deletion): AccountRecoveryRequest
    {
        $recovery = app(AccountRecoveryService::class)->submit($requester, $deletion->id);
        $this->assertNotNull($recovery);

        return $recovery;
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

    /** Exactly one account that is not archived holds this email, and history was not reduced. */
    private function assertOneHolder(string $email, string $round): void
    {
        $this->assertSame(1, User::whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [strtolower($email)])->count(), "{$round}: one active holder of the email");
    }

    public function test_two_administrators_approving_at_once_restore_the_account_once(): void
    {
        $wins = ['x' => 0, 'y' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            [$old, $deletion, $requester] = $this->archivedWithRequester();
            $recovery = $this->openRequest($requester, $deletion);
            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $a = $this->start("ax-{$round}", 'approve', $hold, $this->x->id, $recovery->id, [], $startAt);
            $b = $this->start("ay-{$round}", 'approve', false, $this->y->id, $recovery->id, [], $startAt, $hold ? "{$this->dir}/ax-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rb = $this->finish($b);
            $label = "round {$round}: ".json_encode([$ra, $rb]);

            $this->assertNotSame($ra['status'] === self::APPROVED, $rb['status'] === self::APPROVED, $label);
            $loser = $ra['status'] === self::APPROVED ? $rb : $ra;
            $this->assertSame('This request has already been decided.', $loser['error'], $label);

            // One approval, one restoration, one replaced account, one link.
            $this->assertSame(AccountRecoveryRequest::APPROVED, $recovery->fresh()->status);
            $this->assertSame(1, AuditLog::where('action', 'account.recovery_approved')->where('model_id', $recovery->id)->count(), $label);
            $this->assertNotNull($deletion->fresh()->restored_at);
            $this->assertSame(1, AccountDeletion::where('user_id', $requester->id)->count(), "{$label}: requester archived once");
            $this->assertSame(1, DB::table(config('auth.passwords.users.table'))->where('email', $deletion->email)->count());
            $this->assertTrue($old->fresh()->mustResetPassword());
            $this->assertOneHolder($deletion->email, "round {$round}");

            if ($hold) {
                $this->assertSame(self::APPROVED, $ra['status'], "{$label}: the holder wins");
                $this->assertGreaterThan(1.2, $rb['ended'] - $rb['began'], "{$label}: the second did not wait");
            } elseif ($this->overlapped($ra, $rb)) {
                $overlapping++;
            }

            $wins[$ra['status'] === self::APPROVED ? 'x' : 'y']++;
        }

        fwrite(STDERR, sprintf("\n[approve vs approve] 15 rounds (5 hold, 10 same moment, %d overlapping); first won %d, second won %d\n", $overlapping, $wins['x'], $wins['y']));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_an_approval_and_a_rejection_at_once_make_exactly_one_decision(): void
    {
        $tally = ['approved' => 0, 'rejected' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            [$old, $deletion, $requester] = $this->archivedWithRequester();
            $recovery = $this->openRequest($requester, $deletion);
            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            // Rounds 1-5: the approval holds its locks first. 6-15: same moment.
            $a = $this->start("ok-{$round}", 'approve', $hold, $this->x->id, $recovery->id, [], $startAt);
            $r = $this->start("no-{$round}", 'reject', false, $this->y->id, $recovery->id, [], $startAt, $hold ? "{$this->dir}/ok-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rr = $this->finish($r);
            $label = "round {$round}: ".json_encode([$ra, $rr]);

            $approved = $ra['status'] === self::APPROVED;
            $rejected = $rr['status'] === 'The request was rejected.';
            $this->assertNotSame($approved, $rejected, "{$label}: exactly one decision");
            $this->assertSame('This request has already been decided.', $approved ? $rr['error'] : $ra['error'], $label);

            $fresh = $recovery->fresh();
            $this->assertSame($approved ? AccountRecoveryRequest::APPROVED : AccountRecoveryRequest::REJECTED, $fresh->status, $label);
            $this->assertNull($fresh->open_key);
            $this->assertSame(1, AuditLog::whereIn('action', ['account.recovery_approved', 'account.recovery_rejected'])->where('model_id', $recovery->id)->count(), "{$label}: one decision on record");

            // The state matches the one decision: restored and replaced, or both left as they were.
            $this->assertSame(! $approved, $old->fresh()->isDeleted(), $label);
            $this->assertSame($approved, $requester->fresh()->isDeleted(), $label);
            $this->assertSame($approved ? 1 : 0, DB::table(config('auth.passwords.users.table'))->where('email', $deletion->email)->count(), "{$label}: a link only if approved");
            // Either way nothing is left to choose: rejected, or archived as the replaced account.
            $this->assertSame(User::CHOICE_NEW, $requester->fresh()->previous_account_choice, $label);

            if ($hold) {
                $this->assertTrue($approved, "{$label}: the holder wins");
                $this->assertGreaterThan(1.2, $rr['ended'] - $rr['began'], "{$label}: the rejection did not wait");
            } elseif ($this->overlapped($ra, $rr)) {
                $overlapping++;
            }

            $tally[$approved ? 'approved' : 'rejected']++;
        }

        fwrite(STDERR, sprintf("\n[approve vs reject] 15 rounds (5 hold, 10 same moment, %d overlapping); approval won %d, rejection won %d\n", $overlapping, $tally['approved'], $tally['rejected']));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_an_approval_and_a_registration_of_the_same_email_leave_one_holder(): void
    {
        $tally = ['approved' => 0, 'registered' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 20; $round++) {
            [$old, $deletion, $requester] = $this->archivedWithRequester();
            $recovery = $this->openRequest($requester, $deletion);
            $strangerMobile = '93100'.str_pad((string) $round, 5, '0', STR_PAD_LEFT);
            $auditBefore = AuditLog::count();
            $startAt = microtime(true) + 3;

            // Rounds 1-5: approval holds its locks first. 6-10: the registration does. 11-20: same moment.
            $mode = $round <= 5 ? 'approval first' : ($round <= 10 ? 'registration first' : 'same moment');
            $approval = $this->start("ap-{$round}", 'approve', $mode === 'approval first', $this->x->id, $recovery->id, [], $startAt,
                $mode === 'registration first' ? "{$this->dir}/rg-{$round}.json.locked" : '-');
            $registration = $this->start("rg-{$round}", 'register', $mode === 'registration first', 0, 0, ['email' => $deletion->email, 'mobile' => $strangerMobile], $startAt,
                $mode === 'approval first' ? "{$this->dir}/ap-{$round}.json.locked" : '-');

            $ra = $this->finish($approval);
            $rr = $this->finish($registration);
            $label = "round {$round} ({$mode}): ".json_encode([$ra, $rr]);

            $approved = $ra['status'] === self::APPROVED;
            $registered = $rr['code'] === 200;
            $this->assertNotSame($approved, $registered, "{$label}: exactly one side wins");

            if ($approved) {
                $this->assertSame(422, $rr['code'], $label);
                $this->assertContains($rr['message'], [self::TAKEN, 'The email has already been taken.'], $label);
                $this->assertSame(0, User::where('mobile', $strangerMobile)->count(), "{$label}: no stranger account");
                $this->assertFalse($old->fresh()->isDeleted());
                $tally['approved']++;
            } else {
                $this->assertSame(self::CONFLICT, $ra['error'], $label);
                $this->assertTrue($old->fresh()->isDeleted(), "{$label}: nothing restored");
                $this->assertTrue($recovery->fresh()->isOpen(), "{$label}: the request waits for a decision");
                $tally['registered']++;
            }

            $this->assertOneHolder($deletion->email, $label);
            $this->assertGreaterThanOrEqual($auditBefore, AuditLog::count(), "{$label}: no audit entry lost");

            if ($mode === 'approval first') {
                $this->assertTrue($approved, $label);
                $this->assertGreaterThan(1.2, $rr['ended'] - $rr['began'], "{$label}: the registration did not wait");
            } elseif ($mode === 'registration first') {
                $this->assertTrue($registered, $label);
                $this->assertGreaterThan(1.2, $ra['ended'] - $ra['began'], "{$label}: the approval did not wait");
            } elseif ($this->overlapped($ra, $rr)) {
                $overlapping++;
            }
        }

        fwrite(STDERR, sprintf("\n[approve vs register] 20 rounds (5 approval first, 5 registration first, 10 same moment, %d overlapping); approval won %d, registration won %d\n", $overlapping, $tally['approved'], $tally['registered']));
        $this->assertGreaterThan(0, $overlapping);
    }

    public function test_two_submissions_for_one_archived_account_make_one_request(): void
    {
        $wins = ['by email' => 0, 'by number' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            [$old, $deletion, $byNumber] = $this->archivedWithRequester();
            // A second person, found by the released email itself.
            $byEmail = $this->person('public', ['email' => $deletion->email]);
            DB::table('users')->where('id', $byEmail->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);
            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $a = $this->start("se-{$round}", 'submit', $hold, $byEmail->id, $deletion->id, [], $startAt);
            $b = $this->start("sn-{$round}", 'submit', false, $byNumber->id, $deletion->id, [], $startAt, $hold ? "{$this->dir}/se-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rb = $this->finish($b);
            $label = "round {$round}: ".json_encode([$ra, $rb]);

            $this->assertNotSame($ra['request'] !== null, $rb['request'] !== null, "{$label}: exactly one request");
            $this->assertSame(1, AccountRecoveryRequest::where('account_deletion_id', $deletion->id)->count(), $label);
            $this->assertSame(1, AccountRecoveryRequest::where('account_deletion_id', $deletion->id)->whereNotNull('open_key')->count(), $label);

            $winner = $ra['request'] !== null ? $byEmail : $byNumber;
            $loser = $winner->is($byEmail) ? $byNumber : $byEmail;
            $this->assertSame(User::CHOICE_RECOVERY, $winner->fresh()->previous_account_choice, $label);
            $this->assertSame(User::CHOICE_PENDING, $loser->fresh()->previous_account_choice, "{$label}: the other can still choose");

            if ($hold) {
                $this->assertNotNull($ra['request'], "{$label}: the holder wins");
                $this->assertGreaterThan(1.2, $rb['ended'] - $rb['began'], "{$label}: the second did not wait");
            } elseif ($this->overlapped($ra, $rb)) {
                $overlapping++;
            }

            $wins[$winner->is($byEmail) ? 'by email' : 'by number']++;
        }

        fwrite(STDERR, sprintf("\n[submit vs submit] 15 rounds (5 hold, 10 same moment, %d overlapping); found by email won %d, found by number won %d\n", $overlapping, $wins['by email'], $wins['by number']));
        $this->assertGreaterThan(0, $overlapping);
    }
}
