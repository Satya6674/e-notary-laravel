<?php

namespace Tests\Feature;

use App\Console\ScheduledTasks;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\ContactAcknowledgement;
use App\Notifications\ContactMessageReceived;
use App\Notifications\MobileOtpTestNotification;
use App\Support\Platform;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The remaining automatic email workflows (owner-approved 2026-11-11):
 *  - appointment reminders run under scheduler safe mode, the only email task it allows;
 *  - contact-form copies go to MAIL_ADMIN_ADDRESS;
 *  - the visitor's contact acknowledgement is live, behind its own abuse limits.
 *
 * Mail is captured in memory by a transport Live Mail treats as real, so the Live Mail rules, the
 * emergency stop and the delivery history all apply. Jobs go through the database queue and a
 * queue:work run, as on the live site. Nothing leaves this process.
 */
class RemainingEmailWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private ArrayTransport $captured;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        \Symfony\Component\HttpFoundation\Request::setTrustedHosts([]);

        $this->captured = new ArrayTransport;
        Mail::extend('capture', fn () => $this->captured);

        config([
            'mail.live_enabled' => true,
            'mail.live_recipients' => 'any',
            'mail.default' => 'capture',
            'mail.mailers.capture' => ['transport' => 'capture'],
            'mail.from.address' => 'sender@example.test',
            'mail.admin_address' => 'office@example.test',
            'services.outbound.mail' => null,
            'app.url' => 'http://127.0.0.1:8000',
            'queue.default' => 'database',
        ]);
        app('mail.manager')->forgetMailers();
    }

    // ---------------------------------------------------------------- helpers

    private function person(string $role = 'public', array $extra = []): User
    {
        $n = ++$this->n;
        $user = User::create(['name' => "Workflow {$n}", 'email' => "workflow{$n}@example.test", 'mobile' => '95600'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(array_merge(['mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null], $extra))->save();

        return $user->fresh();
    }

    private function appointment(User $citizen, User $notary, Carbon $at, string $status = 'confirmed', string $requestStatus = NotaryRequest::STATUS_ASSIGNED): Appointment
    {
        $application = NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $citizen->id, 'notary_id' => $notary->id,
            'document_type' => 'affidavit', 'purpose' => 'Reminder test', 'status' => $requestStatus]);

        return Appointment::create(['request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary->id,
            'scheduled_at' => $at, 'mode' => 'video', 'status' => $status]);
    }

    /** Runs the queue worker until no job is left (bounded). */
    private function work(): void
    {
        for ($i = 0; $i < 10 && DB::table('jobs')->count() > 0; $i++) {
            Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
        }
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $out = [];
        foreach ($this->captured->messages() as $message) {
            foreach ($message->getEnvelope()->getRecipients() as $address) {
                $out[] = $address->getAddress();
            }
        }
        sort($out);

        return $out;
    }

    private function contact(string $email, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('contact.store'), $extra + ['name' => 'Visitor Name', 'email' => $email, 'subject' => 'General Inquiry',
            'message' => 'A sample message for this test.']);
    }

    /** The closure's name, or the artisan command (as SchedulerSafeModeTest reads it). */
    private static function taskName(Event $event): string
    {
        if ($event->command === null) {
            return (string) $event->description;
        }

        preg_match("/artisan['\"]?\\s+(\\S+)/", (string) $event->command, $m);

        return $m[1] ?? (string) $event->command;
    }

    private function eventNamed(Schedule $schedule, string $name): ?Event
    {
        return collect($schedule->events())->first(fn (Event $e) => $e->command !== null && str_contains((string) $e->command, $name));
    }

    // ---------------------------------------------------------------- appointment reminders

    public function test_only_confirmed_upcoming_appointments_of_open_applications_qualify(): void
    {
        $citizen = $this->person();
        $notary = $this->person('notary');

        $due = $this->appointment($citizen, $notary, now()->addHours(3));
        $past = $this->appointment($citizen, $notary, now()->subHour());
        $later = $this->appointment($citizen, $notary, now()->addHours(25));
        $requested = $this->appointment($citizen, $notary, now()->addHours(2), 'requested');
        $cancelled = $this->appointment($citizen, $notary, now()->addHours(2), 'cancelled');
        $completed = $this->appointment($citizen, $notary, now()->addHours(2), 'completed');
        $rejectedApp = $this->appointment($citizen, $notary, now()->addHours(2), 'confirmed', NotaryRequest::STATUS_REJECTED);
        $completedApp = $this->appointment($citizen, $notary, now()->addHours(2), 'confirmed', NotaryRequest::STATUS_COMPLETED);
        $deletedApp = $this->appointment($citizen, $notary, now()->addHours(2));
        $deletedApp->request->delete();
        $historical = $this->appointment($citizen, $notary, now()->addHours(4));
        $historical->forceFill(['reminded_at' => now()->subDay()])->save();

        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 1')->assertSuccessful();

        $this->assertNotNull($due->fresh()->reminded_at);
        foreach ([$past, $later, $requested, $cancelled, $completed, $rejectedApp, $completedApp, $deletedApp] as $skipped) {
            $this->assertNull($skipped->fresh()->reminded_at, 'appointment #'.$skipped->id.' is not reminded');
        }
        $this->assertTrue(Carbon::parse($historical->fresh()->reminded_at)->isSameDay(now()->subDay()), 'a reminder already sent is never replayed');

        $this->assertSame(2, MessageDelivery::where('purpose', 'portal')->where('status', MessageDelivery::QUEUED)->count(), 'one email each to Citizen and Notary, queued');
        $this->work();
        $this->assertSame([$citizen->email, $notary->email], $this->recipients());
        $this->assertSame(2, MessageDelivery::where('purpose', 'portal')->where('status', MessageDelivery::ACCEPTED)->count());
        $this->assertSame(1, AuditLog::where('action', 'appointment.reminded')->count());
    }

    public function test_the_reminder_shows_indian_time_for_a_utc_appointment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 04:00:00', 'UTC'));
        $this->appointment($this->person(), $this->person('notary'), Carbon::parse('2026-11-12 08:30:00', 'UTC'));

        $this->artisan('enotary:appointment-reminders')->assertSuccessful();
        $this->work();

        $body = $this->captured->messages()->first()->toString();
        $this->assertStringContainsString('14:00', quoted_printable_decode($body), '08:30 UTC is 14:00 IST');
        $this->assertStringContainsString('IST', $body);
    }

    public function test_repeated_runs_and_worker_redelivery_never_send_a_second_reminder(): void
    {
        $citizen = $this->person();
        $notary = $this->person('notary');
        $this->appointment($citizen, $notary, now()->addHours(5));

        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 1');
        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 0');
        $this->travel(1)->hours();
        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 0');

        $this->work();
        $this->assertCount(2, $this->captured->messages());

        // A job delivered again (a worker crash after the send) is cancelled: the email was accepted.
        $delivery = MessageDelivery::where('user_id', $citizen->id)->sole();
        $notification = new \App\Notifications\PortalNotification('Appointment reminder', 'x', '/appointments');
        $notification->id = $delivery->notification_id;
        $citizen->notifyNow($notification, ['mail']);
        $this->assertCount(2, $this->captured->messages(), 'no duplicate after redelivery');
    }

    public function test_a_deactivated_or_deleted_account_is_not_emailed(): void
    {
        $citizen = $this->person();
        $notary = $this->person('notary', ['deactivated_at' => now()]);
        $this->appointment($citizen, $notary, now()->addHours(2));

        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 1');
        $this->work();

        $this->assertSame([$citizen->email], $this->recipients());
    }

    public function test_safe_mode_schedules_reminders_hourly_and_none_of_the_cleanup_tasks(): void
    {
        $this->assertTrue(ScheduledTasks::safeMode());
        $schedule = app(Schedule::class);

        $reminders = $this->eventNamed($schedule, 'enotary:appointment-reminders');
        $this->assertNotNull($reminders);
        $this->assertSame('0 * * * *', $reminders->expression);
        $this->assertTrue($reminders->withoutOverlapping);

        foreach (['enotary:clear-expired-codes', 'enotary:expire-stale-requests', 'enotary:clear-stale-drafts', 'queue:prune-failed'] as $blocked) {
            $this->assertNull($this->eventNamed($schedule, $blocked), $blocked.' stays blocked');
        }

        // At the nightly cleanup times (02:00-02:30 IST) only heartbeat-type tasks are due; at the top
        // of an hour the reminders join them.
        foreach (['2026-11-11 20:30:00', '2026-11-11 20:40:00', '2026-11-11 20:50:00', '2026-11-11 21:00:00'] as $utc) {
            $this->travelTo(Carbon::parse($utc, 'UTC'));
            $due = collect($schedule->dueEvents(app()))->map(fn (Event $e) => self::taskName($e))->sort()->values()->all();
            $expected = str_ends_with($utc, '21:00:00')
                ? ['enotary:appointment-reminders', 'enotary:record-access-expiry', 'scheduler-heartbeat']
                : ['scheduler-heartbeat'];
            $this->assertSame($expected, $due, $utc);
        }
    }

    public function test_the_emergency_stop_blocks_a_queued_reminder_and_nothing_is_replayed_after(): void
    {
        $citizen = $this->person();
        $notary = $this->person('notary');
        $this->appointment($citizen, $notary, now()->addHours(2));

        $this->artisan('enotary:appointment-reminders')->assertSuccessful();
        $this->artisan('enotary:mail-stop', ['action' => 'on'])->assertSuccessful();
        $this->work();

        $this->assertCount(0, $this->captured->messages());
        $this->assertSame(['mail_stopped', 'mail_stopped'], MessageDelivery::orderBy('id')->pluck('error')->all());
        $this->assertSame([MessageDelivery::BLOCKED, MessageDelivery::BLOCKED], MessageDelivery::orderBy('id')->pluck('status')->all());
        $this->assertSame(0, DB::table('jobs')->count(), 'not retried');

        $this->artisan('enotary:mail-stop', ['action' => 'off'])->assertSuccessful();
        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 0');
        $this->work();
        $this->assertCount(0, $this->captured->messages(), 'blocked reminders are not replayed');
    }

    public function test_tests_never_use_the_live_stop_file(): void
    {
        $this->assertNotSame(storage_path(Platform::MAIL_STOP_FILE), Platform::mailStopFile());
        $this->assertStringContainsString('testing', Platform::mailStopFile());
    }

    // ---------------------------------------------------------------- contact form

    public function test_a_valid_message_queues_the_admin_copy_and_the_visitor_acknowledgement(): void
    {
        $this->contact('Visitor@Example.org')->assertRedirect(route('home').'#contact')->assertSessionHas('contact_status');

        $this->assertSame(2, DB::table('jobs')->count(), 'both emails are queued, not sent in the request');
        $this->assertEqualsCanonicalizing(['contact_acknowledgement', 'contact_message_received'], MessageDelivery::pluck('purpose')->all());
        $this->assertSame([MessageDelivery::QUEUED], MessageDelivery::distinct()->pluck('status')->all());

        $this->work();

        $this->assertSame(['Visitor@Example.org', 'office@example.test'], $this->recipients());
        $this->assertSame([MessageDelivery::ACCEPTED], MessageDelivery::distinct()->pluck('status')->all());
        $this->assertSame(['Vi***@Example.org', 'of***@example.test'], MessageDelivery::orderBy('recipient')->pluck('recipient')->sort()->values()->all(), 'history keeps masked addresses only');
    }

    public function test_the_acknowledgement_is_fixed_text_and_reveals_nothing_internal(): void
    {
        $this->contact('visitor@example.org', ['name' => 'Click http://evil.example now', 'message' => 'RELAY-THIS-TEXT to the victim please.']);
        $this->work();

        $ack = collect($this->captured->messages())->first(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress() === 'visitor@example.org');
        $text = quoted_printable_decode($ack->toString());

        $this->assertStringContainsString('we received your message', $text);
        foreach (['evil.example', 'RELAY-THIS-TEXT', 'office@example.test', 'admin/messages', 'General Inquiry'] as $absent) {
            $this->assertStringNotContainsString($absent, $text, $absent);
        }
    }

    public function test_invalid_rejected_or_honeypot_submissions_send_nothing(): void
    {
        $this->post(route('contact.store'), ['name' => '', 'email' => 'not-an-address', 'subject' => 'Nope', 'message' => 'short'])->assertSessionHasErrors();
        $this->contact('bot@example.org', ['website' => 'http://spam.example']);

        $this->assertSame(0, ContactMessage::count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, MessageDelivery::count());
    }

    public function test_one_acknowledgement_per_address_per_day_while_every_message_reaches_the_admin(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->contact('repeat@example.org');
        $this->contact('REPEAT@example.org');
        $this->travel(23)->hours();
        $this->contact('repeat@example.org');

        $this->assertSame(3, ContactMessage::count());
        $this->assertSame(1, MessageDelivery::where('purpose', 'contact_acknowledgement')->count());
        $this->assertSame(3, MessageDelivery::where('purpose', 'contact_message_received')->count());

        // Only a message with no other from that address in the previous 24 h is acknowledged, so a
        // sender writing every few hours cannot keep the acknowledgements coming.
        $this->travel(2)->hours();
        $this->contact('repeat@example.org');
        $this->assertSame(1, MessageDelivery::where('purpose', 'contact_acknowledgement')->count());

        $this->travel(25)->hours();
        $this->contact('repeat@example.org');
        $this->assertSame(2, MessageDelivery::where('purpose', 'contact_acknowledgement')->count(), 'after a quiet day, a new acknowledgement');
    }

    public function test_the_site_wide_daily_cap_and_own_addresses_stop_the_acknowledgement(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['mail.contact_ack_daily_limit' => 2]);

        $this->contact('one@example.org');
        $this->contact('two@example.org');
        $this->contact('three@example.org');
        $this->contact('office@example.test');
        $this->contact('SENDER@example.test');

        $this->assertSame(['on***@example.org', 'tw***@example.org'], MessageDelivery::where('purpose', 'contact_acknowledgement')->orderBy('id')->pluck('recipient')->all());
        $this->assertSame(5, MessageDelivery::where('purpose', 'contact_message_received')->count());
    }

    public function test_the_contact_form_keeps_its_per_ip_throttle(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->contact("ip{$i}@example.org")->assertRedirect();
        }
        $this->contact('ip9@example.org')->assertStatus(429);
        $this->assertSame(3, ContactMessage::count());
    }

    public function test_without_an_admin_address_the_copy_goes_to_active_admin_accounts(): void
    {
        config(['mail.admin_address' => null]);
        $admin = $this->person('admin');
        $this->person('admin', ['deactivated_at' => now()]);

        $this->contact('visitor@example.org');
        $this->work();

        $this->assertSame(['visitor@example.org', $admin->email], $this->recipients(), 'the active Admin only, not the deactivated one');
    }

    // ---------------------------------------------------------------- policy

    public function test_the_acknowledgement_is_live_and_test_messages_stay_blocked(): void
    {
        $this->assertNull(Platform::liveMailRefusal(ContactAcknowledgement::class));
        $this->assertNull(Platform::liveMailRefusal(ContactMessageReceived::class));
        $this->assertSame('purpose_not_live', Platform::liveMailRefusal(MobileOtpTestNotification::class));
        $this->assertSame('purpose_not_live', Platform::liveMailRefusal(null), 'enotary:mail-test and other plain mailables');

        $this->artisan('enotary:mail-test', ['to' => 'someone@example.org', '--force' => true])->assertFailed();
        $this->assertCount(0, $this->captured->messages());
    }
}
