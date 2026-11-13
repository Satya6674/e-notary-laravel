<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Restore-email resend under real concurrency, in separate PHP processes against MariaDB,
 * through the real route. SQLite has no row locks, so this runs only through
 * scripts/Test-MariaDB.ps1, on its throwaway enotary_phpunit database.
 *
 * - Two administrators resending at once make exactly one new link: one working token, one
 *   "resent" audit entry, and the other attempt refused and audited.
 * - An administrator deactivated while their resend is on its way is refused once the
 *   deactivation commits, and nothing changes.
 *
 * The workers need committed rows, so this class does not use RefreshDatabase. Mail is the array
 * mailer and the queue is sync, in every process. Every record is synthetic.
 */
class ResendConcurrencyTest extends TestCase
{
    private const NOT_ACTIVE = 'Only an active administrator can send a new password link.';

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

        $this->x = $this->make('admin');
        $this->y = $this->make('admin');
        $this->make('admin');

        $this->dir = storage_path('framework/testing/resend-race-'.getmypid());
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

    private function make(string $role): User
    {
        $n = ++$this->n;

        $user = User::create([
            'name' => "Resend {$role} {$n}", 'email' => "resend-{$role}-{$n}@example.test", 'mobile' => '94600'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    /** A restored Citizen whose password link failed more than a minute ago, so it may be replaced. */
    private function scenario(): array
    {
        $citizen = $this->make('public');
        $service = app(AccountDeletionService::class);
        $service->delete($this->x, $citizen, 'Synthetic deletion for testing.');
        $deletion = $service->restore($this->x, $citizen->fresh(), 'Synthetic restore for testing.');

        $id = AuditLog::where('action', 'admin.user_restored')->where('model_id', $citizen->id)->latest('id')->first()->meta['reset_notification_id'];
        MessageDelivery::where('notification_id', $id)->update(['status' => MessageDelivery::FAILED]);
        DB::table(config('auth.passwords.users.table'))->where('email', $citizen->email)->update(['created_at' => now()->subMinutes(2)]);

        $this->assertTrue($service->resetLinkState($deletion->fresh())['resendable']);

        return [$citizen, $deletion->fresh()];
    }

    private function start(string $name, string $action, bool $hold, User $actor, int $target, float $startAt, string $waitFor = '-')
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
            [PHP_BINARY, base_path('tests/Support/deactivation-race-worker.php'), $action, $hold ? '1' : '0', (string) $actor->id,
                (string) $target, '0', '0', '0', sprintf('%.6F', $startAt), '1500', $waitFor, $out],
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
        $result = json_decode((string) file_get_contents($out), true);
        $this->assertSame(302, $result['code'] ?? null, 'unexpected response: '.json_encode($result));

        return $result;
    }

    private function resent(array $result): bool
    {
        return str_starts_with((string) $result['status'], 'A new password link was queued');
    }

    private function entries(string $action, AccountDeletion $deletion): int
    {
        return AuditLog::where('action', $action)->where('meta->reference', $deletion->reference)->count();
    }

    public function test_two_administrators_resending_at_once_make_exactly_one_new_link(): void
    {
        $refusals = [
            'The current link was accepted by the mail service and still works. It can be replaced once it expires.',
            'The current link is waiting in the queue and still works. It can be replaced once it fails or expires.',
            'A link was created less than a minute ago. Please wait a minute and try again.',
        ];
        $overlapping = 0;
        $wins = ['first' => 0, 'second' => 0];

        $times = [];

        for ($round = 1; $round <= 15; $round++) {
            $t0 = microtime(true);
            [$citizen, $deletion] = $this->scenario();
            $times[] = sprintf('setup %.1fs', microtime(true) - $t0);
            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $a = $this->start("a-{$round}", 'resend', $hold, $this->x, $deletion->id, $startAt);
            $b = $this->start("b-{$round}", 'resend', false, $this->y, $deletion->id, $startAt, $hold ? "{$this->dir}/a-{$round}.json.locked" : '-');

            $ra = $this->finish($a);
            $rb = $this->finish($b);

            $this->assertNotSame($this->resent($ra), $this->resent($rb), "round {$round}: exactly one resend succeeds ".json_encode([$ra, $rb]));
            $loser = $this->resent($ra) ? $rb : $ra;
            $this->assertContains($loser['error'], $refusals, "round {$round}");

            // One new link, one working token, and both attempts on record.
            $this->assertSame(1, $this->entries(AccountDeletionService::RESET_LINK_RESENT, $deletion), "round {$round}");
            $this->assertSame(1, $this->entries(AccountDeletionService::RESET_LINK_REFUSED, $deletion), "round {$round}");
            $tokens = DB::table(config('auth.passwords.users.table'))->where('email', $citizen->email)->get();
            $this->assertCount(1, $tokens, "round {$round}: one token per account");
            $this->assertTrue(now()->subSeconds(30)->lt($tokens[0]->created_at), "round {$round}: the token is the new one");
            $this->assertTrue($citizen->fresh()->mustResetPassword(), "round {$round}: still locked");

            if ($hold) {
                $this->assertTrue($this->resent($ra), "round {$round}: the holder wins");
                $this->assertGreaterThan(1.2, $rb['ended'] - $rb['began'], "round {$round}: the second did not wait for the lock");
            } elseif ($ra['began'] < $rb['ended'] && $rb['began'] < $ra['ended']) {
                $overlapping++;
            }

            $wins[$this->resent($ra) ? 'first' : 'second']++;
            $times[array_key_last($times)] .= sprintf(' + race %.1fs', microtime(true) - $t0);
        }

        fwrite(STDERR, "\n[resend vs resend timings] ".implode('; ', $times)."\n");

        fwrite(STDERR, sprintf("\n[resend vs resend] 15 rounds (5 hold, 10 same moment, %d overlapping); first won %d, second won %d\n", $overlapping, $wins['first'], $wins['second']));

        $this->assertGreaterThan(0, $overlapping, 'no same-moment round had the two workers running at the same time');
    }

    public static function deactivations(): array
    {
        return [
            // The application's own deactivation (Admin > Users > Deactivate).
            'through the application' => ['deactivate', 'Account deactivated.'],
            // An UPDATE by primary key that locks only that administrator's row, as a direct SQL
            // change would. The resend must not rely on the deactivation happening to lock more.
            'locking only the administrator row' => ['deactivate-row', 'row deactivated'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deactivations')]
    public function test_a_resend_by_an_administrator_deactivated_meanwhile_is_refused(string $how, string $done): void
    {
        $times = [];

        for ($round = 1; $round <= 5; $round++) {
            $t0 = microtime(true);
            [$citizen, $deletion] = $this->scenario();
            $victim = $this->make('admin');
            $issuedBefore = DB::table(config('auth.passwords.users.table'))->where('email', $citizen->email)->value('created_at');
            $startAt = microtime(true) + 3;

            // X deactivates the administrator and holds the locks; that administrator's resend,
            // already past the middleware, arrives meanwhile.
            $deactivation = $this->start("deact-{$round}", $how, true, $this->x, $victim->id, $startAt);
            $resend = $this->start("resend-{$round}", 'resend', false, $victim, $deletion->id, $startAt, "{$this->dir}/deact-{$round}.json.locked");

            $rd = $this->finish($deactivation);
            $rr = $this->finish($resend);
            $times[] = sprintf('%.1fs (resend waited %.2fs)', microtime(true) - $t0, $rr['ended'] - $rr['began']);

            $this->assertSame($done, $rd['status'], "round {$round}: ".json_encode($rd));
            $this->assertNotNull($victim->fresh()->deactivated_at);
            $this->assertSame(self::NOT_ACTIVE, $rr['error'], "round {$round}: ".json_encode($rr));
            $this->assertGreaterThan(1.2, $rr['ended'] - $rr['began'], "round {$round}: the resend did not wait for the deactivation");

            // Nothing changed: no new link, the old token untouched, the refusal on record.
            $this->assertSame(0, $this->entries(AccountDeletionService::RESET_LINK_RESENT, $deletion), "round {$round}");
            $this->assertSame(1, AuditLog::where('action', AccountDeletionService::RESET_LINK_REFUSED)->where('user_id', $victim->id)
                ->where('meta->reason', self::NOT_ACTIVE)->count(), "round {$round}");
            $this->assertEquals($issuedBefore, DB::table(config('auth.passwords.users.table'))->where('email', $citizen->email)->value('created_at'), "round {$round}");
            $this->assertTrue($citizen->fresh()->mustResetPassword());
        }

        fwrite(STDERR, "\n[resend vs deactivation {$how}] 5 hold rounds, all refused: ".implode(', ', $times)."\n");
    }
}
