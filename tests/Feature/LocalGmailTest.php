<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\SecurityIncident;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NotaryRequestNotification;
use App\Notifications\PortalNotification;
use App\Services\ApplicationDecisionService;
use App\Services\DeliveryLog;
use App\Services\StaffNotifier;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/** Mail is faked, or captured by the array mailer. Nothing leaves the test. */
class LocalGmailTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email, array $values = []): User
    {
        return User::factory()->create($values + ['role' => $role, 'email' => $email, 'email_verified_at' => now(), 'mobile_verified_at' => now()]);
    }

    private function notary(string $email): User
    {
        return $this->user('notary', $email, ['notary_approved_at' => now()]);
    }

    private function application(User $citizen, array $values = []): NotaryRequest
    {
        return NotaryRequest::create($values + [
            'request_number' => 'REQ-'.uniqid(), 'user_id' => $citizen->id, 'document_type' => 'affidavit', 'purpose' => 'Test purpose',
            'status' => NotaryRequest::STATUS_PENDING,
        ]);
    }

    private function localGmail(string $allowed = ''): void
    {
        config(['mail.mode' => 'local_gmail', 'services.outbound.mail' => $allowed]);
    }

    /** Puts mail on a real database queue, so a real worker loop has to send it. */
    private function queued(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'local_gmail', 'queue.connections.database.after_commit' => false]);
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'local_gmail', '--once' => true, '--tries' => 3]);
    }

    private function brokenMailer(): void
    {
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'broken']]);
        $this->freshMailer();

        Mail::extend('broken', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection refused');
            }

            public function __toString(): string
            {
                return 'broken';
            }
        });
    }

    private function freshMailer(): void
    {
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();
    }

    // ---- Modes

    public function test_local_gmail_is_a_mode_of_its_own(): void
    {
        $this->localGmail('a@example.test');

        $this->assertSame('local_gmail', Platform::mailMode());
    }

    public function test_safe_preview_is_never_converted_into_a_real_mail_mode(): void
    {
        $this->localGmail('a@example.test');
        config(['mail.default' => 'log']);

        $this->assertSame('safe_preview', Platform::mailMode());
    }

    public function test_production_ignores_the_local_setting(): void
    {
        $this->localGmail();
        $this->app['env'] = 'production';

        $this->assertSame('live', Platform::mailMode());
    }

    public function test_an_empty_list_blocks_everyone_in_local_gmail_only(): void
    {
        $this->assertTrue(DeliveryLog::allowed('mail', 'a@example.test'));

        $this->localGmail();

        $this->assertFalse(DeliveryLog::allowed('mail', 'a@example.test'));
    }

    public function test_only_approved_addresses_receive_mail_and_nothing_is_redirected(): void
    {
        $this->localGmail('allowed@example.test');
        $allowed = $this->user('public', 'allowed@example.test');
        $other = $this->user('public', 'other@example.test');

        $allowed->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        $other->notify(new PortalNotification('Title', 'Body', 'http://example.test'));

        $sent = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $sent);
        $this->assertSame('allowed@example.test', $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame(MessageDelivery::BLOCKED, MessageDelivery::where('user_id', $other->id)->value('status'));
    }

    public function test_safe_preview_records_a_simulation_not_a_sent_email(): void
    {
        config(['mail.default' => 'log', 'logging.default' => 'null']);
        $user = $this->user('public', 'citizen@example.test');

        $user->notify(new PortalNotification('Title', 'Body', 'http://example.test'));

        $this->assertSame(MessageDelivery::SIMULATED, MessageDelivery::where('user_id', $user->id)->value('status'));
    }

    // ---- Each recipient gets their own message

    public function test_each_citizen_receives_only_their_own_email(): void
    {
        $this->localGmail('a@example.test,b@example.test');
        $a = $this->user('public', 'a@example.test');
        $b = $this->user('public', 'b@example.test');
        $applicationA = $this->application($a);
        $applicationB = $this->application($b);

        $a->notify(new NotaryRequestNotification($applicationA, 'submitted'));
        $b->notify(new NotaryRequestNotification($applicationB, 'submitted'));

        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(2, $messages);
        foreach ([[$messages->first(), 'a@example.test', $applicationA, $applicationB], [$messages->last(), 'b@example.test', $applicationB, $applicationA]] as [$sent, $address, $own, $other]) {
            $this->assertSame($address, $sent->getEnvelope()->getRecipients()[0]->getAddress());
            $this->assertStringContainsString($own->request_number, $sent->getOriginalMessage()->getSubject());
            $this->assertStringNotContainsString($other->request_number, $sent->toString());
        }
    }

    public function test_administrators_hear_of_a_new_application_without_citizen_details(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'admin@example.test');
        $retired = $this->user('admin', 'retired@example.test', ['deactivated_at' => now()]);
        $citizen = $this->user('public', 'citizen@example.test', ['name' => 'Private Person']);
        $application = $this->application($citizen);

        app(StaffNotifier::class)->applicationSubmitted($application);

        Notification::assertSentTo($admin, PortalNotification::class, function ($n) use ($application) {
            return $n->title === 'New application received' && $n->body === $application->request_number
                && ! str_contains(json_encode($n), 'Private Person') && ! str_contains(json_encode($n), 'citizen@example.test');
        });
        Notification::assertNotSentTo($retired, PortalNotification::class);
        Notification::assertNotSentTo($citizen, PortalNotification::class);
    }

    public function test_a_submission_through_the_upload_route_reaches_administrators(): void
    {
        Notification::fake();
        Storage::fake('local');
        $admin = $this->user('admin', 'admin@example.test');
        $notary = $this->notary('notary@example.test');

        $this->actingAs($this->user('public', 'citizen@example.test'))->post('/user/upload', [
            'title' => 'A', 'document_type' => 'affidavit', 'description' => 'B',
            'file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertRedirect();

        Notification::assertSentToTimes($admin, PortalNotification::class, 1);
        Notification::assertNothingSentTo($notary);
    }

    public function test_a_citizen_resubmission_through_the_route_reaches_the_notary(): void
    {
        Notification::fake();
        $notary = $this->notary('notary@example.test');
        $citizen = $this->user('public', 'citizen@example.test');
        $application = $this->application($citizen, ['status' => NotaryRequest::STATUS_CORRECTION, 'notary_id' => $notary->id]);

        $this->actingAs($citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Added page'])->assertRedirect();

        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'Corrected application ready for review');
    }

    public function test_only_the_assigned_notary_is_told_of_an_assignment(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'admin@example.test');
        $assigned = $this->notary('assigned@example.test');
        $other = $this->notary('other@example.test');
        $citizen = $this->user('public', 'citizen@example.test');
        $application = $this->application($citizen);

        $this->actingAs($admin)->post(route('admin.requests.assign', $application->id), ['notary_id' => $assigned->id])->assertRedirect();

        Notification::assertSentTo($assigned, NotaryRequestNotification::class, fn ($n) => $n->event === 'assigned');
        Notification::assertNothingSentTo($other);
        Notification::assertSentTo($citizen, PortalNotification::class, fn ($n) => ! str_contains(json_encode($n), $assigned->name));
    }

    public function test_a_replaced_notary_is_told_and_nobody_else(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'admin@example.test');
        $first = $this->notary('first@example.test');
        $second = $this->notary('second@example.test');
        $third = $this->notary('third@example.test');
        $application = $this->application($this->user('public', 'citizen@example.test'), ['status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $first->id]);

        $this->actingAs($admin)->post(route('admin.requests.assign', $application->id), ['notary_id' => $second->id])->assertRedirect();

        Notification::assertSentTo($first, PortalNotification::class, fn ($n) => $n->title === 'Application reassigned');
        Notification::assertNothingSentTo($third);
    }

    public function test_a_corrected_application_goes_to_its_notary_only(): void
    {
        Notification::fake();
        $notary = $this->notary('notary@example.test');
        $other = $this->notary('other@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $application = $this->application($this->user('public', 'citizen@example.test'), ['notary_id' => $notary->id]);

        app(StaffNotifier::class)->correctionReturned($application);

        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'Corrected application ready for review');
        Notification::assertNothingSentTo($other);
        Notification::assertNothingSentTo($admin);
    }

    public function test_a_corrected_application_without_a_notary_goes_to_administrators(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'admin@example.test');

        app(StaffNotifier::class)->correctionReturned($this->application($this->user('public', 'citizen@example.test')));

        Notification::assertSentTo($admin, PortalNotification::class);
    }

    public function test_the_notary_hears_when_an_administrator_changes_their_application(): void
    {
        Notification::fake();
        $notary = $this->notary('notary@example.test');
        $other = $this->notary('other@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $application = $this->application($this->user('public', 'citizen@example.test'), ['status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $notary->id]);

        app(ApplicationDecisionService::class)->decide($admin, $application, 'reject', 'Reason given for the test');

        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'Application status updated');
        Notification::assertNothingSentTo($other);
    }

    public function test_a_notary_is_not_told_about_their_own_decision(): void
    {
        Notification::fake();
        $notary = $this->notary('notary@example.test');
        $application = $this->application($this->user('public', 'citizen@example.test'), ['status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $notary->id]);

        app(ApplicationDecisionService::class)->decide($notary, $application, 'reject', 'Reason given for the test');

        Notification::assertNotSentTo($notary, PortalNotification::class);
    }

    public function test_notary_registration_reaches_administrators_but_citizens_only_when_configured(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'admin@example.test');
        $notifier = app(StaffNotifier::class);

        $notifier->accountRegistered($this->user('public', 'one@example.test'));
        Notification::assertNothingSentTo($admin);

        $notifier->accountRegistered($this->notary('two@example.test'));
        Notification::assertSentToTimes($admin, PortalNotification::class, 1);

        config(['mail.admin_new_citizens' => true]);
        $notifier->accountRegistered($this->user('public', 'three@example.test'));
        Notification::assertSentToTimes($admin, PortalNotification::class, 2);
    }

    public function test_notary_approval_tells_the_notary_and_other_administrators_not_the_actor(): void
    {
        Notification::fake();
        $actor = $this->user('admin', 'actor@example.test');
        $colleague = $this->user('admin', 'colleague@example.test');
        $notary = $this->user('notary', 'notary@example.test');

        $this->actingAs($actor)->post(route('admin.notaries.approve', $notary))->assertRedirect();

        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'Notary account approved');
        Notification::assertSentTo($colleague, PortalNotification::class);
        Notification::assertNotSentTo($actor, PortalNotification::class);
    }

    public function test_an_appointment_reaches_only_the_notary_on_the_application_then_the_citizen(): void
    {
        Notification::fake();
        $notary = $this->notary('notary@example.test');
        $other = $this->notary('other@example.test');
        $citizen = $this->user('public', 'citizen@example.test');
        $application = $this->application($citizen, ['status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $notary->id]);

        $this->actingAs($citizen)->post('/appointments', ['request_id' => $application->id, 'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i'), 'mode' => 'video'])->assertRedirect();

        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'New appointment request');
        Notification::assertNothingSentTo($other);

        $appointment = Appointment::firstOrFail();
        $this->actingAs($notary)->patch(route('appointments.update', $appointment), ['status' => 'confirmed'])->assertRedirect();
        Notification::assertSentTo($citizen, PortalNotification::class, fn ($n) => $n->title === 'Appointment confirmed');

        $this->actingAs($citizen)->patch(route('appointments.update', $appointment), ['status' => 'cancelled'])->assertRedirect();
        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'Appointment cancelled');
    }

    public function test_a_security_incident_tells_the_other_administrators(): void
    {
        Notification::fake();
        $actor = $this->user('admin', 'actor@example.test');
        $colleague = $this->user('admin', 'colleague@example.test');
        $notary = $this->notary('notary@example.test');

        $this->actingAs($actor)->post(route('admin.security.incidents.store'), ['title' => 'Private detail', 'severity' => SecurityIncident::SEVERITIES[0], 'description' => 'Private detail'])->assertRedirect();

        Notification::assertSentTo($colleague, PortalNotification::class, fn ($n) => $n->title === 'Security incident recorded' && ! str_contains(json_encode($n), 'Private detail'));
        Notification::assertNotSentTo($actor, PortalNotification::class);
        Notification::assertNothingSentTo($notary);
    }

    // ---- The worker

    public function test_the_worker_sends_a_queued_email_and_queued_never_reads_as_sent(): void
    {
        $this->localGmail('citizen@example.test');
        $this->queued();
        $user = $this->user('public', 'citizen@example.test');

        $user->notify(new PortalNotification('Title', 'Body', 'http://example.test'));

        $this->assertSame(MessageDelivery::QUEUED, MessageDelivery::where('user_id', $user->id)->where('channel', 'mail')->value('status'));
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'local_gmail')->count());

        $this->work();

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('user_id', $user->id)->value('status'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_the_heartbeat_never_tells_the_worker_to_pause(): void
    {
        $looping = new Looping('database', 'local_gmail');

        $this->assertNotFalse(event($looping, halt: true));
        $this->assertNotFalse(event($looping, halt: true));
        $this->assertTrue(Cache::has(Platform::WORKER_HEARTBEAT));
    }

    public function test_the_worker_leaves_other_queues_alone(): void
    {
        $this->localGmail('citizen@example.test');
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);
        $user = $this->user('public', 'citizen@example.test');
        $user->notify(new PortalNotification('Title', 'Body', 'http://example.test'));

        $this->work();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_an_smtp_failure_is_visible_retried_three_times_and_recoverable(): void
    {
        $this->localGmail('citizen@example.test');
        $this->queued();
        $this->brokenMailer();
        $user = $this->user('public', 'citizen@example.test');
        $user->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        $row = fn () => MessageDelivery::where('user_id', $user->id)->first();

        $this->work();

        $this->assertSame(MessageDelivery::QUEUED, $row()->status);
        $this->assertSame('TransportException', $row()->error);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        Carbon::setTestNow(now()->addSeconds(31));
        $this->work();
        Carbon::setTestNow(now()->addSeconds(121));
        $this->work();

        $this->assertSame(MessageDelivery::FAILED, $row()->status);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());

        // No further attempt happens on its own.
        Carbon::setTestNow(now()->addHour());
        $this->work();
        $this->assertSame(MessageDelivery::FAILED, $row()->status);

        // An administrator's retry succeeds once the mail server is back.
        config(['mail.default' => 'array']);
        $this->freshMailer();

        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->work();

        $this->assertSame(MessageDelivery::ACCEPTED, $row()->status);
        $this->assertNull($row()->error);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Carbon::setTestNow();
    }

    public function test_a_redelivered_job_does_not_repeat_an_accepted_email(): void
    {
        $this->localGmail('citizen@example.test');
        $user = $this->user('public', 'citizen@example.test');
        $notification = new PortalNotification('Title', 'Body', 'http://example.test');
        $notification->id = 'e7f0a7a2-6c0b-4c5e-9a55-111111111111';

        $user->notifyNow($notification, ['mail']);
        $user->notifyNow($notification, ['mail']);

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(1, MessageDelivery::where('notification_id', $notification->id)->count());
    }

    // ---- Commands

    public function test_the_check_refuses_anything_but_the_isolated_setup(): void
    {
        $this->assertSame(1, Artisan::call('enotary:local-gmail-check', ['--json' => true]));

        $report = json_decode(Artisan::output(), true);

        $this->assertFalse($report['ok']);
        $this->assertContains('MAIL_MODE must resolve to local_gmail, which a log mailer or production prevents.', $report['failures']);
        $this->assertContains('The database must be enotary_gmail_test, and is not.', $report['failures']);
        $this->assertContains('No approved recipients are listed.', $report['failures']);
    }

    public function test_the_check_prints_no_secret_and_masks_recipients(): void
    {
        $this->localGmail('person@example.test');
        config(['mail.mailers.smtp.password' => 'app-password-value', 'mail.mailers.smtp.username' => 'sender@example.test']);

        Artisan::call('enotary:local-gmail-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('app-password-value', $output);
        $this->assertStringNotContainsString('person@example.test', $output);
        $this->assertStringContainsString('pe***@example.test', $output);
    }

    public function test_the_test_email_goes_once_to_an_approved_account_and_is_tried_once(): void
    {
        Notification::fake();
        $this->localGmail('owner@example.test');
        $owner = $this->user('public', 'owner@example.test');

        $this->assertSame(0, Artisan::call('enotary:local-gmail-test', ['user' => $owner->id]));
        $this->assertSame(1, Artisan::call('enotary:local-gmail-test', ['user' => $owner->id]));

        Notification::assertSentToTimes($owner, PortalNotification::class, 1);
        Notification::assertSentTo($owner, PortalNotification::class, fn ($n) => $n->tries === 1);
    }

    public function test_the_test_email_refuses_an_unapproved_account_and_other_modes(): void
    {
        Notification::fake();
        $user = $this->user('public', 'other@example.test');

        $this->assertSame(1, Artisan::call('enotary:local-gmail-test', ['user' => $user->id]));

        $this->localGmail('owner@example.test');
        $this->assertSame(1, Artisan::call('enotary:local-gmail-test', ['user' => $user->id]));

        Notification::assertNothingSent();
    }

    public function test_the_banner_names_the_mode(): void
    {
        $this->localGmail('a@example.test');

        // An Admin-only diagnostic: visitors never see it.
        $this->get('/')->assertOk()->assertDontSee('Local Gmail:');
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'banner.admin@example.test']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Local Gmail: real email goes only to approved local test accounts.');
    }

    public function test_the_resend_cooldown_still_applies_in_local_gmail(): void
    {
        Notification::fake();
        $this->localGmail('citizen@example.test');
        $user = $this->user('public', 'citizen@example.test', ['email_verified_at' => null]);
        $send = fn () => $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'));

        $send()->assertRedirect();
        Notification::assertSentToTimes($user, \App\Notifications\VerifyEmailNotification::class, 1);
        $send()->assertSessionHas('error', \App\Http\Controllers\Auth\EmailVerificationController::RESEND_WAIT);
        Notification::assertSentToTimes($user, \App\Notifications\VerifyEmailNotification::class, 1);
    }

    public function test_local_gmail_resolves_the_real_address_of_every_role(): void
    {
        $this->localGmail('citizen@example.test,notary@example.test,admin@example.test');
        // An Admin address also needs the owner's confirmation for the run (-ConfirmRecipient).
        // LocalGmailSafetyTest proves it is refused without one.
        config(['services.outbound.mail_confirmed' => 'admin@example.test']);

        foreach ([['public', 'citizen@example.test'], ['notary', 'notary@example.test'], ['admin', 'admin@example.test']] as [$role, $address]) {
            $this->user($role, $address)->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        }

        $recipients = app('mailer')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress())->all();

        $this->assertSame(['citizen@example.test', 'notary@example.test', 'admin@example.test'], array_values($recipients));
    }
}
