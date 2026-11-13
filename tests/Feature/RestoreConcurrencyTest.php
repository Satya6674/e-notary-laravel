<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 3: administrators restoring the same deleted account at the same moment, in real
 * PHP processes against MariaDB. SQLite has no row locks, so this runs only through
 * scripts/Test-MariaDB.ps1 on its throwaway enotary_phpunit database.
 *
 * The workers need committed rows, so this class does not use RefreshDatabase. It rebuilds the
 * throwaway schema before and after each test instead. Every record is synthetic.
 */
class RestoreConcurrencyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB: run scripts\Test-MariaDB.ps1.');
        }

        // TestCase has already refused anything but enotary_phpunit on this machine.
        $this->assertSame('enotary_phpunit', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $this->dir = storage_path('framework/testing/restore-race-'.getmypid());
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

    /** Starts the workers together. Windows drops an empty variable, so SMS is switched off by value. */
    private function race(string $round, array $adminIds, int $userId): array
    {
        $env = array_merge(getenv(), [
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'MSG91_ENABLED' => 'false', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null',
        ]);

        $startAt = microtime(true) + 3;
        $workers = [];

        foreach ($adminIds as $i => $adminId) {
            $out = "{$this->dir}/{$round}-{$i}.json";
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/restore-race-worker.php'), (string) $adminId, (string) $userId, sprintf('%.6F', $startAt), $out],
                [0 => ['pipe', 'r'], 1 => ['file', "{$out}.stdout", 'w'], 2 => ['file', "{$out}.stderr", 'w']],
                $pipes, base_path(), $env,
            );
            fclose($pipes[0]);
            $workers[] = [$process, $out];
        }

        $results = [];
        foreach ($workers as [$process, $out]) {
            $code = proc_close($process);
            $this->assertSame(0, $code, 'worker failed: '.@file_get_contents("{$out}.stderr").@file_get_contents($out));
            $results[] = json_decode((string) file_get_contents($out), true);
        }

        return $results;
    }

    public function test_simultaneous_restores_restore_once_and_send_one_reset_link(): void
    {
        $make = fn (string $role, string $n) => User::create([
            'name' => "Race {$role} {$n}", 'email' => "race-{$role}-{$n}@example.test", 'mobile' => '94999800'.$n,
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);

        $first = $make('admin', '01');
        $second = $make('admin', '02');
        $citizen = $make('public', '11');
        $citizen->forceFill(['mobile_verified_at' => now()])->save();
        $overlapping = 0;

        for ($round = 1; $round <= 5; $round++) {
            app(AccountDeletionService::class)->delete($first, $citizen->fresh(), 'Synthetic deletion for a race round.');

            $results = $this->race("round-{$round}", [$first->id, $second->id, $first->id, $second->id], $citizen->id);

            $outcomes = array_count_values(array_column($results, 'outcome'));
            $this->assertSame(1, $outcomes['restored'] ?? 0, "round {$round}: ".json_encode($results));
            $this->assertSame(3, $outcomes['refused'] ?? 0, "round {$round}");
            foreach ($results as $result) {
                if ($result['outcome'] === 'refused') {
                    $this->assertSame('This account is not deleted.', $result['message']);
                }
            }

            $restored = $citizen->fresh();
            $this->assertFalse($restored->isDeleted(), "round {$round}");
            $this->assertTrue($restored->mustResetPassword());
            $this->assertNull($restored->mobile_verified_at);
            $this->assertSame($round, AccountDeletion::where('user_id', $citizen->id)->whereNotNull('restored_at')->count(), "round {$round}: one restore per deletion");
            $this->assertSame($round, AuditLog::where('action', 'admin.user_restored')->where('model_id', $citizen->id)->count());
            $this->assertSame(1, DB::table('password_resets')->where('email', $citizen->email)->count(), 'one live reset token');

            $latestStart = max(array_column($results, 'began'));
            $earliestEnd = min(array_column($results, 'ended'));
            $overlapping += $latestStart < $earliestEnd ? 1 : 0;
        }

        fwrite(STDERR, "\n[restore race] 5 rounds of 4 workers, {$overlapping} with all workers overlapping\n");
        $this->assertGreaterThan(0, $overlapping, 'the workers never ran at the same time');
    }
}
