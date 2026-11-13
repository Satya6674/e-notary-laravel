<?php

namespace Tests\Feature;

use App\Console\ScheduledTasks;
use App\Models\AuditLog;
use App\Support\Platform;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Scheduler safe mode (owner-approved Option B, 2026-11-10): by default only the heartbeat and the
 * temporary-access expiry audit are registered, so the scheduler cannot delete data or send email.
 */
class SchedulerSafeModeTest extends TestCase
{
    use RefreshDatabase;

    /** The name of a scheduled event: the closure's name, or the artisan command. */
    private static function taskName(Event $event): string
    {
        if ($event->command === null) {
            return (string) $event->description;
        }

        preg_match("/artisan['\"]?\\s+(\\S+)/", (string) $event->command, $m);

        return $m[1] ?? (string) $event->command;
    }

    private function names(Schedule $schedule): array
    {
        $names = array_map(fn (Event $e) => self::taskName($e), $schedule->events());
        sort($names);

        return $names;
    }

    public function test_safe_mode_is_on_by_default(): void
    {
        $this->assertTrue(config('scheduler.safe_mode'));
        $this->assertTrue(ScheduledTasks::safeMode());
    }

    public function test_only_anything_but_an_explicit_false_keeps_safe_mode_on(): void
    {
        foreach ([true, null, 'yes', 1, 'false-ish'] as $value) {
            config(['scheduler.safe_mode' => $value]);
            $this->assertTrue(ScheduledTasks::safeMode(), var_export($value, true));
        }

        config(['scheduler.safe_mode' => false]);
        $this->assertFalse(ScheduledTasks::safeMode());
    }

    public function test_the_application_schedule_holds_exactly_the_three_approved_tasks(): void
    {
        $this->assertSame(['enotary:appointment-reminders', 'enotary:record-access-expiry', 'scheduler-heartbeat'], $this->names(app(Schedule::class)));
    }

    public function test_the_allowlist_adds_only_appointment_reminders_and_blocks_the_four_cleanup_tasks(): void
    {
        $this->assertSame(['scheduler-heartbeat', 'enotary:record-access-expiry', 'enotary:appointment-reminders'], ScheduledTasks::SAFE);
        $this->assertSame(['enotary:clear-expired-codes', 'enotary:expire-stale-requests', 'enotary:clear-stale-drafts', 'queue:prune-failed'], ScheduledTasks::UNSAFE);
        $this->assertSame([], array_intersect(ScheduledTasks::SAFE, ScheduledTasks::UNSAFE));

        $reminders = collect(app(Schedule::class)->events())->first(fn (Event $e) => self::taskName($e) === 'enotary:appointment-reminders');
        $this->assertSame('0 * * * *', $reminders->expression, 'hourly, at the top of the hour');
        $this->assertTrue($reminders->withoutOverlapping);
    }

    public function test_safe_mode_registers_none_of_the_four_cleanup_tasks(): void
    {
        $safe = new Schedule;
        ScheduledTasks::register($safe, true);

        $this->assertCount(3, $safe->events());
        foreach (ScheduledTasks::UNSAFE as $blocked) {
            $this->assertNotContains($blocked, $this->names($safe), $blocked);
        }
    }

    public function test_full_mode_registers_all_seven_and_only_with_an_explicit_switch(): void
    {
        $full = new Schedule;
        ScheduledTasks::register($full, false);

        $expected = array_merge(ScheduledTasks::SAFE, ScheduledTasks::UNSAFE);
        sort($expected);
        $this->assertSame($expected, $this->names($full));
        foreach ($full->events() as $event) {
            if ($event->command !== null) {
                $this->assertTrue($event->withoutOverlapping, self::taskName($event).' has overlap protection');
            }
        }
    }

    public function test_schedule_run_at_the_nightly_cleanup_time_deletes_nothing_and_sends_nothing(): void
    {
        Notification::fake();
        Mail::fake();

        // Rows the cleanup tasks would delete if they ran.
        DB::table('otp_verifications')->insert(['mobile' => 'email:9', 'otp' => 'email_verification|$2y$04$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'expires_at' => now()->subDays(3), 'attempts' => 0, 'created_at' => now()->subDays(3)]);
        DB::table('password_resets')->insert(['email' => 'old.reset@example.test', 'token' => 'x', 'created_at' => now()->subDays(3)]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(60)]);

        // 02:00 IST (20:30 UTC), at the top of an hour: in full mode clear-expired-codes, and at :00 the
        // hourly reminders, would be due. In safe mode only the heartbeat, access-expiry and reminders
        // run, and with no upcoming appointment the reminders send nothing.
        foreach (['20:30', '20:40', '20:50', '21:00'] as $utc) {
            $this->travelTo(Carbon::parse('2026-11-11 '.$utc.':00', 'UTC'));
            $this->assertSame(0, Artisan::call('schedule:run'));
        }

        $this->assertSame(1, DB::table('otp_verifications')->count(), 'one-time codes untouched');
        $this->assertSame(1, DB::table('password_resets')->count(), 'reset tokens untouched');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'failed jobs untouched');
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertTrue(Cache::get(Platform::SCHEDULER_HEARTBEAT), 'the heartbeat ran');
        $this->assertSame(0, AuditLog::where('action', 'appointment.reminded')->count());
    }
}
