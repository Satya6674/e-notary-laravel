<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * The email emergency stop.
 *
 *   php artisan enotary:mail-stop on       no email leaves any process, from the next message
 *   php artisan enotary:mail-stop off      email may go again (subject to the Live Mail rules)
 *   php artisan enotary:mail-stop status
 *
 * Works without restarting anything: the web server and the queue worker check the stop file for
 * every message. Queued emails that are refused are recorded as blocked ("mail_stopped") and are not
 * retried. "on" also signals the queue worker to restart, so it reloads .env afterwards.
 */
class MailStop extends Command
{
    protected $signature = 'enotary:mail-stop {action=status : on, off or status}';

    protected $description = 'Emergency stop for all outbound email (instant, no restart needed)';

    public function handle(): int
    {
        $file = Platform::mailStopFile();

        switch ($this->argument('action')) {
            case 'on':
                if (@file_put_contents($file, 'Stopped '.now()->toIso8601String()."\n") === false) {
                    $this->error('Could not write '.$file.'. Email is NOT stopped.');

                    return self::FAILURE;
                }

                AuditLog::record('system.mail_stopped');
                // The worker finishes its current job and is restarted by its supervisor.
                Artisan::call('queue:restart');
                $this->info('Email is STOPPED. Nothing will be sent until: php artisan enotary:mail-stop off');

                return self::SUCCESS;

            case 'off':
                if (is_file($file) && ! @unlink($file)) {
                    $this->error('Could not remove '.$file.'. Email is still stopped.');

                    return self::FAILURE;
                }

                AuditLog::record('system.mail_resumed');
                $this->info('Email stop removed. Mail follows the Live Mail rules again (mode: '.Platform::mailMode().').');

                return self::SUCCESS;

            case 'status':
                $this->line(Platform::mailStopped() ? 'STOPPED (no email leaves this app)' : 'running (mode: '.Platform::mailMode().')');

                return self::SUCCESS;
        }

        $this->error('Use on, off or status.');

        return self::INVALID;
    }
}
