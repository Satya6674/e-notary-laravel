<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\AccountRecoveryNotification;
use App\Notifications\PortalNotification;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use App\Support\Platform;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 13: scheduled tasks are registered in Indian time, are idempotent, delete no account
 * and no audit entry; queued jobs retry with back-off and a failed job can be retried once.
 */
class SchedulerAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function person(string $role = 'public', array $values = []): User
    {
        $n = ++$this->n;
        $user = User::create($values + ['name' => "Sched {$n}", 'email' => "sched{$n}@example.test", 'mobile' => '95500'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(['mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
            'email_verification_method' => 'signed_link', 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user->fresh();
    }

    private function recovery(string $made): AccountRecoveryRequest
    {
        $this->travelTo(Carbon::parse($made, 'UTC'));
        $admin = User::where('role', 'admin')->first() ?? $this->person('admin');
        $old = $this->person();
        app(AccountDeletionService::class)->delete($admin, $old, 'Synthetic deletion for testing.');
        $deletion = AccountDeletion::where('user_id', $old->id)->sole();
        $requester = $this->person('public', ['mobile' => $deletion->mobile]);
        DB::table('users')->where('id', $requester->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);
        $recovery = app(AccountRecoveryService::class)->submit($requester->fresh(), $deletion->id);
        $this->travelBack();

        return $recovery;
    }

    public function test_the_schedule_holds_every_task_in_indian_time_without_overlap(): void
    {
        // The full schedule (safe mode OFF). The live default, safe mode ON, is covered by SchedulerSafeModeTest.
        $full = new Schedule;
        \App\Console\ScheduledTasks::register($full, false);
        $events = collect($full->events())->keyBy(fn ($e) => Str::after((string) $e->command, 'artisan\' '));
        $find = fn (string $name) => $events->first(fn ($e) => str_contains((string) $e->command, $name));

        foreach (['enotary:clear-expired-codes' => '0 2 * * *', 'enotary:expire-stale-requests' => '10 2 * * *', 'enotary:clear-stale-drafts' => '20 2 * * *', 'queue:prune-failed' => '30 2 * * *'] as $name => $cron) {
            $event = $find($name);
            $this->assertNotNull($event, $name);
            $this->assertSame($cron, $event->expression, $name);
            $this->assertSame(Platform::DISPLAY_TIMEZONE, $event->timezone, $name);
        }

        $this->assertTrue($find('enotary:appointment-reminders')->withoutOverlapping);
        $this->assertTrue($find('enotary:expire-stale-requests')->withoutOverlapping);
    }

    public function test_an_overdue_recovery_is_closed_once_and_the_person_may_ask_again(): void
    {
        if (! User::accountRecoverySupported()) {
            $this->markTestSkipped('Needs the recovery tables.');
        }

        $overdue = $this->recovery('2026-09-01 10:00:00');
        $recent = $this->recovery('2026-10-25 10:00:00');
        Notification::fake(); // only what the scheduled task sends
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'UTC'));
        $users = User::count();
        $audit = AuditLog::count();

        $this->artisan('enotary:expire-stale-requests')->expectsOutputToContain('Recovery requests closed as expired: 1.')->assertSuccessful();
        $this->artisan('enotary:expire-stale-requests')->expectsOutputToContain('Recovery requests closed as expired: 0.')->assertSuccessful();

        $this->assertSame(AccountRecoveryRequest::EXPIRED, $overdue->fresh()->status);
        $this->assertNull($overdue->fresh()->open_key);
        $this->assertNull($overdue->fresh()->reviewed_by, 'No administrator is credited with an automatic closure.');
        $this->assertSame(User::CHOICE_CHECK, $overdue->requester->fresh()->previous_account_choice);
        $this->assertTrue($overdue->oldUser->fresh()->isDeleted(), 'Nothing is restored.');
        $this->assertTrue($recent->fresh()->isOpen(), 'A request within 30 days is left alone.');

        $this->assertSame($users, User::count(), 'No account is created or deleted.');
        $this->assertSame($audit + 1, AuditLog::count(), 'Exactly one new audit entry and none removed.');
        $this->assertSame(['reference' => $overdue->reference, 'reason' => 'scheduled', 'age_days' => 60], AuditLog::where('action', 'account.recovery_expired')->sole()->meta);

        Notification::assertSentTimes(AccountRecoveryNotification::class, 1);
        Notification::assertSentTo($overdue->requester, AccountRecoveryNotification::class, function ($n) use ($overdue) {
            return $n->notice === 'rejected'
                && str_contains($n->toMail($overdue->requester)->subject, 'has expired');
        });
    }

    public function test_a_request_decided_meanwhile_is_not_touched(): void
    {
        if (! User::accountRecoverySupported()) {
            $this->markTestSkipped('Needs the recovery tables.');
        }

        $recovery = $this->recovery('2026-09-01 10:00:00');
        $recovery->forceFill(['status' => AccountRecoveryRequest::REJECTED, 'open_key' => null, 'decision_reason' => 'Decided already.'])->save();
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'UTC'));

        $this->artisan('enotary:expire-stale-requests')->expectsOutputToContain('closed as expired: 0.');
        $this->assertSame(AccountRecoveryRequest::REJECTED, $recovery->fresh()->status);
        $this->assertSame('Decided already.', $recovery->fresh()->decision_reason);
    }

    public function test_a_lapsed_replacement_link_is_marked_expired_and_frees_the_address(): void
    {
        if (! EmailReplacement::supported()) {
            $this->markTestSkipped('Needs the email_replacements table.');
        }

        $admin = $this->person('admin');
        $user = $this->person();
        $replacement = EmailReplacement::create([
            'reference' => 'ER-TEST-1', 'user_id' => $user->id, 'requested_by' => $admin->id, 'old_email' => $user->email, 'new_email' => 'fresh@example.test',
            'identity_check' => 'video_call', 'identity_note' => 'Video call, matched the photo.', 'reason' => 'Lost access to the old mailbox for good.',
            'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->subMinute(), 'status' => EmailReplacement::PENDING,
            'open_key' => $user->id, 'open_email' => 'fresh@example.test',
        ]);
        $live = EmailReplacement::create([
            'reference' => 'ER-TEST-2', 'user_id' => $admin->id, 'requested_by' => $admin->id, 'old_email' => $admin->email, 'new_email' => 'other@example.test',
            'identity_check' => 'video_call', 'identity_note' => 'Video call, matched the photo.', 'reason' => 'Lost access to the old mailbox for good.',
            'token_hash' => hash('sha256', 'y'), 'expires_at' => now()->addHour(), 'status' => EmailReplacement::PENDING,
            'open_key' => $admin->id, 'open_email' => 'other@example.test',
        ]);

        $this->artisan('enotary:expire-stale-requests')->expectsOutputToContain('Replacement links marked expired: 1.');
        $this->artisan('enotary:expire-stale-requests')->expectsOutputToContain('Replacement links marked expired: 0.');

        $this->assertSame([EmailReplacement::EXPIRED, null, null], [$replacement->fresh()->status, $replacement->fresh()->open_key, $replacement->fresh()->open_email]);
        $this->assertSame(EmailReplacement::PENDING, $live->fresh()->status);
        $this->assertSame($user->email, $user->fresh()->email, 'The account itself is unchanged.');
    }

    public function test_reminders_are_sent_once_even_when_the_task_runs_twice(): void
    {
        Notification::fake();
        $citizen = $this->person();
        $notary = $this->person('notary');
        $application = NotaryRequest::create(['request_number' => 'REQ-SCH-1', 'user_id' => $citizen->id, 'notary_id' => $notary->id,
            'document_type' => 'affidavit', 'purpose' => 'x', 'status' => NotaryRequest::STATUS_ASSIGNED]);
        $soon = Appointment::create(['request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary->id,
            'scheduled_at' => now()->addHours(3), 'mode' => 'video', 'status' => 'confirmed']);
        Appointment::create(['request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary->id,
            'scheduled_at' => now()->addDays(3), 'mode' => 'video', 'status' => 'confirmed']);

        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 1');
        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 0');

        Notification::assertSentToTimes($citizen, PortalNotification::class, 1);
        Notification::assertSentToTimes($notary, PortalNotification::class, 1);
        $this->assertNotNull($soon->fresh()->reminded_at);
        $this->assertSame(1, AuditLog::where('action', 'appointment.reminded')->count());
    }

    public function test_code_cleanup_removes_only_day_old_codes_and_never_audit_entries(): void
    {
        DB::table('otp_verifications')->insert([
            ['mobile' => '9555000001', 'otp' => 'x', 'expires_at' => now()->subDays(2), 'created_at' => now()->subDays(2)],
            ['mobile' => '9555000002', 'otp' => 'y', 'expires_at' => now()->addMinutes(5), 'created_at' => now()],
        ]);
        AuditLog::record('test.entry');
        $audit = AuditLog::count();

        $this->artisan('enotary:clear-expired-codes')->assertSuccessful();
        $this->artisan('enotary:clear-expired-codes')->assertSuccessful();

        $this->assertSame(['9555000002'], DB::table('otp_verifications')->pluck('mobile')->all());
        $this->assertSame($audit, AuditLog::count());
    }

    public function test_queued_mail_retries_with_backoff_and_runs_after_commit(): void
    {
        $notice = new PortalNotification('Title', 'Body', 'https://example.test');

        $this->assertSame(3, $notice->tries);
        $this->assertSame([30, 120, 600], $notice->backoff);
        $this->assertTrue($notice->afterCommit);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notice);
    }

    public function test_a_failed_job_is_listed_and_can_be_retried_once_by_an_admin(): void
    {
        $admin = $this->person('admin');
        $citizen = $this->person();
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            // A real queued notification, as the database queue stores it.
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => PortalNotification::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 3, 'backoff' => '30,120,600', 'timeout' => null, 'retryUntil' => null,
                'data' => ['commandName' => SendQueuedNotifications::class, 'command' => serialize(new SendQueuedNotifications(collect([$citizen]), new PortalNotification('Title', 'Body', 'https://example.test'), ['mail']))]]),
            'exception' => "RuntimeException: SMTP refused\n#0 secret trace line with smtp.example.test", 'failed_at' => now(),
        ]);

        $this->actingAs($citizen)->get(route('admin.delivery.index'))->assertForbidden();
        $this->actingAs($citizen)->post(route('admin.delivery.retry', $uuid))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.delivery.index'))->assertOk()
            ->assertSee('RuntimeException: SMTP refused')->assertDontSee('secret trace line');

        $this->actingAs($admin)->post(route('admin.delivery.retry', $uuid))->assertSessionHas('status');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'Back on the queue exactly once.');
        $this->actingAs($admin)->post(route('admin.delivery.retry', $uuid))->assertNotFound();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.delivery_retried')->count());
    }
}
