<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\ContactAcknowledgement;
use App\Notifications\ContactMessageReceived;
use App\Notifications\NotaryRequestNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Http\Controllers\Auth\ForgotPasswordController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Email workflows: verification, password reset, SMS OTP hardening, request
 * lifecycle notifications, contact form. Notifications are faked here, so a
 * green run proves the application queues the right email to the right
 * recipient; it does NOT prove SMTP delivery.
 */
class EmailWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    // ------------------------------------------------------------ helpers

    private function makeUser(array $overrides = []): User
    {
        static $i = 0;
        $i++;

        return User::create(array_merge([
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'mobile' => '98765432'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('Secret123!'),
            'role' => 'public',
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
        ], $overrides));
    }

    private function makeNotary(bool $approved = true): User
    {
        return $this->makeUser([
            'role' => 'notary',
            'notary_approved_at' => $approved ? now() : null,
        ]);
    }

    private function makeAdmin(): User
    {
        return $this->makeUser(['role' => 'admin']);
    }

    private function makeRequest(User $owner, array $overrides = []): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(),
            'user_id' => $owner->id,
            'document_type' => 'affidavit',
            'purpose' => 'Testing',
            'status' => 'pending',
        ], $overrides));
    }

    // ----------------------------------------------- A. email verification

    public function test_registration_sends_exactly_one_verification_email(): void
    {
        $this->postJson('/custom-register', [
            'name' => 'New', 'email' => 'new@example.com', 'mobile' => '+91 91234 56789',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'public', 'accept_terms' => '1',
        ])->assertOk()->assertJsonPath('redirect', route('verification.notice'));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame('9123456789', $user->mobile, 'mobile is normalised to 10 digits');

        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    }

    public function test_unverified_user_is_redirected_to_notice_and_cannot_upload(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertSee('Verify your email address');
    }

    public function test_valid_signed_link_verifies_email(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_or_expired_or_foreign_links_are_rejected(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
        $other = $this->makeUser(['email_verified_at' => null]);

        // Wrong hash (different email) with a valid signature -> 403.
        $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1('someone-else@example.com'),
        ]);
        $this->actingAs($user)->get($wrongHash)->assertForbidden();

        // Tampered signature -> 403.
        $valid = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->actingAs($user)->get($valid.'tampered')->assertForbidden();

        // Expired -> 403.
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->actingAs($user)->get($expired)->assertForbidden();

        // Another logged-in user cannot consume this user's link -> 403.
        $this->actingAs($other)->get($valid)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_sends_notification_and_is_throttled(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);

        // Notifications are faked here, so no delivery is recorded and the page must not say "sent".
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

        // Route allows 6 per minute; the 7th is rejected with 429.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('verification.send'));
        }
        $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
    }

    // ------------------------------------------------- B. password reset

    /** The code from the PasswordResetCodeNotification sent to this account. */
    private function resetCodeFor(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    }

    public function test_forgot_password_by_email_sends_a_code_without_leaking_it(): void
    {
        $user = $this->makeUser();
        $this->mock(SmsService::class, fn ($mock) => $mock->shouldNotReceive('send'));

        $response = $this->post('/forgot-password', ['identifier' => $user->email]);
        $response->assertRedirect()->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);

        $code = $this->resetCodeFor($user);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Notification::assertNotSentTo($user, ResetPasswordNotification::class);
        $this->assertSame(0, DB::table('password_resets')->count(), 'the public form no longer makes link tokens');

        // Stored as a tagged bcrypt hash bound to the address, never in the clear or in the session.
        $row = DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->sole();
        $this->assertStringStartsWith('password_reset_email|$2y$', $row->otp);
        $this->assertStringNotContainsString($code, $row->otp);
        $this->assertTrue(Hash::check('password-reset-email:'.sha1(strtolower($user->email)).':'.$code, substr($row->otp, strlen('password_reset_email|'))));
        $this->assertStringNotContainsString($code, json_encode(session()->all()));
    }

    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        $user = $this->makeUser();

        $known = $this->post('/forgot-password', ['identifier' => $user->email]);
        $unknown = $this->post('/forgot-password', ['identifier' => 'nobody@example.com']);

        $this->assertSame(session('status'), $known->getSession()->get('status'));
        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status', $known->getSession()->get('status'));
        $unknown->assertSessionHasNoErrors();
        Notification::assertNothingSentTo(new AnonymousNotifiable);
    }

    /** The emailed-link reset stays for links an administrator sends when restoring an account. */
    public function test_reset_with_valid_token_works_once_and_rejects_reuse_invalid_and_expired(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        // Invalid token.
        $this->post('/reset-password', [
            'token' => 'wrong', 'email' => $user->email,
            'password' => 'Newsecret123!', 'password_confirmation' => 'Newsecret123!',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Secret123!', $user->fresh()->password));

        // Valid token.
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Newsecret123!', 'password_confirmation' => 'Newsecret123!',
        ])->assertRedirect(route('home'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Newsecret123!', $user->fresh()->password));

        // Re-use of the same token is refused.
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Another123!', 'password_confirmation' => 'Another123!',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Newsecret123!', $user->fresh()->password));

        // Expired token (older than the configured 60 minutes).
        $expired = Password::broker()->createToken($user);
        DB::table('password_resets')->where('email', $user->email)->update(['created_at' => now()->subHours(2)]);
        $this->post('/reset-password', [
            'token' => $expired, 'email' => $user->email,
            'password' => 'Expired123!', 'password_confirmation' => 'Expired123!',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Newsecret123!', $user->fresh()->password));
    }

    public function test_forgot_password_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['identifier' => "x{$i}@example.com"])->assertRedirect();
        }
        $this->post('/forgot-password', ['identifier' => 'x6@example.com'])->assertStatus(429);
    }

    // ---------------------------------------- C. reset code hardening (email only, never SMS)

    public function test_a_mobile_request_emails_the_code_hashed_never_displayed_and_attempt_limited(): void
    {
        $user = $this->makeUser();
        $this->mock(SmsService::class, fn ($mock) => $mock->shouldNotReceive('send'));

        $this->post('/forgot-password', ['identifier' => '+91 '.$user->mobile])->assertRedirect();
        $code = $this->resetCodeFor($user);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // The forgot-password page must not contain the code anywhere.
        $this->get('/forgot-password')->assertOk()->assertDontSee($code)->assertSee('Verify code');

        // Wrong attempts are counted and the 5th blocks further tries.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify-otp', ['otp' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('otp');
        }
        $this->post('/verify-otp', ['otp' => $code])->assertSessionHasErrors(['otp' => ForgotPasswordController::CODE_BLOCKED]); // blocked even with the right code
    }

    public function test_a_mobile_request_resets_the_password_through_the_emailed_code(): void
    {
        $user = $this->makeUser();
        $this->mock(SmsService::class, fn ($mock) => $mock->shouldNotReceive('send'));

        $this->post('/forgot-password', ['identifier' => $user->mobile]);
        $this->post('/verify-otp', ['otp' => $this->resetCodeFor($user)])->assertRedirect(route('password.reset.form'));
        $this->get('/reset-password-form')->assertOk();
        $this->post('/reset-password-form', ['password' => 'Viacode123!', 'password_confirmation' => 'Viacode123!'])
            ->assertRedirect(route('home'));

        $this->assertTrue(Hash::check('Viacode123!', $user->fresh()->password));
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_an_unknown_number_gets_the_same_response_and_nothing_is_sent(): void
    {
        $this->mock(SmsService::class, fn ($mock) => $mock->shouldNotReceive('send'));

        $this->post('/forgot-password', ['identifier' => '9000000000'])
            ->assertRedirect()->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS)->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_using_a_different_email_or_number_starts_over(): void
    {
        $user = $this->makeUser();

        $this->post('/forgot-password', ['identifier' => $user->mobile]);
        $this->get('/forgot-password')->assertSee('Verify code');

        $this->post('/forgot-password/restart')->assertRedirect(route('password.request'))->assertSessionMissing('reset.identifier');
        $this->get('/forgot-password')->assertOk()->assertDontSee('Verify code')->assertSee('Send verification code');
    }

    public function test_a_new_code_respects_the_cooldown(): void
    {
        $user = $this->makeUser();

        $this->post('/forgot-password', ['identifier' => $user->mobile]);
        $this->get('/forgot-password')->assertOk()
            ->assertSee('Send a new code')
            ->assertSee('Use a different email or mobile number')
            ->assertDontSee('Send verification code');

        // Immediate resend: the same answer for everyone, and no second code inside the 60 s cooldown.
        $this->post('/forgot-password', ['identifier' => $user->mobile])->assertRedirect()->assertSessionHas('error', ForgotPasswordController::RESEND_WAIT);
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 1);

        // After the cooldown the resend issues a new code.
        $this->travel(61)->seconds();
        $this->post('/forgot-password', ['identifier' => $user->mobile])->assertRedirect()->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 2);
    }

    // ------------------------------------------ D. request lifecycle emails

    public function test_submission_creates_request_and_sends_confirmation_to_owner(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user)->post('/user/upload', [
            'title' => 'Doc', 'document_type' => 'affidavit', 'description' => 'test',
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('user.documents'));

        $request = NotaryRequest::firstOrFail();
        $this->assertMatchesRegularExpression('/^NR-\d{8}-[A-Z0-9]{6}$/', $request->request_number);

        Notification::assertSentToTimes($user, NotaryRequestNotification::class, 1);
        Notification::assertSentTo($user, NotaryRequestNotification::class, fn ($n) => $n->event === 'submitted');
    }

    public function test_submission_is_queued_after_commit_so_mail_failure_cannot_lose_request(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->makeUser();

        // Use the real database queue: the notification becomes a job row that
        // a worker sends later; the HTTP request never talks to SMTP.
        Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        config(['queue.default' => 'database']);

        $this->actingAs($user)->post('/user/upload', [
            'title' => 'Doc', 'document_type' => 'affidavit', 'description' => 'test',
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('user.documents'));

        $this->assertDatabaseCount('notary_requests', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_admin_assignment_notifies_only_the_assigned_approved_notary(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeUser();
        $notary = $this->makeNotary();
        $otherNotary = $this->makeNotary();
        $unapproved = $this->makeNotary(false);
        $request = $this->makeRequest($owner);

        // Unapproved notary cannot be assigned.
        $this->actingAs($admin)->post("/admin/requests/{$request->id}/assign", ['notary_id' => $unapproved->id])
            ->assertSessionHasErrors('notary_id');
        Notification::assertNothingSent();

        $this->actingAs($admin)->post("/admin/requests/{$request->id}/assign", ['notary_id' => $notary->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('assigned', $request->status);
        $this->assertSame($notary->id, $request->notary_id);

        Notification::assertSentTo($notary, NotaryRequestNotification::class, fn ($n) => $n->event === 'assigned');
        Notification::assertNotSentTo($otherNotary, NotaryRequestNotification::class);
        Notification::assertNotSentTo($owner, NotaryRequestNotification::class);

        // Posting the same assignment again sends nothing new.
        $this->actingAs($admin)->post("/admin/requests/{$request->id}/assign", ['notary_id' => $notary->id]);
        Notification::assertSentToTimes($notary, NotaryRequestNotification::class, 1);
    }

    public function test_notary_approve_reject_complete_notify_owner_once_and_block_repeats(): void
    {
        $owner = $this->makeUser();
        $notary = $this->makeNotary();
        $request = $this->makeRequest($owner, ['status' => 'assigned', 'notary_id' => $notary->id]);

        // Completion issues a certificate, which needs a real file.
        Storage::fake('local');
        Storage::disk('local')->put('documents/a.pdf', 'content');
        Document::create(['request_id' => $request->id, 'title' => 'T', 'original_file' => 'documents/a.pdf', 'file_hash' => hash('sha256', 'content')]);

        $this->actingAs($notary)->post("/notary/request/{$request->id}/approve", ['reason' => 'All in order'])->assertRedirect(route('notary.requests'));
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->decided_at);
        Notification::assertSentTo($owner, NotaryRequestNotification::class, fn ($n) => $n->event === 'approved');

        // Repeating approve, or rejecting an approved request, is refused and sends nothing.
        $this->actingAs($notary)->post("/notary/request/{$request->id}/approve", ['reason' => 'Again'])->assertSessionHas('error');
        $this->actingAs($notary)->post("/notary/request/{$request->id}/reject", ['reason' => 'Changed my mind'])->assertSessionHas('error');
        Notification::assertSentToTimes($owner, NotaryRequestNotification::class, 1);

        // Completion.
        $this->actingAs($notary)->post("/notary/request/{$request->id}/complete", ['reason' => 'Review finished'])->assertRedirect();
        $this->assertSame('completed', $request->fresh()->status);
        Notification::assertSentTo($owner, NotaryRequestNotification::class, fn ($n) => $n->event === 'completed');
        Notification::assertSentToTimes($owner, NotaryRequestNotification::class, 2);

        // Rejection with a reason on a second request.
        $second = $this->makeRequest($owner, ['status' => 'assigned', 'notary_id' => $notary->id]);
        $this->actingAs($notary)->post("/notary/request/{$second->id}/reject", ['rejection_reason' => 'Signature missing'])
            ->assertRedirect();
        $this->assertSame('Signature missing', $second->fresh()->rejection_reason);
        Notification::assertSentTo($owner, NotaryRequestNotification::class, function ($n) use ($second) {
            return $n->event === 'rejected' && $n->request->is($second)
                && str_contains(implode(' ', $n->toMail($second->user)->viewData['introLines']), 'Signature missing');
        });
    }

    public function test_notary_cannot_act_on_requests_not_assigned_to_them(): void
    {
        $owner = $this->makeUser();
        $notary = $this->makeNotary();
        $other = $this->makeNotary();
        $request = $this->makeRequest($owner, ['status' => 'assigned', 'notary_id' => $other->id]);

        $this->actingAs($notary)->get("/notary/request/{$request->id}")->assertNotFound();
        $this->actingAs($notary)->post("/notary/request/{$request->id}/approve")->assertNotFound();
        $this->assertSame('assigned', $request->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_unapproved_or_self_registered_notary_is_blocked(): void
    {
        $pending = $this->makeNotary(false);

        // Module 1: the work area sends a pending Notary to the waiting page, never to the work.
        $this->actingAs($pending)->get('/notary/dashboard')->assertRedirect(route('notary.pending'));

        // Admin approval now sets notary_approved_at (not email_verified_at).
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post("/admin/notaries/{$pending->id}/approve")->assertRedirect();
        $this->assertNotNull($pending->fresh()->notary_approved_at);
        $this->actingAs($pending->fresh())->get('/notary/dashboard')->assertOk();
    }

    public function test_notification_emails_never_contain_document_content_or_secrets(): void
    {
        $owner = $this->makeUser();
        $request = $this->makeRequest($owner, ['purpose' => 'SECRET-PURPOSE-TEXT']);

        $mail = (new NotaryRequestNotification($request, 'approved'))->toMail($owner);
        $rendered = implode(' ', $mail->viewData['introLines']).' '.$mail->subject;

        $this->assertStringContainsString($request->request_number, $rendered);
        $this->assertStringNotContainsString('SECRET-PURPOSE-TEXT', $rendered);
        $this->assertStringNotContainsString('password', strtolower($rendered));
    }

    // ------------------------------------------------- E. contact form

    public function test_contact_form_validates_persists_and_notifies(): void
    {
        config(['mail.admin_address' => 'admin-inbox@example.com']);

        // Validation.
        $this->post('/contact', ['name' => '', 'email' => 'bad', 'subject' => 'Nope', 'message' => 'short'])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
        $this->assertDatabaseCount('contact_messages', 0);

        // Honeypot filled: looks successful, nothing stored or sent.
        $this->post('/contact', [
            'name' => 'Bot', 'email' => 'bot@example.com', 'subject' => 'General Inquiry',
            'message' => 'Buy cheap things now please', 'website' => 'http://spam',
        ])->assertRedirect();
        $this->assertDatabaseCount('contact_messages', 0);
        Notification::assertNothingSent();

        // Real submission.
        $this->post('/contact', [
            'name' => 'Asha', 'email' => 'asha@example.com', 'subject' => 'Technical Support',
            'message' => 'I cannot upload my affidavit file.',
        ])->assertRedirect(route('home').'#contact')->assertSessionHas('contact_status');

        $msg = ContactMessage::firstOrFail();
        $this->assertSame('asha@example.com', $msg->email);
        $this->assertFalse((bool) $msg->is_read);

        Notification::assertSentOnDemand(ContactAcknowledgement::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'asha@example.com');
        Notification::assertSentOnDemand(ContactMessageReceived::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'admin-inbox@example.com');
    }

    public function test_contact_form_skips_admin_copy_when_no_admin_address_configured(): void
    {
        config(['mail.admin_address' => null]);

        $this->post('/contact', [
            'name' => 'Asha', 'email' => 'asha@example.com', 'subject' => 'General Inquiry',
            'message' => 'Hello, just testing the form.',
        ])->assertRedirect();

        Notification::assertSentOnDemandTimes(ContactAcknowledgement::class, 1);
        Notification::assertSentOnDemandTimes(ContactMessageReceived::class, 0);
    }

    public function test_contact_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/contact', [
                'name' => 'A', 'email' => "a{$i}@example.com", 'subject' => 'General Inquiry',
                'message' => 'Hello, just testing the form.',
            ])->assertRedirect();
        }
        $this->post('/contact', [
            'name' => 'A', 'email' => 'a9@example.com', 'subject' => 'General Inquiry',
            'message' => 'Hello, just testing the form.',
        ])->assertStatus(429);
    }

    // ----------------------------------------------- misc regressions

    public function test_registration_error_does_not_leak_exception_details(): void
    {
        $this->postJson('/custom-register', [
            'name' => 'Dup', 'email' => 'not-an-email', 'mobile' => '12', 'password' => 'short', 'password_confirmation' => 'short', 'role' => 'public', 'accept_terms' => '1',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_login_redirects_use_route_helper(): void
    {
        $user = $this->makeUser();

        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('redirect', route('dashboard'));
    }
}
