<?php

namespace App\Console;

use App\Support\Platform;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/**
 * Every automatic task of the application, in one place.
 *
 * SAFE MODE (config scheduler.safe_mode, env SCHEDULER_SAFE_MODE, default ON): only the tasks in
 * SAFE are registered with the scheduler, so `schedule:run` / `schedule:work` cannot start any other
 * task. The other tasks are not registered at all, not just skipped. The commands themselves stay
 * available to run by hand.
 *
 *   SAFE (owner-approved 2026-11-10, Option B; reminders added with the owner's approval 2026-11-11):
 *     scheduler-heartbeat            every minute  one cache key (scheduler health)
 *     enotary:record-access-expiry   hourly        appends audit entries for expired grants; idempotent
 *     enotary:appointment-reminders  hourly        one reminder per confirmed appointment starting in
 *                                                  the next 24 h, claimed via reminded_at; never a past one
 *
 *   NOT IN SAFE MODE (change or delete data, or close requests; each needs the owner's approval):
 *     enotary:clear-expired-codes   02:00 IST    deletes one-time codes and reset tokens over a day old
 *     enotary:expire-stale-requests  02:10 IST    closes overdue recovery requests, emails a rejection
 *     enotary:clear-stale-drafts     02:20 IST    deletes drafts over 14 days old and their files
 *     queue:prune-failed --hours=720 02:30 IST    deletes failed jobs over 30 days old
 */
final class ScheduledTasks
{
    /** Names of the tasks registered in safe mode. */
    public const SAFE = ['scheduler-heartbeat', 'enotary:record-access-expiry', 'enotary:appointment-reminders'];

    /** Names of the tasks registered only when safe mode is off: cleanup, deletion and expiry. */
    public const UNSAFE = ['enotary:clear-expired-codes', 'enotary:expire-stale-requests', 'enotary:clear-stale-drafts', 'queue:prune-failed'];

    public static function safeMode(): bool
    {
        // Anything but an explicit false keeps safe mode on.
        return config('scheduler.safe_mode', true) !== false;
    }

    public static function register(Schedule $schedule, bool $safeMode): void
    {
        $schedule->call(fn () => Cache::put(Platform::SCHEDULER_HEARTBEAT, true, 150))->everyMinute()->name('scheduler-heartbeat');

        // Audit only: temporary access already ends at its expiry without this.
        $schedule->command('enotary:record-access-expiry')->hourly()->withoutOverlapping();

        // The one email task allowed in safe mode. Each appointment's reminder is claimed through
        // reminded_at before anything is sent, so a missed, repeated or overlapping run cannot double
        // one, and only appointments still ahead qualify, so nothing historical is ever replayed.
        $schedule->command('enotary:appointment-reminders')->hourly()->withoutOverlapping();

        if ($safeMode) {
            return;
        }

        // Daily tasks run at night in Indian time, not at UTC midnight (05:30 IST). Every task is
        // idempotent, so a missed or repeated run does no harm, and none deletes an account or an
        // audit entry. withoutOverlapping() stops a slow run from starting a second copy.
        $schedule->command('enotary:clear-expired-codes')->timezone(Platform::DISPLAY_TIMEZONE)->dailyAt('02:00')->withoutOverlapping();
        $schedule->command('enotary:expire-stale-requests')->timezone(Platform::DISPLAY_TIMEZONE)->dailyAt('02:10')->withoutOverlapping();
        $schedule->command('enotary:clear-stale-drafts')->timezone(Platform::DISPLAY_TIMEZONE)->dailyAt('02:20')->withoutOverlapping();
        $schedule->command('queue:prune-failed', ['--hours' => 720])->timezone(Platform::DISPLAY_TIMEZONE)->dailyAt('02:30')->withoutOverlapping();
    }
}
