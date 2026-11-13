<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deactivation racing other work, in real PHP processes against MariaDB, through the real HTTP
 * routes. SQLite has no row locks, so this runs only through scripts/Test-MariaDB.ps1, on its
 * throwaway enotary_phpunit database.
 *
 * - A Citizen booking an appointment while an administrator deactivates the assigned Notary must
 *   never leave an open appointment with a deactivated Notary, and must not move, close or
 *   delete the application or any appointment.
 * - Two administrators deactivating each other at once must leave one active administrator.
 *
 * "hold" rounds fix the order (one side holds its locks 1.5 s while the other is sent), so they
 * are deterministic. "same moment" rounds start both sides together and report who won.
 *
 * The workers need committed rows, so this class does not use RefreshDatabase. It rebuilds the
 * throwaway schema before and after each test instead. Every record is synthetic.
 */
class DeactivationConcurrencyTest extends TestCase
{
    private const UNAVAILABLE = 'The notary assigned to this application is not available at the moment, so it cannot be booked now. An administrator will assign another notary.';

    private const NOT_ACTIVE_ADMIN = 'Only an active administrator can deactivate accounts. Nothing was changed.';

    private User $admin;

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

        $this->admin = $this->make('admin');

        $this->dir = storage_path('framework/testing/deactivation-race-'.getmypid());
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
            'name' => "Race {$role} {$n}", 'email' => "deact-{$role}-{$n}@example.test", 'mobile' => '94300'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    /** A Citizen, their application assigned to a fresh Notary, and that Notary. */
    private function scenario(): array
    {
        $citizen = $this->make('public');
        $notary = $this->make('notary');

        $application = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $citizen->id,
            'document_type' => 'affidavit', 'purpose' => 'Synthetic race', 'status' => 'pending',
        ]);
        $application->forceFill(['status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $notary->id])->save();

        return [$citizen, $notary, $application->fresh()];
    }

    private function start(string $name, string $action, bool $hold, User $actor, ?User $target, ?NotaryRequest $application, float $startAt, string $waitFor = '-')
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
                (string) ($target?->id ?? 0), (string) ($application?->id ?? 0), '1', '0', sprintf('%.6F', $startAt), '1500', $waitFor, $out],
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

    private function booked(array $result): bool
    {
        return $result['status'] === 'Appointment requested.';
    }

    private function deactivated(array $result): bool
    {
        return str_starts_with((string) $result['status'], 'Account deactivated');
    }

    /** The invariants that must hold after every round, whoever won. */
    private function assertNothingStranded(NotaryRequest $application, User $notary, string $round): void
    {
        $stranded = Appointment::whereIn('status', ['requested', 'confirmed'])
            ->whereHas('notary', fn ($q) => $q->whereNotNull('deactivated_at'))->count();
        $this->assertSame(0, $stranded, "{$round}: an open appointment is assigned to a deactivated notary");

        // The application is not moved, closed or deleted by either side.
        $fresh = NotaryRequest::find($application->id);
        $this->assertNotNull($fresh, $round);
        $this->assertSame(NotaryRequest::STATUS_ASSIGNED, $fresh->status, $round);
        $this->assertSame($notary->id, (int) $fresh->notary_id, $round);

        // At most one booking, never moved to another notary, never closed.
        $appointments = Appointment::where('request_id', $application->id)->get();
        $this->assertLessThanOrEqual(1, $appointments->count(), $round);
        foreach ($appointments as $appointment) {
            $this->assertSame('requested', $appointment->status, $round);
            $this->assertSame($notary->id, (int) $appointment->notary_id, $round);
        }
    }

    public function test_a_booking_sent_while_a_deactivation_holds_the_lock_waits_and_is_refused(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            [$citizen, $notary, $application] = $this->scenario();
            $startAt = microtime(true) + 3;

            $holder = $this->start("deact-{$round}", 'deactivate', true, $this->admin, $notary, null, $startAt);
            $waiter = $this->start("book-{$round}", 'book', false, $citizen, null, $application, $startAt, "{$this->dir}/deact-{$round}.json.locked");

            $held = $this->finish($holder);
            $waited = $this->finish($waiter);

            $this->assertTrue($this->deactivated($held), "round {$round}: ".json_encode($held));
            $this->assertNotNull($notary->fresh()->deactivated_at);
            $this->assertSame(self::UNAVAILABLE, $waited['error'], "round {$round}: ".json_encode($waited));
            $this->assertGreaterThan(1.2, $waited['ended'] - $waited['began'], "round {$round}: the booking did not wait for the lock");
            $this->assertSame(0, Appointment::where('request_id', $application->id)->count());

            $this->assertNothingStranded($application, $notary, "round {$round}");
        }
    }

    public function test_a_deactivation_sent_while_a_booking_holds_the_lock_waits_and_sees_the_new_appointment(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            [$citizen, $notary, $application] = $this->scenario();
            $startAt = microtime(true) + 3;

            // The administrator's page showed 1 open application and 0 appointments.
            $holder = $this->start("book-{$round}", 'book', true, $citizen, null, $application, $startAt);
            $waiter = $this->start("deact-{$round}", 'deactivate', false, $this->admin, $notary, null, $startAt, "{$this->dir}/book-{$round}.json.locked");

            $held = $this->finish($holder);
            $waited = $this->finish($waiter);

            $this->assertTrue($this->booked($held), "round {$round}: ".json_encode($held));
            $this->assertStringContainsString('which you have not confirmed. Nothing was changed.', (string) $waited['error'], "round {$round}: ".json_encode($waited));
            $this->assertGreaterThan(1.2, $waited['ended'] - $waited['began'], "round {$round}: the deactivation did not wait for the lock");
            $this->assertNull($notary->fresh()->deactivated_at);
            $this->assertSame(1, Appointment::where('request_id', $application->id)->where('notary_id', $notary->id)->count());

            $this->assertNothingStranded($application, $notary, "round {$round}");
        }
    }

    public function test_booking_and_deactivation_at_the_same_moment_never_strand_an_appointment(): void
    {
        $tally = ['booked' => 0, 'deactivated' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            [$citizen, $notary, $application] = $this->scenario();
            $startAt = microtime(true) + 3;

            $book = $this->start("book-{$round}", 'book', false, $citizen, null, $application, $startAt);
            $deact = $this->start("deact-{$round}", 'deactivate', false, $this->admin, $notary, null, $startAt);

            $b = $this->finish($book);
            $d = $this->finish($deact);

            // Exactly one side wins; the other is refused with its message, and nothing else changes.
            $this->assertNotSame($this->booked($b), $this->deactivated($d), "round {$round}: ".json_encode([$b, $d]));

            if ($this->booked($b)) {
                $this->assertStringContainsString('which you have not confirmed', (string) $d['error'], "round {$round}");
                $tally['booked']++;
            } else {
                $this->assertSame(self::UNAVAILABLE, $b['error'], "round {$round}");
                $tally['deactivated']++;
            }

            if ($b['began'] < $d['ended'] && $d['began'] < $b['ended']) {
                $overlapping++;
            }

            $this->assertNothingStranded($application, $notary, "round {$round}");
        }

        fwrite(STDERR, sprintf("\n[booking vs deactivation] 15 rounds, %d overlapping; booking won %d, deactivation won %d\n", $overlapping, $tally['booked'], $tally['deactivated']));

        $this->assertGreaterThan(0, $overlapping, 'no round had the two workers running at the same time');
    }

    public function test_two_administrators_deactivating_each_other_leave_one_active_administrator(): void
    {
        $tally = ['first' => 0, 'second' => 0];
        $overlapping = 0;

        for ($round = 1; $round <= 15; $round++) {
            $x = $this->make('admin');
            $y = $this->make('admin');
            // These two are the only active administrators in this round.
            User::where('role', 'admin')->whereNotIn('id', [$x->id, $y->id])->update(['deactivated_at' => now()]);

            $hold = $round <= 5;
            $startAt = microtime(true) + 3;

            $first = $this->start("x-{$round}", 'deactivate', $hold, $x, $y, null, $startAt);
            $second = $this->start("y-{$round}", 'deactivate', false, $y, $x, null, $startAt, $hold ? "{$this->dir}/x-{$round}.json.locked" : '-');

            $rx = $this->finish($first);
            $ry = $this->finish($second);

            $active = User::where('role', 'admin')->whereNull('deactivated_at')->count();
            $this->assertSame(1, $active, "round {$round}: ".json_encode([$rx, $ry]));
            $this->assertNotSame($this->deactivated($rx), $this->deactivated($ry), "round {$round}");

            $loser = $this->deactivated($rx) ? $ry : $rx;
            $this->assertSame(self::NOT_ACTIVE_ADMIN, $loser['error'], "round {$round}");

            if ($hold) {
                $this->assertTrue($this->deactivated($rx), "round {$round}: the holder should win");
                $this->assertGreaterThan(1.2, $ry['ended'] - $ry['began'], "round {$round}: the second did not wait for the lock");
            } elseif ($rx['began'] < $ry['ended'] && $ry['began'] < $rx['ended']) {
                $overlapping++;
            }

            $tally[$this->deactivated($rx) ? 'first' : 'second']++;
        }

        fwrite(STDERR, sprintf("\n[admin vs admin deactivation] 15 rounds (5 hold, 10 same moment, %d overlapping); first won %d, second won %d\n", $overlapping, $tally['first'], $tally['second']));

        $this->assertGreaterThan(0, $overlapping, 'no same-moment round had the two workers running at the same time');
    }
}
