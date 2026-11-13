<?php

namespace App\Console\Commands;

use App\Support\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operational health, measured now. Read-only: it writes nothing and sends nothing.
 *
 *   php artisan enotary:health            human-readable
 *   php artisan enotary:health --json     for a monitor; exit code 1 when anything is FAIL
 *
 * Every value is read from the real system at the moment of the check. A value that cannot be read
 * is reported as UNKNOWN, never guessed.
 */
class HealthCheck extends Command
{
    protected $signature = 'enotary:health {--json : Print JSON} {--backups= : Backup folder to inspect (default %USERPROFILE%\\E-Notary-backups)}';

    protected $description = 'Check database, queue, scheduler, mail, disk, logs and backups (read-only)';

    public function handle(): int
    {
        $checks = [];
        $add = function (string $name, string $status, string $detail) use (&$checks) {
            $checks[] = compact('name', 'status', 'detail');
        };

        try {
            $t = microtime(true);
            DB::select('select 1');
            $add('database', 'PASS', DB::connection()->getDatabaseName().' reachable in '.round((microtime(true) - $t) * 1000).' ms');
        } catch (Throwable $e) {
            $add('database', 'FAIL', 'not reachable: '.class_basename($e));
        }

        try {
            $jobs = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $failed = DB::table('failed_jobs')->count();
            $age = $oldest ? time() - (int) $oldest : 0;
            $add('queue backlog', $age > 900 ? 'WARN' : 'PASS', "{$jobs} waiting".($oldest ? ", oldest {$age}s" : ''));
            $add('failed jobs', $failed > 0 ? 'WARN' : 'PASS', "{$failed} in failed_jobs");
        } catch (Throwable $e) {
            $add('queue backlog', 'UNKNOWN', 'jobs table not readable');
        }

        $queue = (string) config('queue.default');
        $add('queue worker', $queue === 'sync' ? 'PASS' : (Cache::get(Platform::WORKER_HEARTBEAT) ? 'PASS' : 'FAIL'),
            $queue === 'sync' ? 'sync queue (no worker needed)' : (Cache::get(Platform::WORKER_HEARTBEAT) ? 'heartbeat seen in the last 90 s' : 'no heartbeat in the last 90 s'));
        $add('scheduler', Cache::get(Platform::SCHEDULER_HEARTBEAT) ? 'PASS' : 'WARN', (Cache::get(Platform::SCHEDULER_HEARTBEAT) ? 'heartbeat seen in the last 150 s' : 'no heartbeat: the scheduler is not running').'; '.(\App\Console\ScheduledTasks::safeMode() ? 'safe mode ON ('.count(\App\Console\ScheduledTasks::SAFE).' tasks)' : 'safe mode OFF (all tasks)'));

        $mode = Platform::mailMode();
        $add('mail', Platform::mailStopped() ? 'WARN' : ($mode === 'misconfigured' ? 'FAIL' : 'PASS'),
            Platform::mailStopped() ? 'emergency stop is ON (nothing is sent)' : "mode {$mode}".(($p = Platform::liveMailProblem()) && Platform::liveMailApplies() ? " ({$p})" : ''));

        try {
            $since = now()->subDay();
            $rows = DB::table('message_deliveries')->where('created_at', '>=', $since)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
            $bad = ($rows['failed'] ?? 0) + ($rows['blocked'] ?? 0);
            $add('email deliveries (24 h)', $bad > 0 ? 'WARN' : 'PASS', $rows ? json_encode($rows) : 'none');
        } catch (Throwable) {
            $add('email deliveries (24 h)', 'UNKNOWN', 'message_deliveries not readable');
        }

        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        if ($free !== false && $total) {
            $pct = round($free / $total * 100, 1);
            $add('disk', $pct < 10 ? 'FAIL' : ($pct < 20 ? 'WARN' : 'PASS'), round($free / 1073741824, 1)." GB free ({$pct}%)");
        } else {
            $add('disk', 'UNKNOWN', 'could not read');
        }

        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $size = filesize($log);
            $recent = $this->recentErrors($log);
            $add('application log', $recent > 0 ? 'WARN' : 'PASS', round($size / 1024)." KB; {$recent} ERROR+ entries in the last 24 h");
        } else {
            $add('application log', 'PASS', 'no laravel.log yet');
        }

        $dir = (string) ($this->option('backups') ?: (getenv('USERPROFILE') ? getenv('USERPROFILE').DIRECTORY_SEPARATOR.'E-Notary-backups' : ''));
        $latest = null;
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $pkg) {
            if (is_file($pkg.DIRECTORY_SEPARATOR.'manifest.json') && (! $latest || filemtime($pkg) > filemtime($latest))) {
                $latest = $pkg;
            }
        }
        if ($latest) {
            $hours = round((time() - filemtime($latest)) / 3600, 1);
            $manifest = json_decode((string) file_get_contents($latest.DIRECTORY_SEPARATOR.'manifest.json'), true) ?: [];
            $triggers = $manifest['trigger_count'] ?? $manifest['triggers']['count'] ?? null;
            $add('last backup', $hours > 26 ? 'WARN' : 'PASS', basename($latest)." ({$hours} h old".($triggers !== null ? ", {$triggers} triggers" : '').')');
        } else {
            $add('last backup', 'WARN', 'no verified backup package found in '.($dir ?: '(unknown folder)'));
        }

        $failed = collect($checks)->contains('status', 'FAIL');

        if ($this->option('json')) {
            $this->line(json_encode(['checked_at' => now()->toIso8601String(), 'ok' => ! $failed, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Detail'], array_map(fn ($c) => [$c['name'], $c['status'], $c['detail']], $checks));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** ERROR or worse in the last 24 h, from the tail of the log only (bounded read). */
    private function recentErrors(string $log): int
    {
        $handle = fopen($log, 'rb');
        $size = filesize($log);
        fseek($handle, max(0, $size - 2 * 1024 * 1024));
        $tail = stream_get_contents($handle);
        fclose($handle);

        $since = now()->subDay()->format('Y-m-d H:i:s');
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/m', $tail, $m);

        return count(array_filter($m[1], fn ($ts) => str_replace('T', ' ', $ts) >= $since));
    }
}
