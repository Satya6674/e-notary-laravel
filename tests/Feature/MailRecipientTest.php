<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ContactMessageReceived;
use App\Notifications\NotaryRequestNotification;
use App\Notifications\PortalNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/** Who each message goes to. Mail is captured here, and nothing leaves the test. */
class MailRecipientTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::factory()->create([
            'role' => $role, 'email' => $email, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function application(User $owner, array $values = []): NotaryRequest
    {
        return NotaryRequest::create($values + [
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'affidavit', 'purpose' => 'Sample', 'status' => 'pending',
        ]);
    }

    /** The real To addresses handed to the mail transport. */
    private function recipients(): array
    {
        return Mail::getSymfonyTransport()->messages()
            ->flatMap(fn ($sent) => array_map(fn ($a) => $a->getAddress(), $sent->getOriginalMessage()->getTo()))
            ->values()->all();
    }

    public function test_each_citizen_receives_their_own_notification_and_no_one_elses(): void
    {
        $a = $this->user('public', 'citizen-a@example.test');
        $b = $this->user('public', 'citizen-b@example.test');

        $a->notify(new NotaryRequestNotification($this->application($a), 'submitted'));
        $this->assertSame(['citizen-a@example.test'], $this->recipients());

        $b->notify(new NotaryRequestNotification($this->application($b), 'submitted'));
        $this->assertSame(['citizen-a@example.test', 'citizen-b@example.test'], $this->recipients());
    }

    public function test_a_changed_address_is_used_at_send_time(): void
    {
        $citizen = $this->user('public', 'old@example.test');
        $citizen->update(['email' => 'new@example.test']);

        $citizen->fresh()->notify(new VerifyEmailNotification());

        $this->assertSame(['new@example.test'], $this->recipients());
    }

    public function test_a_decision_goes_to_the_applicant_not_to_the_person_deciding(): void
    {
        $citizen = $this->user('public', 'citizen@example.test');
        $notary = $this->user('notary', 'notary@example.test');
        $application = $this->application($citizen, ['notary_id' => $notary->id, 'status' => 'assigned']);

        $this->actingAs($notary)->post(route('notary.request.approve', $application->id), ['remarks' => 'Looks correct', 'reason' => 'Looks correct', 'decision_remarks' => 'Looks correct']);

        $this->assertSame(['citizen@example.test'], $this->recipients());
        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_notary_receives_only_work_assigned_to_them(): void
    {
        Notification::fake();
        $citizen = $this->user('public', 'citizen@example.test');
        $assigned = $this->user('notary', 'assigned@example.test');
        $other = $this->user('notary', 'other@example.test');
        $application = $this->application($citizen);

        $this->actingAs($this->user('admin', 'admin@example.test'))
            ->post(route('admin.requests.assign', $application), ['notary_id' => $assigned->id])->assertRedirect();

        Notification::assertSentTo($assigned, NotaryRequestNotification::class, fn ($n) => $n->event === 'assigned');
        Notification::assertNotSentTo($other, NotaryRequestNotification::class);
        Notification::assertNotSentTo($citizen, NotaryRequestNotification::class, fn ($n) => $n->event === 'assigned');
    }

    public function test_appointment_notice_goes_to_the_other_person_only(): void
    {
        Notification::fake();
        $citizen = $this->user('public', 'citizen@example.test');
        $notary = $this->user('notary', 'notary@example.test');
        $outsider = $this->user('public', 'outsider@example.test');
        $application = $this->application($citizen, ['notary_id' => $notary->id, 'status' => 'assigned']);
        $appointment = Appointment::create(['request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary->id, 'scheduled_at' => now()->addDay(), 'mode' => 'video']);

        $this->actingAs($notary)->patch(route('appointments.update', $appointment), ['status' => 'confirmed'])->assertRedirect();

        Notification::assertSentTo($citizen, PortalNotification::class);
        Notification::assertNotSentTo($outsider, PortalNotification::class);
    }

    public function test_support_tickets_notify_active_admins_only(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'admin@example.test');
        $inactive = $this->user('admin', 'inactive@example.test');
        $inactive->forceFill(['deactivated_at' => now()])->save();
        $citizen = $this->user('public', 'citizen@example.test');

        $this->actingAs($citizen)->post(route('support.store'), ['subject' => 'Help needed', 'category' => SupportTicket::CATEGORIES[0], 'body' => 'Please help with this sample.'])->assertRedirect();

        $this->assertSame(1, SupportTicket::count());
        Notification::assertSentTo($admin, PortalNotification::class);
        Notification::assertNotSentTo($inactive, PortalNotification::class);
        Notification::assertNotSentTo($citizen, PortalNotification::class);
    }

    private function contact(): void
    {
        $this->post(route('contact.store'), ['name' => 'Sample', 'email' => 'visitor@example.test', 'subject' => 'General Inquiry', 'message' => 'A sample message for this test.', '_contact_form' => '1']);
    }

    public function test_contact_messages_reach_the_configured_admin_address(): void
    {
        Notification::fake();
        config(['mail.admin_address' => 'office@example.test']);

        $this->contact();

        Notification::assertSentOnDemand(ContactMessageReceived::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'office@example.test');
    }

    public function test_contact_messages_reach_admin_accounts_when_no_address_is_configured(): void
    {
        Notification::fake();
        config(['mail.admin_address' => null]);
        $admin = $this->user('admin', 'admin@example.test');

        $this->contact();

        Notification::assertSentTo($admin, ContactMessageReceived::class);
    }

    public function test_allowlist_blocks_and_never_redirects_a_citizen_message(): void
    {
        config(['services.outbound.mail' => 'approved@example.test']);
        Log::spy();
        $citizen = $this->user('public', 'citizen@example.test');

        $citizen->notify(new VerifyEmailNotification());

        $this->assertSame([], $this->recipients(), 'A blocked message must not go to anyone, including the approved address.');
        $this->assertSame(MessageDelivery::BLOCKED, MessageDelivery::firstOrFail()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context === ['to' => 'ci***@example.test'])->once();
    }

    public function test_one_event_sends_exactly_one_email(): void
    {
        $citizen = $this->user('public', 'citizen@example.test');

        $citizen->notify(new NotaryRequestNotification($this->application($citizen), 'submitted'));

        $this->assertCount(1, $this->recipients());
        $this->assertSame(1, MessageDelivery::count());
    }

    public function test_preview_mode_records_a_message_as_not_really_sent(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'null']);
        $citizen = $this->user('public', 'citizen@example.test');

        $citizen->notify(new VerifyEmailNotification());

        $this->assertSame(MessageDelivery::SIMULATED, MessageDelivery::firstOrFail()->status);
    }

    public function test_mail_modes_are_told_apart_and_shown_on_the_page(): void
    {
        // The banner is an Admin-only diagnostic: visitors see no mode at all, in any mode.
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'email' => 'banner.admin@example.test']);
        $asAdmin = fn () => $this->actingAs($admin)->get(route('admin.dashboard'));

        config(['mail.default' => 'smtp', 'services.outbound.mail' => null]);
        $this->assertSame('live', Platform::mailMode());
        $asAdmin()->assertDontSee('Safe Preview:')->assertDontSee('Live Test:');

        config(['services.outbound.mail' => 'approved@example.test']);
        $this->assertSame('live_test', Platform::mailMode());
        $asAdmin()->assertSee('Live Test:');

        config(['mail.default' => 'log', 'services.outbound.mail' => null]);
        $this->assertSame('safe_preview', Platform::mailMode());
        $asAdmin()->assertSee('Safe Preview:');

        \Illuminate\Support\Facades\Auth::logout();
        $this->get('/forgot-password')->assertDontSee('Safe Preview');
        $this->get('/')->assertDontSee('Safe Preview');
    }

    public function test_failed_job_is_recorded_without_personal_data(): void
    {
        config(['queue.default' => 'database']);
        $citizen = $this->user('public', 'citizen@example.test');
        $notification = new VerifyEmailNotification();
        $notification->id = '11111111-1111-4111-8111-111111111111';
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'ci***@example.test', 'status' => 'sending', 'notification_id' => $notification->id]);

        $command = serialize(new SendQueuedNotifications(collect([$citizen]), $notification, ['mail']));
        $job = new SyncJob($this->app, json_encode(['data' => ['command' => $command]]), 'database', 'default');

        Event::dispatch(new JobFailed('database', $job, new RuntimeException('Refused for citizen@example.test')));

        $row = MessageDelivery::firstOrFail();
        $this->assertSame(MessageDelivery::FAILED, $row->status);
        $this->assertSame('RuntimeException', $row->error);
    }

    public function test_worker_heartbeat_shows_on_the_security_page_and_never_a_secret(): void
    {
        config(['mail.mailers.smtp.password' => 'SECRET-MAIL-VALUE']);
        $admin = $this->user('admin', 'admin@example.test');

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()->assertSee('Not running')->assertDontSee('SECRET-MAIL-VALUE');

        Event::dispatch(new Looping('database', 'default'));
        $this->assertTrue(Cache::has(Platform::WORKER_HEARTBEAT));

        $this->actingAs($admin)->get(route('admin.security.index'))->assertSee('Running');
    }

    public function test_stale_queued_messages_are_counted(): void
    {
        $row = MessageDelivery::create(['channel' => 'mail', 'purpose' => 'reset_password', 'recipient' => 'ci***@example.test', 'status' => 'queued']);
        $row->forceFill(['created_at' => now()->subHour()])->save();

        $this->actingAs($this->user('admin', 'admin@example.test'))->get(route('admin.security.index'))->assertSee('Messages Queued Over 15 Minutes');
    }
}
