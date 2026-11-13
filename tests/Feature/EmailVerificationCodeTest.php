<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\AccountDeletionService;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The 6-digit code in the registration email. Real messages are built by the in-memory mailer
 * and read back; nothing leaves this process, and no request can reach MSG91.
 */
class EmailVerificationCodeTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'new.person@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    private function register(string $email = self::EMAIL, string $role = 'public'): User
    {
        Auth::logout();
        $this->postJson(route('register.submit'), ['name' => 'New Person', 'email' => $email, 'mobile' => '9000000123', 'password' => 'A-good-password1', 'password_confirmation' => 'A-good-password1', 'role' => $role, 'accept_terms' => '1'])->assertOk();

        return User::where('email', $email)->sole();
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    private function sent(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => (string) $m->getOriginalMessage()->getSubject(), 'body' => (string) $m->getOriginalMessage()->getTextBody()])
            ->values()->all();
    }

    private function lastCode(): ?string
    {
        $mail = collect($this->sent())->last();
        preg_match('/verification code is (\d{6})/', $mail['body'] ?? '', $found);

        return $found[1] ?? null;
    }

    private function rows(User $user)
    {
        return DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user));
    }

    private function submit(User $user, string $code)
    {
        return $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.code'), ['code' => $code]);
    }

    private function wrong(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }

    public function test_registration_sends_one_email_with_a_six_digit_code_stored_only_as_a_hash(): void
    {
        $user = $this->register();

        $mails = $this->sent();
        $this->assertCount(1, $mails, 'exactly one email');
        $this->assertSame(self::EMAIL, $mails[0]['to']);
        $this->assertSame('Your E-Notary verification code', $mails[0]['subject']);
        $code = $this->lastCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertStringNotContainsString('/email/verify/', $mails[0]['body'], 'the email carries the code only, no link');

        $row = $this->rows($user)->sole();
        $this->assertStringStartsWith('email_verification|', $row->otp);
        $this->assertStringNotContainsString($code, $row->otp);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, strtotime($row->expires_at), 5);
        $this->assertSame(0, (int) $row->attempts);
    }

    public function test_the_code_is_never_in_the_queue_in_the_clear(): void
    {
        // Even with a database queue the email is sent at once, so no job ever holds the code.
        config(['queue.default' => 'database']);
        $user = $this->register();
        $this->assertSame(0, DB::table('jobs')->count());

        $code = $this->lastCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'verify_email'])->value('status'));
        $this->assertStringNotContainsString($code, (string) $this->rows($user)->value('otp'));
        $this->assertStringNotContainsString($code, (string) AuditLog::pluck('meta')->implode(''));

        // Should it ever be queued again, the job stays encrypted.
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, class_implements(VerifyEmailNotification::class));
    }

    public function test_the_right_code_verifies_the_address_once(): void
    {
        $user = $this->register();
        $code = $this->lastCode();

        $this->submit($user, $code)->assertRedirect($user->fresh()->landingUrl())->assertSessionHas('status', 'Your email address has been verified.');

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(User::METHOD_EMAIL_CODE, $user->email_verification_method);
        $this->assertSame(0, $this->rows($user)->count(), 'used codes are removed');
        $this->assertNotNull(MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'verify_email'])->value('confirmed_at'), 'using the code proves arrival');

        // Replay: the page is done, and the service refuses the same code again.
        $this->submit($user, $code)->assertRedirect($user->landingUrl());
        $this->assertSame(OtpService::EXPIRED, app(OtpService::class)->verifyEmailCode($user, $code));
    }

    public function test_a_wrong_code_is_refused_and_five_lock_the_step(): void
    {
        $user = $this->register();
        $code = $this->lastCode();

        $this->submit($user, $this->wrong($code))->assertSessionHasErrors(['code' => \App\Http\Controllers\Auth\EmailVerificationController::CODE_INVALID]);
        $this->assertSame(1, (int) $this->rows($user)->value('attempts'));

        foreach (range(2, OtpService::MAX_ATTEMPTS) as $i) {
            $this->submit($user, $this->wrong($code));
        }

        $this->submit($user, $code)->assertSessionHasErrors(['code' => \App\Http\Controllers\Auth\EmailVerificationController::CODE_BLOCKED]);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(OtpService::MAX_ATTEMPTS + 1, AuditLog::where('action', 'auth.otp_failed')->where('model_id', $user->id)->count());

        // A new code cannot lift the lock-out.
        $this->travel(2)->minutes();
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))->assertSessionHas('error', \App\Http\Controllers\Auth\EmailVerificationController::CODE_BLOCKED);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->register();
        $code = $this->lastCode();

        $this->travel(OtpService::ttl() * 60 + 1)->seconds();

        $this->submit($user, $code)->assertSessionHasErrors(['code' => \App\Http\Controllers\Auth\EmailVerificationController::CODE_EXPIRED]);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_waits_a_minute_and_the_new_code_replaces_the_old(): void
    {
        $user = $this->register();
        $first = $this->lastCode();
        $resend = fn () => $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'));

        $resend()->assertSessionHas('error');
        $this->assertCount(1, $this->sent(), 'nothing more inside the minute');

        $this->travel(61)->seconds();
        $resend()->assertSessionHas('status', \App\Http\Controllers\Auth\EmailVerificationController::CODE_SENT);
        $this->assertCount(2, $this->sent());
        $second = $this->lastCode();

        if ($first !== $second) {
            $this->assertSame(OtpService::INVALID, app(OtpService::class)->verifyEmailCode($user, $first), 'the old code stops working');
        }
        $this->submit($user, $second)->assertSessionHas('status', 'Your email address has been verified.');
    }

    public function test_at_most_five_codes_an_hour(): void
    {
        $user = $this->register();

        foreach (range(2, OtpService::MAX_PER_HOUR) as $i) {
            $this->travel(61)->seconds();
            $this->assertSame(OtpService::ISSUED, $user->sendEmailVerificationCode());
        }

        $this->travel(61)->seconds();
        $this->assertSame(OtpService::HOURLY_LIMIT, $user->sendEmailVerificationCode());
        $this->assertCount(OtpService::MAX_PER_HOUR, $this->sent());
    }

    public function test_a_code_proves_only_the_address_it_was_sent_to(): void
    {
        $user = $this->register();
        $code = $this->lastCode();

        $user->forceFill(['email' => 'someone.else@example.test'])->save();

        $this->assertSame(OtpService::INVALID, app(OtpService::class)->verifyEmailCode($user->fresh(), $code));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_codes_of_other_flows_never_cross_over(): void
    {
        $user = $this->register();
        $code = $this->lastCode();
        $service = app(OtpService::class);

        $this->assertSame(OtpService::EXPIRED, $service->verify((string) $user->mobile, $code), 'not a mobile code');
        $this->assertSame(OtpService::EXPIRED, $service->verifyRecoveryCode((string) $user->mobile, $code), 'not a recovery code');

        $this->expectException(InvalidArgumentException::class);
        $service->verify(OtpService::emailKey($user), $code, false, OtpService::FLOW_EMAIL_VERIFICATION);
    }

    public function test_the_sms_entry_point_refuses_the_email_flow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OtpService::class)->issue('9000000123', null, OtpService::FLOW_EMAIL_VERIFICATION);
    }

    public function test_a_signed_link_sent_before_the_code_only_email_still_works(): void
    {
        $user = $this->register();
        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

        Auth::logout();
        $this->get($link)->assertRedirect(route('login'));

        $this->assertSame('signed_link', $user->fresh()->email_verification_method);
    }

    public function test_an_expired_code_is_never_put_in_the_email(): void
    {
        $user = $this->register();

        $mail = (new VerifyEmailNotification('123456', now()->subSecond()))->toMail($user);

        $this->assertStringNotContainsString('123456', implode(' ', $mail->viewData['introLines']));
        $this->assertStringContainsString('request a new code', implode(' ', $mail->viewData['introLines']));
        $this->assertNull($mail->viewData['actionUrl'], 'no link either');
    }

    public function test_the_code_is_redacted_in_a_log_copy(): void
    {
        $log = storage_path('framework/testing/email-code-'.uniqid().'.log');
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'email-code-test', 'logging.channels.email-code-test' => ['driver' => 'single', 'path' => $log]]);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();

        try {
            $user = $this->register();
            $this->assertNotNull($this->rows($user)->value('otp'), 'a code was issued');

            $contents = File::get($log);
            $this->assertStringContainsString('verification code is [removed-in-log-mode]', $contents);
            $this->assertDoesNotMatchRegularExpression('/verification code is \d{6}/', $contents);
        } finally {
            File::delete($log);
        }
    }

    public function test_the_verify_route_is_rate_limited(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $user = $this->register();

        $statuses = [];
        foreach (range(1, 11) as $i) {
            $statuses[] = $this->submit($user, '000000')->status();
        }

        $this->assertSame(429, end($statuses));
    }

    public function test_a_deactivated_account_cannot_verify(): void
    {
        $user = $this->register();
        $code = $this->lastCode();
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->assertContains($this->submit($user, $code)->status(), [302, 403]);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_deleting_an_account_removes_its_unused_email_code(): void
    {
        $user = $this->register();
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test', 'email_verified_at' => now(), 'password' => Hash::make('admin-password')]);
        $this->assertSame(1, $this->rows($user)->count());

        app(AccountDeletionService::class)->delete($admin, $user->fresh(), 'Requested by the account holder in writing.');

        $this->assertSame(0, $this->rows($user)->count());
    }

    public function test_a_notary_registration_gets_the_code_too(): void
    {
        $user = $this->register('notary.person@example.test', 'notary');

        $this->assertNotNull($this->lastCode());
        $this->submit($user, $this->lastCode())->assertSessionHas('status', 'Your email address has been verified.');
        $this->assertNull($user->fresh()->notary_approved_at, 'approval is still the administrator\'s');
    }
}
