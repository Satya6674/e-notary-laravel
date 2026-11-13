<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\NotaryAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 2: two administrators assigning the same application at the same moment, in two
 * real PHP processes against MariaDB. SQLite has no row locks, so this runs only through
 * scripts/Test-MariaDB.ps1, on its throwaway enotary_phpunit database.
 *
 * The workers need committed rows, so this class does not use RefreshDatabase. It rebuilds
 * the throwaway schema before and after each test instead. Every record is synthetic.
 */
class AssignmentConcurrencyTest extends TestCase
{
    private const STALE = 'This application was assigned by someone else while you were viewing it. Please check it again.';

    private User $admin;

    private User $citizen;

    private User $first;

    private User $second;

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

        $make = fn (string $role, int $n) => User::create([
            'name' => "Race {$role} {$n}", 'email' => "race-{$role}-{$n}@example.test", 'mobile' => '94200000'.$n.$n,
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        $this->admin = $make('admin', 1);
        $this->citizen = $make('public', 2);
        $this->first = $make('notary', 3);
        $this->second = $make('notary', 4);

        $this->dir = storage_path('framework/testing/assignment-race-'.getmypid());
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

    private function pendingApplication(): NotaryRequest
    {
        return NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $this->citizen->id,
            'document_type' => 'affidavit', 'purpose' => 'Synthetic race', 'status' => 'pending',
        ]);
    }

    /** Starts one worker process. Its environment forces fakes, and the worker checks them again. */
    private function start(string $name, string $mode, NotaryRequest $application, User $notary, float $startAt, int $holdMs = 0, string $waitFor = '-')
    {
        $out = "{$this->dir}/{$name}.json";
        @unlink($out);
        @unlink($out.'.locked');

        // Windows drops a variable set to an empty string, and .env would then fill the gap. So
        // the SMS key is switched off with a real value (MSG91_ENABLED=false), never just emptied.
        $env = array_merge(getenv(), [
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'MSG91_ENABLED' => 'false', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null',
        ]);

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/assignment-race-worker.php'), $mode, (string) $this->admin->id, (string) $application->id,
                (string) $notary->id, 'none', sprintf('%.6F', $startAt), (string) $holdMs, $waitFor, $out],
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

    private function assertOneAssignment(NotaryRequest $application, int $notaryId): void
    {
        $fresh = $application->fresh();

        $this->assertSame('assigned', $fresh->status);
        $this->assertSame($notaryId, (int) $fresh->notary_id);
        $this->assertSame(1, AuditLog::where('action', 'request.assigned')->where('model_id', $application->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'request.reassigned')->where('model_id', $application->id)->count());
    }

    public function test_the_second_administrator_waits_for_the_lock_and_is_then_refused(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            $application = $this->pendingApplication();
            $startAt = microtime(true) + 3;

            $holder = $this->start("hold-{$round}", 'hold', $application, $this->first, $startAt, 1500);
            $waiter = $this->start("wait-{$round}", 'plain', $application, $this->second, $startAt, 0, "{$this->dir}/hold-{$round}.json.locked");

            $held = $this->finish($holder);
            $waited = $this->finish($waiter);

            $this->assertSame(NotaryAssignmentService::ASSIGNED, $held['outcome'], "round {$round}");
            $this->assertSame(NotaryAssignmentService::REFUSED, $waited['outcome'], "round {$round}");
            $this->assertSame(self::STALE, $waited['message']);
            // It really waited on the row lock: it could not finish before the holder's 1.5 s hold
            // was over. (The holder stamps its own end only after commit returns, so that stamp
            // can come a moment after the waiter is released; the hold start is the fair mark.)
            $this->assertGreaterThan(1.2, $waited['ended'] - $waited['began'], "round {$round}: the second worker did not block");
            $this->assertGreaterThanOrEqual($held['began'] + 1.5 - 0.05, $waited['ended'], "round {$round}: finished before the lock was released");

            $this->assertOneAssignment($application, $this->first->id);
        }
    }

    public function test_two_administrators_at_the_same_moment_produce_exactly_one_assignment(): void
    {
        $overlapping = 0;
        $wins = [$this->first->id => 0, $this->second->id => 0];

        for ($round = 1; $round <= 15; $round++) {
            $application = $this->pendingApplication();
            $startAt = microtime(true) + 3;

            $a = $this->start("a-{$round}", 'plain', $application, $this->first, $startAt);
            $b = $this->start("b-{$round}", 'plain', $application, $this->second, $startAt);

            $results = [$this->finish($a), $this->finish($b)];
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);

            $this->assertSame([NotaryAssignmentService::ASSIGNED, NotaryAssignmentService::REFUSED], $outcomes, "round {$round}");

            $winner = collect($results)->firstWhere('outcome', NotaryAssignmentService::ASSIGNED);
            $this->assertSame(self::STALE, collect($results)->firstWhere('outcome', NotaryAssignmentService::REFUSED)['message']);
            $this->assertOneAssignment($application, $winner['notary']);
            $wins[$winner['notary']]++;

            [$x, $y] = $results;
            if ($x['began'] < $y['ended'] && $y['began'] < $x['ended']) {
                $overlapping++;
            }
        }

        fwrite(STDERR, sprintf("\n[assignment race] 15 rounds, %d with overlapping workers, wins %d / %d\n", $overlapping, ...array_values($wins)));

        // The workers demonstrably ran at the same time, not one after the other.
        $this->assertGreaterThan(0, $overlapping, 'no round had the two workers running at the same time');
    }
}
