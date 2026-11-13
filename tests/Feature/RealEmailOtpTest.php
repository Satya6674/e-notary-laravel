<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\EmailVerificationController as Verify;
use App\Http\Controllers\Auth\ForgotPasswordController as Forgot;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\OtpService;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Real email OTP for registration and Forgot Password (approved 2026-11-08), with Live Mail on and the
 * Citizen mobile step off, as the application is configured by default.
 *
 * Nothing leaves this process: "capture" keeps messages in memory and "failing" throws. Live Mail
 * treats both as real transports, so every Live Mail rule applies. The SMTP mailer points at a closed
 * local port with dummy values. No SMS can be attempted: there is no MSG91 key and stray HTTP is refused.
 */
class RealEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    private const CITIZEN = 'citizen@example.test';

    private const PASSWORD = 'An-old-good-password1';

    private ArrayTransport $captured;

    private int $mobile = 9100000001;

    /** @var list<string> */
    private array $logged = [];

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
            'accounts.citizen_mobile_verification' => false,
            'mail.live_enabled' => true,
            'mail.default' => 'capture',
            'mail.mailers.capture' => ['transport' => 'capture'],
            'mail.mailers.failing' => ['transport' => 'failing'],
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'username' => 'dummy', 'password' => 'Dummy-password1', 'timeout' => 2],
            'mail.from.address' => 'sender@example.test',
            'services.outbound.mail' => self::CITIZEN.',notary@example.test,admin@example.test,unverified@example.test,inactive@example.test',
            'app.url' => 'http://127.0.0.1:8010',
        ]);
        app('mail.manager')->forgetMailers();

        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.' '.json_encode($e->context));
    }

    // ---------------------------------------------------------------- helpers

    private function register(string $email = self::CITIZEN, string $role = 'public')
    {
        Auth::logout();

        return $this->postJson(route('register.submit'), [
            'name' => 'Real Email Tester', 'email' => $email, 'mobile' => (string) $this->mobile++,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => $role, 'accept_terms' => '1',
        ]);
    }

    /** @return list<\Symfony\Component\Mailer\SentMessage> */
    private function messages(): array
    {
        return array_values(iterator_to_array($this->captured->messages()));
    }

    private function bodyOf(int $index): string
    {
        $email = $this->messages()[$index]->getOriginalMessage();

        return html_entity_decode((string) $email->getHtmlBody()).' '.$email->getTextBody();
    }

    private function codeIn(int $index): string
    {
        $this->assertSame(1, preg_match('~verification code is (\d{6})~', $this->bodyOf($index), $m), 'no code in the email');

        return $m[1];
    }

    private function lastCode(): string
    {
        return $this->codeIn(count($this->messages()) - 1);
    }

    private function citizen(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'email' => self::CITIZEN, 'mobile' => '9200000001', 'role' => 'public',
            'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(),
        ]);
    }

    private function askForCode(string $identifier)
    {
        return $this->from(route('password.request'))->post(route('password.email'), ['identifier' => $identifier]);
    }

    private function submitCode(string $code)
    {
        return $this->from(route('password.request'))->post(route('password.verify.otp'), ['otp' => $code]);
    }

    private function wrongCode(string $right): string
    {
        return $right === '000000' ? '111111' : '000000';
    }

    // ---------------------------------------------------------------- registration

    public function test_registration_sends_one_otp_only_email_and_says_sent_only_after_acceptance(): void
    {
        $this->register()->assertOk()->assertJsonPath('message', RegisterController::CODE_SENT);

        $this->assertCount(1, $this->messages());
        $this->assertSame([self::CITIZEN], array_map(fn ($a) => $a->getAddress(), $this->messages()[0]->getEnvelope()->getRecipients()));
        $this->assertSame('Your E-Notary verification code', $this->messages()[0]->getOriginalMessage()->getSubject());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->codeIn(0));
        $this->assertStringNotContainsString('/email/verify/', $this->bodyOf(0), 'no verification link in the email');
        $this->assertStringNotContainsString('signature=', $this->bodyOf(0));

        $user = User::where('email', self::CITIZEN)->sole();
        $this->assertFalse($user->hasVerifiedEmail(), 'the account stays unverified until the code is entered');
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('user_id', $user->id)->value('status'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringNotContainsString($this->codeIn(0), (string) DB::table('otp_verifications')->value('otp'), 'only a hash is stored');

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee('A verification code has been sent to your registered email address.')
            ->assertDontSee('Safe Preview')->assertDontSee('preview')->assertDontSee('test copy');
    }

    public function test_the_code_completes_citizen_registration_without_any_mobile_step(): void
    {
        $this->register();
        $user = User::where('email', self::CITIZEN)->sole();
        $code = $this->codeIn(0);

        // Unverified: the citizen area stays closed.
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->post(route('verification.code'), ['code' => $code])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(User::METHOD_EMAIL_CODE, $user->email_verification_method);
        $this->assertNull($user->mobile_verified_at, 'the number is stored but not proven');
        $this->assertNotNull($user->mobile);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // Used once: the same code cannot be used again (the account is verified, and the row is gone).
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->count());
    }

    public function test_a_notary_still_waits_for_admin_approval_after_the_code(): void
    {
        $this->register('notary@example.test', 'notary');
        $notary = User::where('email', 'notary@example.test')->sole();

        $this->actingAs($notary)->post(route('verification.code'), ['code' => $this->codeIn(0)])->assertRedirect(route('notary.pending'));
        $this->assertTrue($notary->fresh()->hasVerifiedEmail());
        $this->assertNull($notary->fresh()->notary_approved_at);
        $this->actingAs($notary)->get(route('notary.dashboard'))->assertRedirect(route('notary.pending'));
    }

    public function test_invalid_expired_superseded_and_too_many_codes_are_refused_with_clear_messages(): void
    {
        $this->register();
        $user = User::where('email', self::CITIZEN)->sole();
        $first = $this->codeIn(0);
        $submit = fn (string $code) => $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.code'), ['code' => $code]);

        $submit($this->wrongCode($first))->assertSessionHasErrors(['code' => Verify::CODE_INVALID]);

        // Superseded by a new code: the old one no longer works.
        $this->travel(61)->seconds();
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))->assertSessionHas('status', Verify::CODE_SENT);
        $second = $this->lastCode();
        $submit($first === $second ? $this->wrongCode($second) : $first)->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        // Expired after 10 minutes.
        $this->travel(11)->minutes();
        $submit($second)->assertSessionHasErrors(['code' => Verify::CODE_EXPIRED]);

        // Five wrong attempts lock the code, even the right one.
        $this->travel(1)->minutes();
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'));
        $third = $this->lastCode();
        foreach (range(1, OtpService::MAX_ATTEMPTS) as $attempt) {
            $submit($this->wrongCode($third));
        }
        $submit($third)->assertSessionHasErrors(['code' => Verify::CODE_BLOCKED]);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_waits_sixty_seconds(): void
    {
        $this->register();
        $user = User::where('email', self::CITIZEN)->sole();
        $resend = fn () => $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'));

        $resend()->assertSessionHas('error', Verify::RESEND_WAIT);
        $this->assertCount(1, $this->messages());

        $this->travel(61)->seconds();
        $resend()->assertSessionHas('status', Verify::CODE_SENT);
        $this->assertCount(2, $this->messages());
    }

    public function test_a_delivery_failure_is_reported_truthfully_and_its_code_is_cancelled(): void
    {
        config(['mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();

        $this->register()->assertOk()->assertJsonPath('message', RegisterController::CODE_NOT_SENT);

        $user = User::where('email', self::CITIZEN)->sole();
        $this->assertSame(MessageDelivery::FAILED, MessageDelivery::where('user_id', $user->id)->value('status'));
        $row = DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->sole();
        $this->assertTrue(now()->greaterThanOrEqualTo($row->expires_at), 'a code nobody received cannot be used');

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee("We couldn't send your verification code. Please try again later.")
            ->assertDontSee('has been sent');

        $this->travel(61)->seconds();
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))->assertSessionHas('error', Verify::CODE_NOT_SENT);
    }

    public function test_safe_preview_never_claims_an_email_was_sent_and_shows_no_preview_wording_to_citizens(): void
    {
        config(['mail.live_enabled' => false, 'mail.default' => 'log']);
        app('mail.manager')->forgetMailers();
        $this->assertSame('safe_preview', Platform::mailMode());

        $this->register()->assertOk()->assertJsonPath('message', RegisterController::CODE_NOT_SENT);
        $user = User::where('email', self::CITIZEN)->sole();
        $this->assertSame(MessageDelivery::SIMULATED, MessageDelivery::where('user_id', $user->id)->value('status'));

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee("We couldn't send your verification code. Please try again later.")
            ->assertDontSee('Safe Preview')->assertDontSee('simulated')->assertDontSee('preview only');
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))->assertSessionHas('error', Verify::CODE_NOT_SENT);

        Auth::logout();
        $this->get(route('password.request'))->assertOk()->assertDontSee('Safe Preview')->assertDontSee('test copy')->assertDontSee('SMS');
        $this->get(route('home'))->assertOk()->assertDontSee('Safe Preview: no real email');

        // The internal diagnostic is still there for an administrator.
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Safe Preview: no real email or SMS is sent.');
    }

    public function test_an_old_simulated_record_from_safe_preview_is_not_shown_as_a_failure_under_live_mail(): void
    {
        // Registered while the site was in Safe Preview: its only record says "simulated".
        $user = User::factory()->create(['email' => self::CITIZEN, 'email_verified_at' => null, 'role' => 'public']);
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'ci***@example.test', 'status' => MessageDelivery::SIMULATED, 'user_id' => $user->id]);
        $this->assertSame('live_test', Platform::mailMode());

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertDontSee("We couldn't send your verification code. Please try again later.")
            ->assertDontSee('has been sent')
            ->assertSee('Enter the 6-digit code from the email to complete your registration.')
            ->assertSee('Send a new code');

        // Back in Safe Preview, the same record is a real "not sent".
        config(['mail.live_enabled' => false, 'mail.default' => 'log']);
        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee("We couldn't send your verification code. Please try again later.");
    }

    public static function failuresUnderLiveMail(): array
    {
        return ['failed' => [MessageDelivery::FAILED], 'blocked' => [MessageDelivery::BLOCKED]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failuresUnderLiveMail')]
    public function test_a_failed_or_blocked_record_is_still_shown_under_live_mail(string $status): void
    {
        $user = User::factory()->create(['email' => self::CITIZEN, 'email_verified_at' => null, 'role' => 'public']);
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'ci***@example.test', 'status' => $status, 'user_id' => $user->id]);

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee("We couldn't send your verification code. Please try again later.");
    }

    // ---------------------------------------------------------------- forgot password

    public function test_forgot_password_by_email_sends_an_otp_only_code_and_resets_the_password(): void
    {
        $user = $this->citizen();

        $this->askForCode(self::CITIZEN)->assertRedirect(route('password.request'))->assertSessionHas('status', Forgot::GENERIC_STATUS);

        $this->assertCount(1, $this->messages());
        $this->assertSame('Your E-Notary password reset code', $this->messages()[0]->getOriginalMessage()->getSubject());
        $this->assertStringNotContainsString('reset-password', $this->bodyOf(0), 'no reset link in the email');
        $code = $this->codeIn(0);
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'password_reset_code'])->value('status'));

        // The code screen shows; the reset form does not open before the code.
        $this->get(route('password.request'))->assertOk()->assertSee('Enter the 6-digit verification code', false);
        $this->get(route('password.reset.form'))->assertRedirect(route('password.request'));
        $this->post(route('password.reset.submit'), ['password' => 'A-brand-new-password1', 'password_confirmation' => 'A-brand-new-password1'])->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        $this->submitCode($code)->assertRedirect(route('password.reset.form'));
        $this->get(route('password.reset.form'))->assertOk();
        $this->post(route('password.reset.submit'), ['password' => 'A-brand-new-password1', 'password_confirmation' => 'A-brand-new-password1'])
            ->assertRedirect(route('home'))->assertSessionHas('status', Forgot::RESET_DONE);

        $this->assertTrue(Hash::check('A-brand-new-password1', $user->fresh()->password));
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->count(), 'the code is used up');

        // Login stays password-only.
        $this->postJson(route('login.submit'), ['mobile' => self::CITIZEN, 'password' => 'A-brand-new-password1'])->assertOk()->assertJson(['success' => true]);
    }

    public function test_forgot_password_by_mobile_sends_the_code_to_the_linked_email_and_never_sms(): void
    {
        $user = $this->citizen(['mobile' => '9300000001']);

        $this->askForCode('+91 93000 00001')->assertSessionHas('status', Forgot::GENERIC_STATUS);

        $this->assertCount(1, $this->messages());
        $this->assertSame([self::CITIZEN], array_map(fn ($a) => $a->getAddress(), $this->messages()[0]->getEnvelope()->getRecipients()));
        $this->assertSame('off', Platform::smsMode(), 'no SMS provider is configured');

        $this->submitCode($this->codeIn(0))->assertRedirect(route('password.reset.form'));
        $this->post(route('password.reset.submit'), ['password' => 'A-brand-new-password1', 'password_confirmation' => 'A-brand-new-password1'])->assertRedirect(route('home'));
        $this->assertTrue(Hash::check('A-brand-new-password1', $user->fresh()->password));
    }

    public function test_an_unknown_account_gets_exactly_the_same_answers_as_a_real_one(): void
    {
        $this->citizen();

        $run = function (string $identifier) {
            $answers = [];
            $answers[] = $this->askForCode($identifier)->baseResponse->headers->get('Location').'|'.session('status');
            $answers[] = $this->submitCode('000001')->baseResponse->headers->get('Location').'|'.session('errors')?->first('otp');
            $answers[] = $this->askForCode($identifier)->baseResponse->headers->get('Location').'|'.session('error');
            $this->travel(11)->minutes();
            $answers[] = $this->submitCode('000002')->baseResponse->headers->get('Location').'|'.session('errors')?->first('otp');
            $this->travelBack();
            foreach (range(1, 4) as $i) {
                $this->submitCode('00000'.(3 + $i));
            }
            $answers[] = session('errors')?->first('otp');
            $this->post(route('password.otp.restart'));

            return $answers;
        };

        $real = $run(self::CITIZEN);
        $sentForReal = count($this->messages());
        $unknown = $run('nobody@example.test');
        $unknownMobile = $run('9999999999');

        $this->assertSame($real, $unknown);
        $this->assertSame($real, $unknownMobile);
        $this->assertSame([Forgot::CODE_INVALID, Forgot::CODE_EXPIRED, Forgot::CODE_BLOCKED], [
            explode('|', $real[1])[1], explode('|', $real[3])[1], $real[4],
        ]);
        $this->assertSame(1, $sentForReal);
        $this->assertCount(1, $this->messages(), 'nothing is sent for an unknown address or number');
    }

    public function test_ineligible_accounts_get_no_code_and_the_same_generic_answer(): void
    {
        User::factory()->create(['email' => 'unverified@example.test', 'mobile' => '9400000001', 'email_verified_at' => null]);
        User::factory()->create(['email' => 'inactive@example.test', 'mobile' => '9400000002', 'email_verified_at' => now()])->forceFill(['deactivated_at' => now()])->save();
        User::factory()->create(['email' => 'admin@example.test', 'mobile' => '9400000003', 'role' => 'admin', 'email_verified_at' => now()]);

        foreach (['unverified@example.test', 'inactive@example.test', '9400000003'] as $identifier) {
            $this->askForCode($identifier)->assertSessionHas('status', Forgot::GENERIC_STATUS);
            $this->post(route('password.otp.restart'));
        }
        $this->assertCount(0, $this->messages());

        // An administrator may reset by typing the email address.
        $this->askForCode('admin@example.test')->assertSessionHas('status', Forgot::GENERIC_STATUS);
        $this->assertCount(1, $this->messages());
    }

    public function test_the_reset_needs_a_fresh_proof_and_wrong_codes_are_limited(): void
    {
        $user = $this->citizen();
        $this->askForCode(self::CITIZEN);
        $code = $this->codeIn(0);

        $this->submitCode($this->wrongCode($code))->assertSessionHasErrors(['otp' => Forgot::CODE_INVALID]);
        $this->submitCode($code)->assertRedirect(route('password.reset.form'));

        // The proof is good for 10 minutes only.
        $this->travel(11)->minutes();
        $this->post(route('password.reset.submit'), ['password' => 'A-brand-new-password1', 'password_confirmation' => 'A-brand-new-password1'])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors(['identifier' => Forgot::SESSION_EXPIRED]);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_failed_reset_email_leaves_no_usable_code(): void
    {
        $user = $this->citizen();
        config(['mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();

        $this->askForCode(self::CITIZEN)->assertSessionHas('status', Forgot::GENERIC_STATUS);

        $this->assertSame(MessageDelivery::FAILED, MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'password_reset_code'])->value('status'));
        $row = DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->sole();
        $this->assertTrue(now()->greaterThanOrEqualTo($row->expires_at));
    }

    // ---------------------------------------------------------------- policy and secrets

    public function test_both_otp_emails_are_live_types_and_the_reset_is_followed_by_its_security_alert(): void
    {
        $this->assertContains(VerifyEmailNotification::class, Platform::LIVE_MAIL_NOTIFICATIONS);
        $this->assertContains(PasswordResetCodeNotification::class, Platform::LIVE_MAIL_NOTIFICATIONS);

        $user = $this->citizen();
        $this->askForCode(self::CITIZEN);
        $this->submitCode($this->codeIn(0));
        $this->post(route('password.reset.submit'), ['password' => 'A-brand-new-password1', 'password_confirmation' => 'A-brand-new-password1']);

        // The code, then the "password changed" alert to the same account; nothing else.
        $this->assertSame([self::CITIZEN, self::CITIZEN], array_map(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress(), $this->messages()));
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'security_alert'])->value('status'));
    }

    public function test_logs_never_hold_codes_or_passwords(): void
    {
        $this->register();
        $registrationCode = $this->codeIn(0);
        $this->citizen(['email' => 'unverified@example.test', 'mobile' => '9500000001']);
        $this->askForCode('unverified@example.test');
        $resetCode = $this->lastCode();
        $this->submitCode($this->wrongCode($resetCode));

        config(['mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();
        $this->post(route('password.otp.restart'));
        $this->askForCode(self::CITIZEN);

        $all = implode("\n", $this->logged).json_encode(DB::table('audit_logs')->pluck('meta'));
        foreach ([$registrationCode, $resetCode, self::PASSWORD, 'Dummy-password1'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
