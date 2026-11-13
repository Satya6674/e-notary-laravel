<?php

namespace Tests\Feature;

use App\Services\OtpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 6: OTP requests arriving at the same moment, in real PHP processes against MariaDB.
 * SQLite has no row locks, so this runs only through scripts/Test-MariaDB.ps1 on its throwaway
 * enotary_phpunit database. The workers share locks and counters through the database cache
 * store in that same throwaway database. No SMS is sent: each worker uses a stand-in provider.
 *
 * The workers need committed rows, so this class does not use RefreshDatabase. It rebuilds the
 * throwaway schema before and after each test instead. Every number here is synthetic.
 */
class OtpConcurrencyTest extends TestCase
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

        $this->dir = storage_path('framework/testing/otp-race-'.getmypid());
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

    /**
     * Starts the workers together and returns their results. Windows drops a variable set to an
     * empty string, so the SMS key is switched off with MSG91_ENABLED=false, never just emptied.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $requests  [mobile, flow, ip]
     */
    private function race(string $round, array $requests, array $extraEnv = []): array
    {
        $env = array_merge(getenv(), [
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'array', 'MSG91_ENABLED' => 'false', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null',
        ], $extraEnv);

        $startAt = microtime(true) + 3;
        $workers = [];

        foreach ($requests as $i => [$mobile, $flow, $ip]) {
            $out = "{$this->dir}/{$round}-{$i}.json";
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/otp-race-worker.php'), $mobile, $flow, $ip, sprintf('%.6F', $startAt), $out],
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

    private function overlapped(array $results): bool
    {
        $latestStart = max(array_column($results, 'began'));
        $earliestEnd = min(array_column($results, 'ended'));

        return $latestStart < $earliestEnd;
    }

    public function test_simultaneous_requests_for_one_number_and_flow_issue_exactly_one_code(): void
    {
        $overlapping = 0;

        for ($round = 1; $round <= 5; $round++) {
            $mobile = '946770000'.$round;
            $results = $this->race("same-{$round}", array_fill(0, 6, [$mobile, OtpService::FLOW_PASSWORD_RESET, '198.51.100.'.$round]));

            $outcomes = array_count_values(array_column($results, 'outcome'));
            $this->assertSame([OtpService::ISSUED => 1, OtpService::COOLDOWN => 5], [OtpService::ISSUED => $outcomes[OtpService::ISSUED] ?? 0, OtpService::COOLDOWN => $outcomes[OtpService::COOLDOWN] ?? 0], "round {$round}");
            $this->assertSame(1, DB::table('otp_verifications')->where('mobile', $mobile)->count(), "round {$round}");
            $this->assertSame(1, (int) Cache::store('database')->get('otp-day:'.now()->format('Ymd').":num:{$mobile}"), "round {$round}");

            $overlapping += $this->overlapped($results) ? 1 : 0;
        }

        fwrite(STDERR, "\n[otp race] same number and flow: 5 rounds of 6 workers, {$overlapping} with all workers overlapping\n");
        $this->assertGreaterThan(0, $overlapping, 'the workers never ran at the same time');
    }

    public function test_simultaneous_requests_for_different_flows_each_get_their_own_code(): void
    {
        $mobile = '9467710001';
        $flows = [OtpService::FLOW_MOBILE_VERIFICATION, OtpService::FLOW_PASSWORD_RESET, OtpService::FLOW_MOBILE_CHANGE, OtpService::PURPOSE_RECOVERY];

        $results = $this->race('flows', array_map(fn ($flow) => [$mobile, $flow, '198.51.100.20'], $flows));

        $this->assertSame(array_fill(0, 4, OtpService::ISSUED), array_column($results, 'outcome'));

        // Four live codes, one per flow, none expired by another.
        $rows = DB::table('otp_verifications')->where('mobile', $mobile)->get();
        $this->assertCount(4, $rows);
        $this->assertEqualsCanonicalizing($flows, $rows->map(fn ($row) => explode('|', $row->otp, 2)[0])->all());
        $this->assertSame(4, $rows->where('expires_at', '>', now()->toDateTimeString())->count());
    }

    public function test_the_site_wide_daily_ceiling_holds_under_simultaneous_requests(): void
    {
        // Six different numbers at once against a ceiling of three: exactly three may pass.
        $requests = array_map(fn ($n) => ['94677200'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), OtpService::FLOW_PASSWORD_RESET, '198.51.100.'.(30 + $n)], range(1, 6));

        $results = $this->race('site', $requests, ['MSG91_DAILY_LIMIT' => '3']);

        $outcomes = array_count_values(array_column($results, 'outcome'));
        $this->assertSame(3, $outcomes[OtpService::ISSUED] ?? 0);
        $this->assertSame(3, $outcomes[OtpService::DAILY_LIMIT] ?? 0);
        $this->assertSame(3, DB::table('otp_verifications')->count());
        $this->assertSame(3, (int) Cache::store('database')->get('otp-day:'.now()->format('Ymd').':site'));
        $this->assertTrue($this->overlapped($results), 'the workers did not run at the same time');
    }
}
