<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\MobileEmailFallbackCodeNotification;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The mobile step while SMS is unavailable: a mobile-purpose code sent to the verified email.
 * Messages are built by the in-memory mailer and read back. Every test ends by proving that no
 * HTTP request was made, so no SMS provider was ever called.
 */
class MobileEmailFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9876500001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.msg91.email_fallback' => true]);
        Http::fake();
    }

    protected function tearDown(): void
    {
        if (! $this->allowSms) {
            Http::assertNothingSent();
        }

        parent::tearDown();
    }

    private bool $allowSms = false;

    private function citizen(string $email = 'citizen@example.test', string $mobile = self::MOBILE): User
    {
        $user = User::factory()->create(['role' => 'public', 'email' => $email, 'mobile' => $mobile, 'email_verified_at' => now(), 'mobile_verified_at' => null, 'password' => Hash::make('a-good-password')]);
        $user->forceFill(['email_verification_method' => User::METHOD_EMAIL_CODE])->save();

        return $user->fresh();
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
        preg_match('/mobile verification code is (\d{6})/', collect($this->sent())->last()['body'] ?? '', $m);

        return $m[1] ?? null;
    }

    private function send(User $user)
    {
        return $this->actingAs($user)->from(route('verification.mobile.notice'))->post(route('mobile.verification.send'));
    }

    private function verify(User $user, ?string $code)
    {
        return $this->actingAs($user)->from(route('verification.mobile.notice'))->post(route('mobile.verification.verify'), ['otp' => (string) $code]);
    }

    private function rows(string $flow)
    {
        return DB::table('otp_verifications')->where('otp', 'like', $flow.'|%');
    }

    private function wrong(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }

    public function test_the_fallback_is_the_mobile_mode_while_sms_is_not_ready(): void
    {
        $this->assertFalse(SmsService::realSmsReady());
        $this->assertSame(VerificationStatus::MODE_EMAIL_FALLBACK, VerificationStatus::mode());

        config(['services.msg91.email_fallback' => false]);
        $this->assertSame(VerificationStatus::MODE_SMS_NOT_READY, VerificationStatus::mode());
    }

    public function test_the_sms_button_emails_a_mobile_code_to_the_verified_address_and_calls_no_sms_provider(): void
    {
        $user = $this->citizen();

        $this->send($user)->assertRedirect(route('verification.mobile.notice'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'SMS is temporarily unavailable') && str_contains($s, 'No text message was sent'));

        $mails = $this->sent();
        $this->assertCount(1, $mails);
        $this->assertSame('citizen@example.test', $mails[0]['to']);
        $this->assertStringContainsString('mobile verification code', $mails[0]['subject']);
        $this->assertStringContainsString('SMS delivery is temporarily unavailable', $mails[0]['body']);
        $this->assertStringContainsString('No text message was sent to ******0001', $mails[0]['body']);
        $this->assertStringContainsString('It does not mean the number was confirmed by SMS', $mails[0]['body']);

        $code = $this->lastCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $row = $this->rows(OtpService::FLOW_MOBILE_EMAIL_FALLBACK)->sole();
        $this->assertSame(self::MOBILE, $row->mobile);
        $this->assertStringNotContainsString($code, $row->otp);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, strtotime($row->expires_at), 5);

        $this->assertSame(0, $this->rows(OtpService::FLOW_MOBILE_VERIFICATION)->count(), 'no SMS-flow code exists');
        $this->assertSame(0, $this->rows(OtpService::FLOW_EMAIL_VERIFICATION)->count(), 'the email purpose is untouched');
        $this->assertSame(0, MessageDelivery::where('channel', 'sms')->count(), 'no SMS was recorded, let alone sent');
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('purpose', 'mobile_email_fallback_code')->value('status'));
        $this->assertSame(['sms_sent' => false, 'sent_to' => 'verified_email'], AuditLog::where('action', 'auth.mobile_email_fallback_issued')->sole()->meta);
    }

    public function test_the_code_goes_to_each_account_s_own_address_and_never_to_the_page(): void
    {
        $a = $this->citizen('a@example.test', '9876500011');
        $b = $this->citizen('b@example.test', '9876500012');

        $this->send($a);
        $codeA = $this->lastCode();
        $this->send($b);

        $this->assertSame(['a@example.test', 'b@example.test'], array_column($this->sent(), 'to'));

        $page = $this->actingAs($a)->get(route('verification.mobile.notice'))->assertOk();
        $page->assertDontSee($codeA)->assertSee('Send a new code');
        $this->assertStringNotContainsString($codeA, json_encode(session()->all()));
        $this->assertStringNotContainsString($codeA, (string) AuditLog::pluck('meta')->toJson());
    }

    public function test_the_right_code_records_an_email_fallback_and_never_sms_proof(): void
    {
        $user = $this->citizen();
        $emailVerifiedAt = $user->email_verified_at;
        $this->send($user);

        $this->verify($user, $this->lastCode())->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Mobile step complete: Verified via Email Fallback. No SMS was sent, so your number is not confirmed by SMS.');

        $user->refresh();
        $this->assertSame(User::METHOD_EMAIL_FALLBACK, $user->mobile_verification_method);
        $this->assertSame(User::STATUS_FALLBACK_COMPLETED, $user->mobile_verification_status);
        $this->assertSame(User::SMS_NOT_SENT, $user->mobile_sms_status);
        $this->assertNotNull($user->mobile_fallback_verified_at);
        $this->assertSame(self::MOBILE, $user->mobile_fallback_number);
        $this->assertNull($user->mobile_verified_at, 'never SMS proof');
        $this->assertFalse($user->hasVerifiedMobile());
        $this->assertTrue($user->mobileFallbackVerified());
        $this->assertFalse($user->needsMobileVerification(), 'the citizen area opens');
        $this->assertFalse($user->canRecoverByMobile(), 'SMS password reset still needs a real SMS');
        $this->assertFalse(VerificationStatus::productionVerified($user));
        $this->assertEquals($emailVerifiedAt, $user->email_verified_at, 'email verification untouched');
        $this->assertSame(User::METHOD_EMAIL_CODE, $user->email_verification_method);
        $this->assertSame(0, $this->rows(OtpService::FLOW_MOBILE_EMAIL_FALLBACK)->count(), 'used codes are removed');

        $event = DB::table('verification_events')->where('user_id', $user->id)->sole();
        $this->assertSame(['mobile', 'email_fallback', 'email', 'not_sent', '******0001'], [$event->purpose, $event->method, $event->delivered_via, $event->sms_status, $event->target_masked]);
        $this->assertNotNull(MessageDelivery::where('purpose', 'mobile_email_fallback_code')->value('confirmed_at'));
        $this->assertSame(['sms_sent' => false, 'mobile_verified_by_sms' => false], AuditLog::where('action', 'auth.mobile_email_fallback_verified')->sole()->meta);
    }

    public function test_the_dashboard_and_pages_show_the_fallback_honestly(): void
    {
        $user = $this->citizen();
        $this->send($user);
        $this->verify($user, $this->lastCode());
        $user->refresh();

        $this->assertSame(['email' => 'Verified (Email OTP)', 'mobile' => 'Verified via Email Fallback', 'account' => 'Verified with email fallback', 'method' => 'Email fallback, no SMS sent'], VerificationStatus::for($user));
        $this->assertSame('Not Sent / Temporarily Unavailable', VerificationStatus::sms($user));

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Verified (Email OTP)')->assertSee('Verified via Email Fallback')->assertDontSee('Verified (Email Fallback)')
            ->assertSee('Not Sent / Temporarily Unavailable')->assertSee('Verified via Email Fallback')
            ->assertDontSee('Delivered and verified by SMS')->assertDontSee('Mobile Verified');

        $this->actingAs($user)->get(route('user.profile'))->assertOk()->assertSee('Verified via Email Fallback')->assertSee('No SMS sent');

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test', 'email_verified_at' => now()]);
        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()->assertSee('Verified via Email Fallback');
    }

    public function test_a_wrong_code_is_refused_five_lock_the_step_and_a_used_code_never_works_again(): void
    {
        $user = $this->citizen();
        $this->send($user);
        $code = $this->lastCode();

        $this->verify($user, $this->wrong($code))->assertSessionHasErrors(['otp' => 'Incorrect code. You have 4 attempts left.']);
        foreach (range(2, OtpService::MAX_ATTEMPTS) as $i) {
            $this->verify($user, $this->wrong($code));
        }
        $this->verify($user, $code)->assertSessionHasErrors(['otp' => 'Too many incorrect attempts. Please request a new code later.']);
        $this->assertFalse($user->fresh()->mobileFallbackVerified());

        // A new code cannot lift the lock-out.
        $this->travel(2)->minutes();
        $this->send($user)->assertSessionHas('error', OtpService::userMessage(OtpService::BLOCKED_NOW));

        // After the lock-out: a fresh code works once.
        $this->travel(16)->minutes();
        $this->send($user);
        $fresh = $this->lastCode();
        $this->verify($user, $fresh)->assertRedirect(route('dashboard'));
        $this->assertSame(OtpService::EXPIRED, app(OtpService::class)->verifyMobileFallbackCode($user->fresh(), $fresh), 'one use only');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->citizen();
        $this->send($user);
        $code = $this->lastCode();

        $this->travel(OtpService::ttl() * 60 + 1)->seconds();

        $this->verify($user, $code)->assertSessionHasErrors(['otp' => 'The code is invalid or has expired. Please request a new one.']);
        $this->assertFalse($user->fresh()->mobileFallbackVerified());
    }

    public function test_cooldown_and_hourly_limit_apply(): void
    {
        $user = $this->citizen();
        $this->send($user);
        $this->send($user)->assertSessionHas('error', OtpService::userMessage(OtpService::COOLDOWN));
        $this->assertCount(1, $this->sent());

        foreach (range(2, OtpService::MAX_PER_HOUR) as $i) {
            $this->travel(61)->seconds();
            $this->send($user)->assertSessionHas('status');
        }
        $this->travel(61)->seconds();
        $this->send($user)->assertSessionHas('error', OtpService::userMessage(OtpService::HOURLY_LIMIT));
        $this->assertCount(OtpService::MAX_PER_HOUR, $this->sent());
    }

    public function test_email_and_mobile_purposes_never_complete_each_other(): void
    {
        $user = $this->citizen();
        $service = app(OtpService::class);

        // An email-verification code cannot pass the mobile step, and the reverse.
        [, $emailCode] = $service->issueEmailCode($user);
        $this->assertSame(OtpService::EXPIRED, $service->verifyMobileFallbackCode($user, $emailCode));
        [, $mobileCode] = $service->issueMobileFallbackCode($user);
        $this->assertSame(OtpService::INVALID, $service->verifyEmailCode($user, $mobileCode));

        // Passing the email step leaves the mobile step to do.
        $this->assertSame(OtpService::OK, $service->verifyEmailCode($user, $emailCode));
        $this->assertTrue($user->fresh()->needsMobileVerification());

        // A code sent to one address does not pass once the address changed.
        $user->forceFill(['email' => 'changed@example.test'])->save();
        $this->assertSame(OtpService::INVALID, $service->verifyMobileFallbackCode($user->fresh(), $mobileCode));
    }

    public function test_an_unverified_email_gets_no_mobile_code(): void
    {
        $user = User::factory()->create(['role' => 'public', 'email' => 'unverified@example.test', 'mobile' => self::MOBILE, 'email_verified_at' => null, 'mobile_verified_at' => null]);

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertRedirect(route('verification.notice'));

        $this->assertSame([], $this->sent());
        $this->assertSame(0, $this->rows(OtpService::FLOW_MOBILE_EMAIL_FALLBACK)->count());
    }

    public function test_a_number_change_ends_the_fallback(): void
    {
        $user = $this->citizen();
        $this->send($user);
        $this->verify($user, $this->lastCode());

        // Through the model, the columns are cleared.
        $user->fresh()->forceFill(['mobile' => '9876500099'])->save();
        $user->refresh();
        $this->assertNull($user->mobile_fallback_verified_at);
        $this->assertFalse($user->mobileFallbackVerified());
        $this->assertTrue($user->needsMobileVerification());
        $this->assertSame(1, DB::table('verification_events')->where('user_id', $user->id)->count(), 'the history row stays');

        // A direct update that skips the model still cannot carry the fallback to another number.
        $other = $this->citizen('other@example.test', '9876500022');
        $this->send($other);
        $this->verify($other, $this->lastCode());
        User::whereKey($other->id)->update(['mobile' => '9876500033']);
        $this->assertFalse($other->fresh()->mobileFallbackVerified());
    }

    public function test_safe_preview_makes_no_code_and_says_so_plainly(): void
    {
        $user = $this->citizen();
        config(['mail.default' => 'log']);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();

        $this->actingAs($user)->get(route('verification.mobile.notice'))->assertOk()->assertDontSee('Safe Preview');
        $this->send($user)->assertSessionHas('error', "We couldn't send your verification code. Please try again later.");

        $this->assertSame(0, $this->rows(OtpService::FLOW_MOBILE_EMAIL_FALLBACK)->count());
    }

    public function test_the_code_is_encrypted_in_the_queue_and_redacted_in_a_log_copy(): void
    {
        $user = $this->citizen();
        config(['queue.default' => 'database']);
        $this->send($user);

        $payload = (string) DB::table('jobs')->value('payload');
        $notification = unserialize(app('encrypter')->decrypt(json_decode($payload, true)['data']['command']))->notification;
        $this->assertInstanceOf(MobileEmailFallbackCodeNotification::class, $notification);
        $this->assertStringNotContainsString($notification->code, $payload);

        $log = storage_path('framework/testing/fallback-'.uniqid().'.log');
        config(['queue.default' => 'sync', 'mail.default' => 'log', 'mail.mailers.log.channel' => 'fallback-test', 'logging.channels.fallback-test' => ['driver' => 'single', 'path' => $log]]);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();

        try {
            $user->notify(new MobileEmailFallbackCodeNotification('482915', self::MOBILE, now()->addMinutes(10)));
            $contents = File::get($log);
            $this->assertStringContainsString('mobile verification code is [removed-in-log-mode]', $contents);
            $this->assertStringNotContainsString('482915', $contents);
        } finally {
            File::delete($log);
        }
    }

    public function test_a_retry_after_expiry_sends_no_code(): void
    {
        $user = $this->citizen();
        $mail = (new MobileEmailFallbackCodeNotification('482915', self::MOBILE, now()->subSecond()))->toMail($user);

        $this->assertStringNotContainsString('482915', implode(' ', $mail->introLines));
    }

    public function test_a_later_sms_verification_adds_history_and_keeps_the_fallback_record(): void
    {
        $user = $this->citizen();
        $this->send($user);
        $this->verify($user, $this->lastCode());

        // SMS becomes available: the existing SMS path runs, against a faked provider.
        $this->allowSms = true;
        config(['services.msg91.email_fallback' => false, 'services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
        $smsCode = null;
        // A fresh fake: the catch-all from setUp would otherwise answer first.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$smsCode) {
            $smsCode = $request->data()['otp'] ?? null;

            return Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });

        $this->assertSame(OtpService::ISSUED, app(OtpService::class)->issue(self::MOBILE, null, OtpService::FLOW_MOBILE_VERIFICATION, $user->id));
        $this->verify($user->fresh(), $smsCode)->assertSessionHas('status');

        $user->refresh();
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertSame(User::METHOD_SMS, $user->mobile_verification_method);
        $this->assertSame(User::SMS_DELIVERED, $user->mobile_sms_status);
        $this->assertSame('Delivered and verified by SMS', VerificationStatus::sms($user));

        $history = DB::table('verification_events')->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame(['email_fallback', 'msg91_sms'], $history->pluck('method')->all(), 'the fallback row is kept, not overwritten');
        $this->assertSame(['not_sent', 'delivered'], $history->pluck('sms_status')->all());
    }

    public function test_with_the_fallback_off_the_sms_path_is_unchanged_and_sends_no_email(): void
    {
        config(['services.msg91.email_fallback' => false]);
        $user = $this->citizen();

        $this->send($user)->assertSessionHas('error', OtpService::userMessage(OtpService::SEND_FAILED));

        $this->assertSame([], $this->sent());
        $this->assertSame(0, $this->rows(OtpService::FLOW_MOBILE_EMAIL_FALLBACK)->count());
    }
}
