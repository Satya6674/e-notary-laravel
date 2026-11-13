<?php

namespace Tests\Feature;

use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ContactAcknowledgement;
use App\Notifications\MobileOtpTestNotification;
use App\Notifications\PortalNotification;
use App\Notifications\SecurityAlertNotification;
use App\Services\AccountDeletionService;
use App\Services\ApplicationDecisionService;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * System-wide Live Mail (approved 2026-11-08): every business notification may go out, to any
 * legitimate recipient, and only when its own event happens.
 *
 * Nothing leaves this process: "capture" keeps messages in memory and "failing" throws. Live Mail
 * treats both as real transports, so its rules apply. SMTP points at a closed local port.
 */
class SystemWideLiveMailTest extends TestCase
{
    use RefreshDatabase;

    private ArrayTransport $captured;

    protected function setUp(): void
    {
        parent::setUp();

        \Symfony\Component\HttpFoundation\Request::setTrustedHosts([]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->captured = new ArrayTransport;
        Mail::extend('capture', fn () => $this->captured);
        Mail::extend('failing', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Connection could not be established');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });

        config([
            'mail.live_enabled' => true,
            'mail.live_recipients' => 'any',
            'mail.default' => 'capture',
            'mail.mailers.capture' => ['transport' => 'capture'],
            'mail.mailers.failing' => ['transport' => 'failing'],
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'username' => 'Dummy1!x', 'password' => 'Dummy1!x', 'timeout' => 2],
            'mail.from.address' => 'sender@example.test',
            'services.outbound.mail' => null,
            'app.url' => 'http://127.0.0.1:8000',
            'accounts.citizen_mobile_verification' => false,
        ]);
        app('mail.manager')->forgetMailers();
    }

    // ---------------------------------------------------------------- helpers

    private function person(string $role, string $email, array $extra = []): User
    {
        static $mobile = 9700000000;
        $mobile++;

        $user = User::factory()->create(['role' => $role, 'email' => $email, 'mobile' => (string) $mobile, 'email_verified_at' => now(), 'password' => Hash::make('A-good-password1')]);
        $user->forceFill(array_merge(['notary_approved_at' => $role === 'notary' ? now() : null], $extra))->save();

        return $user->fresh();
    }

    /** @return list<string> envelope recipients of every captured message, in order */
    private function recipients(): array
    {
        $out = [];
        foreach ($this->captured->messages() as $message) {
            foreach ($message->getEnvelope()->getRecipients() as $address) {
                $out[] = $address->getAddress();
            }
        }

        return $out;
    }

    private function application(User $citizen, ?User $notary, string $status): NotaryRequest
    {
        return NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(),
            'user_id' => $citizen->id, 'notary_id' => $notary?->id,
            'document_type' => 'affidavit', 'purpose' => 'System-wide live mail test', 'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------- policy

    public function test_every_business_notification_may_go_live_and_the_rest_stay_blocked(): void
    {
        $this->assertSame('live', Platform::mailMode());
        $this->assertNull(Platform::liveMailProblem());

        foreach (Platform::LIVE_MAIL_NOTIFICATIONS as $class) {
            $this->assertNull(Platform::liveMailRefusal($class), $class);
        }

        // Every notification class is either live or deliberately excluded: none is forgotten.
        $all = array_map(fn ($f) => 'App\\Notifications\\'.basename($f, '.php'), glob(app_path('Notifications/*.php')));
        // ContactAcknowledgement went live with the owner's approval on 2026-11-11, behind its own limits.
        $this->assertContains(ContactAcknowledgement::class, Platform::LIVE_MAIL_NOTIFICATIONS);
        $excluded = [MobileOtpTestNotification::class];
        $this->assertEqualsCanonicalizing($all, array_merge(Platform::LIVE_MAIL_NOTIFICATIONS, $excluded));

        foreach ($excluded as $class) {
            $this->assertSame('purpose_not_live', Platform::liveMailRefusal($class));
        }
        $this->assertSame('purpose_not_live', Platform::liveMailRefusal(null), 'a plain mailable is not a business event');
    }

    public function test_any_legitimate_recipient_is_allowed_and_a_malformed_one_is_not(): void
    {
        $this->assertTrue(\App\Services\DeliveryLog::allowed('mail', 'anyone@example.org'));
        $this->assertFalse(\App\Services\DeliveryLog::allowed('mail', 'not-an-address'));

        // Back on the allowlist policy, an empty list still means nobody.
        config(['mail.live_recipients' => 'allowlist']);
        $this->assertSame('recipient_allowlist_missing', Platform::liveMailProblem());
        $this->assertFalse(\App\Services\DeliveryLog::allowed('mail', 'anyone@example.org'));

        config(['mail.live_recipients' => 'everyone']);
        $this->assertSame('recipient_policy_invalid', Platform::liveMailProblem());
    }

    public static function permanentDatabaseCases(): array
    {
        return [
            'local copy, allowed'        => ['local', true, null],
            'local copy, not allowed'    => ['local', false, 'permanent_database'],
            'production ignores the flag' => ['production', true, 'permanent_database'],
        ];
    }

    #[DataProvider('permanentDatabaseCases')]
    public function test_the_permanent_database_override_is_local_only(string $environment, bool $allowed, ?string $problem): void
    {
        config(['mail.live_allow_permanent_database' => $allowed, 'app.url' => $environment === 'production' ? 'https://enotary.example' : 'http://127.0.0.1:8000']);
        $this->app->detectEnvironment(fn () => $environment);
        $connection = DB::connection();
        $name = $connection->getDatabaseName();
        $connection->setDatabaseName('enotary_local');

        try {
            $this->assertSame($problem, Platform::liveMailProblem());
        } finally {
            $connection->setDatabaseName($name);
        }
    }

    public function test_switching_live_mail_on_sends_nothing_by_itself(): void
    {
        $this->person('public', 'existing.citizen@example.test');
        $this->person('notary', 'existing.notary@example.test');
        $this->person('admin', 'existing.admin@example.test');

        $this->get(route('home'))->assertOk();
        $this->assertSame([], $this->recipients(), 'no email without an event');
    }

    // ---------------------------------------------------------------- real events, real recipients

    public function test_registration_sends_the_code_to_the_registrant_and_a_notary_signup_alerts_the_admins(): void
    {
        $admin = $this->person('admin', 'admin@example.test');
        $this->person('admin', 'deactivated.admin@example.test', ['deactivated_at' => now()]);

        $this->postJson(route('register.submit'), ['name' => 'New Notary', 'email' => 'new.notary@example.test', 'mobile' => '9711111111', 'password' => 'A-good-password1', 'password_confirmation' => 'A-good-password1', 'role' => 'notary', 'accept_terms' => '1'])->assertOk();

        $this->assertEqualsCanonicalizing(['new.notary@example.test', $admin->email], $this->recipients());
        $this->assertSame(0, MessageDelivery::whereIn('status', [MessageDelivery::BLOCKED, MessageDelivery::FAILED])->count());
    }

    public function test_admin_notary_approval_emails_the_notary_only(): void
    {
        $admin = $this->person('admin', 'admin@example.test');
        $notary = $this->person('notary', 'pending.notary@example.test', ['notary_approved_at' => null]);
        $this->person('public', 'bystander@example.test');

        $this->actingAs($admin)->post(route('admin.notaries.approve', $notary))->assertRedirect();

        $this->assertSame([$notary->email], $this->recipients());
    }

    public function test_an_application_decision_emails_the_owner_and_no_one_else(): void
    {
        $citizen = $this->person('public', 'owner@example.test');
        $notary = $this->person('notary', 'assigned.notary@example.test');
        $this->person('public', 'someone.else@example.test');
        $application = $this->application($citizen, $notary, NotaryRequest::STATUS_ASSIGNED);

        $this->assertTrue(app(ApplicationDecisionService::class)->decide($notary, $application, 'approve', 'All documents are in order.'));

        $this->assertContains($citizen->email, $this->recipients());
        $this->assertNotContains('someone.else@example.test', $this->recipients());
        $this->assertSame(1, collect($this->recipients())->filter(fn ($r) => $r === $citizen->email)->count(), 'one email to the owner');

        // The same decision again is refused, so no second email.
        $before = count($this->recipients());
        $this->assertFalse(app(ApplicationDecisionService::class)->decide($notary, $application->fresh(), 'approve', 'Again.'));
        $this->assertCount($before, $this->recipients());
    }

    public function test_support_ticket_events_reach_the_right_people(): void
    {
        $admin = $this->person('admin', 'admin@example.test');
        $citizen = $this->person('public', 'citizen@example.test');
        $this->person('public', 'another.citizen@example.test');

        $this->actingAs($citizen)->post(route('support.store'), ['subject' => 'Help with my file', 'category' => 'documents', 'body' => 'I cannot open my receipt.'])->assertRedirect();
        $this->assertSame([$admin->email], $this->recipients(), 'a new ticket alerts the Admins');

        $ticket = SupportTicket::sole();
        $this->actingAs($admin)->post(route('support.reply', $ticket), ['body' => 'Please try again now.'])->assertRedirect();
        $this->assertSame([$admin->email, $citizen->email], $this->recipients(), 'the reply reaches the ticket owner');

        $this->actingAs($admin)->post(route('support.close', $ticket))->assertRedirect();
        $this->assertSame([$admin->email, $citizen->email, $citizen->email], $this->recipients(), 'closing tells the owner');
    }

    public function test_security_alerts_go_to_the_account_and_an_email_change_also_warns_the_old_address(): void
    {
        $citizen = $this->person('public', 'old.address@example.test');

        $citizen->notify(new SecurityAlertNotification('password_changed'));
        $this->assertSame([$citizen->email], $this->recipients());

        \Illuminate\Support\Facades\Notification::route('mail', 'old.address@example.test')->notify(new SecurityAlertNotification('email_changed'));
        $this->assertSame(['old.address@example.test', 'old.address@example.test'], $this->recipients());
    }

    public function test_deletion_and_restoration_notices_go_to_the_archived_address(): void
    {
        $admin = $this->person('admin', 'admin@example.test');
        $citizen = $this->person('public', 'leaving@example.test');

        app(AccountDeletionService::class)->delete($admin, $citizen, 'Asked to leave by email.');
        $this->assertSame(['leaving@example.test'], $this->recipients(), 'the deletion notice');

        // Ordinary mail to the deleted account goes nowhere.
        $citizen->fresh()->notify(new PortalNotification('Hello', 'Body', route('home')));
        $this->assertSame(['leaving@example.test'], $this->recipients());

        app(AccountDeletionService::class)->restore($admin, $citizen->fresh(), 'Owner asked to come back.');
        $this->assertSame(['leaving@example.test'], array_values(array_unique($this->recipients())));
        $this->assertGreaterThan(1, count($this->recipients()), 'the restoration notice (and its reset link) went too');
    }

    public function test_the_contact_form_tells_the_admins_and_acknowledges_the_typed_address_once(): void
    {
        // The acknowledgement went live on 2026-11-11 behind its own limits (RemainingEmailWorkflowsTest).
        $admin = $this->person('admin', 'admin@example.test');
        $send = fn () => $this->post(route('contact.store'), ['name' => 'Visitor', 'email' => 'unproven.visitor@example.org', 'subject' => \App\Http\Controllers\Contact\ContactController::SUBJECTS[0], 'message' => 'A question about the service.'])->assertRedirect();

        $send();
        $send();

        $this->assertSame(['unproven.visitor@example.org', $admin->email, $admin->email], $this->recipients(), 'two Admin copies, one acknowledgement');
    }

    // ---------------------------------------------------------------- reliability

    public function test_a_queued_email_that_keeps_failing_is_retried_then_recorded_as_failed(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();
        $citizen = $this->person('public', 'citizen@example.test');

        $citizen->notify(new SecurityAlertNotification('password_changed'));
        $this->assertSame(MessageDelivery::QUEUED, MessageDelivery::sole()->status);

        foreach ([0, 31, 121] as $wait) {
            $this->travel($wait)->seconds();
            Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
        }
        $this->travel(601)->seconds();
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(MessageDelivery::FAILED, MessageDelivery::sole()->status, 'never reported as sent');
        $this->assertSame([], $this->recipients());
    }

    public function test_a_redelivered_job_does_not_send_an_accepted_email_twice(): void
    {
        $citizen = $this->person('public', 'citizen@example.test');
        $notification = new SecurityAlertNotification('password_changed');
        $notification->id = (string) Str::uuid();

        $citizen->notify($notification);
        $citizen->notify(clone $notification);

        $this->assertSame([$citizen->email], $this->recipients());
        $this->assertSame(1, MessageDelivery::count());
    }

    public function test_no_sms_is_ever_attempted(): void
    {
        $this->assertSame('off', Platform::smsMode());

        $citizen = $this->person('public', 'citizen@example.test');
        $this->post(route('password.email'), ['identifier' => $citizen->mobile])->assertRedirect();

        // The code went by email, and no HTTP call (the SMS provider) was made: stray requests throw.
        $this->assertSame([$citizen->email], $this->recipients());
    }
}
