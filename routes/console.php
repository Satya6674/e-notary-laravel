<?php

use App\Support\Platform;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('enotary:clear-expired-codes', function () {
    $otp = DB::table('otp_verifications')->where('created_at', '<', now()->subDay())->delete();
    $resets = DB::table('password_resets')->where('created_at', '<', now()->subDay())->delete();

    $this->info("Removed {$otp} one-time codes and {$resets} reset tokens.");
})->purpose('Remove expired one-time codes and password reset tokens');

// These run only where "php artisan schedule:run" is called every minute (Windows Task Scheduler entry
// "E-Notary Laravel Scheduler", or scripts/Start-Scheduler.ps1). Which tasks are registered depends on
// scheduler safe mode (default ON: heartbeat, access-expiry audit and appointment reminders only). See App\Console\ScheduledTasks.
\App\Console\ScheduledTasks::register(Schedule::getFacadeRoot(), \App\Console\ScheduledTasks::safeMode());
