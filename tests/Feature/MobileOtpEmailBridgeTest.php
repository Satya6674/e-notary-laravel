<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\MobileOtpTestNotification;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\MobileOtpEmailBridge;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\VerificationStatus;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Mail is faked or held in memory, and MSG91 is faked. Nothing leaves this process. */
class MobileOtpEmailBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9876543210';

    private const EMAIL = 'bridge.citizen@example.test';

    private const OTHER = 'other.citizen@example.test';

    private const NOTARY = 'bridge.notary@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bridgeOn();
        // CSRF checks return outside the testing environment, and these tests run as local.
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Notification::fake();
        // A live key, so a call to MSG91 would be possible if the bridge let one through.
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
        Http::fake();
    }

    private function bridgeOn(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true, 'mail.mode' => 'local_gmail', 'mail.default' => 'array', 'services.outbound.mail' => implode(',', [self::EMAIL, self::OTHER, self::NOTARY])]);
    }

    private function closeBridge(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);
    }

    private function citizen(array $values = []): User
    {
        return User::factory()->create($values + [
            'role' => 'public', 'email' => self::EMAIL, 'mobile' => self::MOBILE, 'email_verified_at' => null, 'mobile_verified_at' => null,
        ]);
    }

    private function issue(User $user): string
    {
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');

        return $this->lastEmailedCode($user);
    }

    private function complete(User $user): User
    {
        $this->post(route('mobile.verification.verify'), ['otp' => $this->issue($user)])->assertSessionHasNoErrors();

        return $user->fresh();
    }

    private function lastEmailedCode(User $user): string
    {
        return Notification::sent($user, MobileOtpTestNotification::class)->last()->code;
    }

    private function page(User $user)
    {
        return $this->actingAs($user)->get(route('verification.mobile.notice'))->assertOk();
    }

    private function assertSimulatedOnly(User $user): void
    {
        $this->assertNull($user->mobile_verified_at);
        $this->assertFalse($user->hasVerifiedMobile());
        $this->assertFalse($user->canRecoverByMobile());
        $this->assertSame(User::METHOD_DEV_EMAIL, $user->mobile_verification_method);
        $this->assertSame(User::STATUS_SIMULATED, $user->mobile_verification_status);
        $this->assertSame(User::METHOD_DEV_EMAIL, $user->email_verification_method);
        $this->assertFalse(VerificationStatus::productionVerified($user));
    }

    private function register(string $email, string $mobile, string $role)
    {
        Auth::logout();

        return $this->postJson(route('register.submit'), ['name' => 'New Person', 'email' => $email, 'mobile' => $mobile, 'password' => 'A-long-test-password1', 'password_confirmation' => 'A-long-test-password1', 'role' => $role, 'accept_terms' => '1'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_a_new_citizen_uses_one_code_from_registration_to_the_dashboard(): void
    {
        $this->register(self::EMAIL, self::MOBILE, 'public');
        $user = User::where('email', self::EMAIL)->sole();

        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('verification.mobile.notice'));
        $this->page($user)->assertSee('data-auto="1"', false);

        $code = $this->issue($user);
        Notification::assertSentToTimes($user, MobileOtpTestNotification::class, 1);
        $this->assertSame(1, DB::table('otp_verifications')->count());

        $this->page($user)->assertSee('value="'.$code.'"', false);
        $this->post(route('mobile.verification.verify'), ['otp' => $code])->assertRedirect(route('dashboard'));

        $user = $user->fresh();
        Auth::forgetUser();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSimulatedOnly($user);
        $this->assertSame(['email' => 'Verified', 'mobile' => 'Development Verified', 'account' => 'Locally verified', 'method' => 'Development email OTP'], VerificationStatus::for($user));
        Http::assertNothingSent();
    }

    public function test_a_notary_verifies_before_approval_and_is_not_approved_by_it(): void
    {
        $this->register(self::NOTARY, '9123456780', 'notary');
        $notary = User::where('email', self::NOTARY)->sole();

        // Module 1: a Notary awaiting approval lands on the waiting page, not the public home page.
        $this->actingAs($notary)->post(route('mobile.verification.verify'), ['otp' => $this->issue($notary)])->assertRedirect(route('notary.pending'));

        $notary = $notary->fresh();
        $this->assertNotNull($notary->email_verified_at);
        $this->assertSimulatedOnly($notary);
        $this->assertNull($notary->notary_approved_at);
        $this->assertFalse($notary->isApprovedNotary());
        Auth::forgetUser();
        // Module 1: the work area sends a pending Notary to the waiting page, never to the work.
        $this->actingAs($notary)->get(route('notary.dashboard'))->assertRedirect(route('notary.pending'));

        $notary->forceFill(['notary_approved_at' => now()])->save();
        Auth::forgetUser();
        $this->actingAs($notary->fresh())->get(route('notary.dashboard'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_the_page_shows_the_truth_in_local_mode(): void
    {
        $user = $this->citizen();
        $this->issue($user);

        $page = $this->page($user)
            ->assertSee('Local Development Verification — Email OTP used for mobile simulation.')
            ->assertSee(\App\Services\DeliveryLog::maskEmail(self::EMAIL))
            ->assertSee(SmsService::mask(self::MOBILE))
            ->assertSee('Verification method')
            ->assertSee('Resend email OTP');

        foreach (['SMS OTP successfully sent', 'Send code by SMS', self::EMAIL, self::MOBILE] as $absent) {
            $page->assertDontSee($absent, false);
        }
    }

    public function test_profile_and_admin_show_the_simulation_as_development(): void
    {
        $user = $this->complete($this->citizen());

        Auth::forgetUser();
        $this->actingAs($user)->get(route('user.profile'))->assertOk()
            ->assertSee('Development Verified')->assertSee('Locally verified')->assertDontSee('Production verified');

        Auth::forgetUser();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.users.show', $user))->assertOk()
            ->assertSee('Development Verified');
    }

    public function test_the_filled_code_is_the_emailed_code_and_is_only_stored_hashed(): void
    {
        $user = $this->citizen();
        $code = $this->issue($user);

        $this->assertSame($code, MobileOtpEmailBridge::prefill($user, true));
        $this->assertSame(1, DB::table('otp_verifications')->count());
        $row = DB::table('otp_verifications')->first();
        // Module 6: tagged with its flow; the hash still carries the bridge prefix.
        $this->assertStringStartsWith('mobile_verification|', $row->otp);
        $hash = substr($row->otp, strlen('mobile_verification|'));
        $this->assertTrue(Hash::check('email-bridge:'.$code, $hash));
        $this->assertFalse(Hash::check($code, $hash));

        foreach (['otp_verifications', 'jobs', 'message_deliveries', 'audit_logs', 'notifications'] as $table) {
            $this->assertStringNotContainsString($code, json_encode(DB::table($table)->get()), $table);
        }

        $this->assertStringNotContainsString($code, json_encode(session()->all()));
    }

    public function test_the_page_is_not_filled_before_a_code_exists(): void
    {
        $this->page($this->citizen())->assertSee('value=""', false);
    }

    public function test_the_automatic_request_happens_once(): void
    {
        $user = $this->citizen();

        $this->page($user)->assertSee('data-auto="1"', false);
        $this->page($user)->assertDontSee('data-auto="1"', false);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $user = $this->citizen();
        $code = $this->issue($user);
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->post(route('mobile.verification.verify'), ['otp' => $wrong])->assertSessionHasErrors('otp');

        $this->assertSame(1, (int) DB::table('otp_verifications')->value('attempts'));
        $this->assertNull($user->fresh()->mobile_verification_status);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(1, AuditLog::where('action', 'auth.otp_failed')->count());
    }

    public function test_five_wrong_codes_lock_the_number(): void
    {
        $user = $this->citizen();
        $code = $this->issue($user);
        $wrong = $code === '111111' ? '222222' : '111111';

        foreach (range(1, 5) as $attempt) {
            $this->post(route('mobile.verification.verify'), ['otp' => $wrong]);
        }

        $this->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertNull($user->fresh()->mobile_verification_status);
    }

    public function test_an_expired_code_is_rejected_and_no_longer_filled(): void
    {
        $user = $this->citizen();
        $code = $this->issue($user);

        $this->travel(OtpService::ttl() + 1)->minutes();

        $this->page($user)->assertDontSee('value="'.$code.'"', false);
        $this->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertNull($user->fresh()->mobile_verification_status);
    }

    public function test_resend_keeps_the_cooldown_and_a_new_code_replaces_the_old_one(): void
    {
        $user = $this->citizen();
        $first = $this->issue($user);

        $this->post(route('mobile.verification.send'))->assertSessionHas('error');
        Notification::assertSentToTimes($user, MobileOtpTestNotification::class, 1);

        $this->travel(61)->seconds();
        $second = $this->issue($user);
        Notification::assertSentToTimes($user, MobileOtpTestNotification::class, 2);

        $this->page($user)->assertSee('value="'.$second.'"', false);

        if ($first !== $second) {
            $this->post(route('mobile.verification.verify'), ['otp' => $first])->assertSessionHasErrors('otp');
        }

        $this->post(route('mobile.verification.verify'), ['otp' => $second])->assertRedirect();
        $this->assertSimulatedOnly($user->fresh());
    }

    public function test_a_used_code_cannot_be_used_again(): void
    {
        $user = $this->citizen();
        $code = $this->issue($user);
        $this->post(route('mobile.verification.verify'), ['otp' => $code]);

        $user->fresh()->forceFill(['mobile_verification_status' => null, 'mobile_verification_method' => null, 'email_verified_at' => null])->save();
        Auth::forgetUser();

        $this->actingAs($user->fresh())->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
    }

    public function test_each_account_sees_and_gets_only_its_own_status(): void
    {
        $a = $this->citizen();
        $b = $this->citizen(['email' => self::OTHER, 'mobile' => '9123456789']);
        $c = User::factory()->create(['role' => 'notary', 'email' => self::NOTARY, 'mobile' => '9123456780', 'email_verified_at' => null, 'mobile_verified_at' => null, 'notary_approved_at' => now()]);

        $codeA = $this->issue($a);
        $this->post(route('mobile.verification.verify'), ['otp' => $codeA]);
        Auth::forgetUser();

        $this->assertSimulatedOnly($a->fresh());
        $this->assertNull($b->fresh()->mobile_verification_status);
        $this->assertNull($c->fresh()->email_verified_at);

        $codeC = $this->issue($c);
        Auth::forgetUser();
        Notification::assertSentToTimes($b, MobileOtpTestNotification::class, 0);
        $this->assertSimulatedOnly($this->tap(fn () => $this->actingAs($c)->post(route('mobile.verification.verify'), ['otp' => $codeC]), $c)->fresh());
        $this->assertNull($b->fresh()->mobile_verification_status);
    }

    private function tap(callable $action, User $user): User
    {
        $action();

        return $user;
    }

    public function test_another_account_in_the_same_browser_gets_nothing(): void
    {
        $owner = $this->citizen();
        $code = $this->issue($owner);
        $other = $this->citizen(['email' => self::OTHER, 'mobile' => '9123456789']);

        $this->page($other)->assertDontSee('value="'.$code.'"', false);
        $this->actingAs($other)->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertNull($other->fresh()->mobile_verification_status);
    }

    public function test_the_session_entry_is_tied_to_the_account_as_well_as_the_number(): void
    {
        $owner = $this->citizen();
        $this->issue($owner);

        $sameNumber = $owner->replicate();
        $sameNumber->id = $owner->id + 1000;

        $this->assertTrue(MobileOtpEmailBridge::issuedHere($owner));
        $this->assertFalse(MobileOtpEmailBridge::issuedHere($sameNumber));
        $this->assertNull(MobileOtpEmailBridge::prefill($sameNumber, true));
    }

    public function test_the_right_code_fails_in_a_session_that_did_not_ask_for_it(): void
    {
        $user = $this->citizen();
        $code = $this->issue($user);

        $this->flushSession();
        $this->actingAs($user);

        $this->page($user)->assertDontSee('value="'.$code.'"', false);
        $this->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertNull($user->fresh()->mobile_verification_status);
    }

    public function test_the_status_survives_a_new_login(): void
    {
        $user = $this->complete($this->citizen());

        $this->post(route('logout'));
        Auth::forgetGuards();

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
        $this->post(route('mobile.verification.send'))->assertRedirect(route('dashboard'));
        Notification::assertSentToTimes($user, MobileOtpTestNotification::class, 1);
    }

    public function test_changing_the_number_ends_the_simulation(): void
    {
        $user = $this->complete($this->citizen());

        $user->forceFill(['mobile' => '9123456789'])->save();
        $user = $user->fresh();

        $this->assertNull($user->mobile_verification_status);
        $this->assertNull($user->mobile_simulated_at);
        Auth::forgetUser();
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.mobile.notice'));
    }

    public function test_changing_the_email_ends_the_development_email_status(): void
    {
        $user = $this->complete($this->citizen());

        $user->forceFill(['email' => self::OTHER, 'email_verified_at' => null])->save();

        $this->assertNull($user->fresh()->email_verification_method);
        $this->assertFalse($user->fresh()->mobileStepDone());
    }

    public function test_success_is_recorded_as_a_simulation_and_never_as_sms_verification(): void
    {
        $user = $this->complete($this->citizen());

        $this->assertSimulatedOnly($user);
        $this->assertSame(0, AuditLog::where('action', 'auth.mobile_verified')->count());
        Notification::assertNotSentTo($user, SecurityAlertNotification::class);

        $audit = AuditLog::where('action', 'auth.mobile_otp_test_passed')->sole();
        $this->assertTrue($audit->meta['simulated']);
        $this->assertFalse($audit->meta['sms_sent']);
        $this->assertFalse($audit->meta['mobile_verified']);
        $this->assertSame(User::METHOD_DEV_EMAIL, $audit->meta['method']);
        $this->assertSame(1, AuditLog::where('action', 'auth.mobile_otp_test_issued')->count());

        $delivery = MessageDelivery::where('channel', 'sms')->sole();
        $this->assertSame(MessageDelivery::SIMULATED, $delivery->status);
        $this->assertNull($delivery->confirmed_at);
        $this->assertSame(0, DB::table('otp_verifications')->count());
    }

    /** @return array<string, array{string, bool, ?string}> */
    public static function closedEnvironments(): array
    {
        return [
            'production' => ['production', false, 'local_gmail'],
            'production with debug on' => ['production', true, 'local_gmail'],
            'staging' => ['staging', true, 'local_gmail'],
            'local with debug off' => ['local', false, 'local_gmail'],
            'the Apache settings: local and debug, no Local Gmail' => ['local', true, null],
        ];
    }

    #[DataProvider('closedEnvironments')]
    public function test_the_bridge_is_closed_outside_local_gmail(string $env, bool $debug, ?string $mode): void
    {
        $user = $this->citizen(['email_verified_at' => now()]);
        $this->app['env'] = $env;
        config(['app.debug' => $debug, 'mail.mode' => $mode, 'services.outbound.mail' => null]);

        $this->assertFalse(MobileOtpEmailBridge::enabled());

        $page = $this->actingAs($user)->get(route('verification.mobile.notice'))->assertOk();
        $page->assertSee('value=""', false)->assertDontSee('data-auto="1"', false)->assertDontSee('Local Development Verification');

        // Nothing is emailed, and the normal SMS path stays locked without limits.
        $this->post(route('mobile.verification.send'));

        Notification::assertNotSentTo($user, MobileOtpTestNotification::class);
        Http::assertNothingSent();
    }

    public function test_an_unverified_email_still_stops_at_the_email_page_when_closed(): void
    {
        $user = $this->citizen();
        $this->closeBridge();

        $this->actingAs($user)->get(route('verification.mobile.notice'))->assertRedirect(route('verification.notice'));
    }

    public function test_production_never_accepts_a_simulation(): void
    {
        $user = $this->complete($this->citizen());
        $this->closeBridge();
        Auth::forgetUser();
        $user = $user->fresh();

        $this->assertSame(VerificationStatus::MODE_SMS_NOT_READY, VerificationStatus::mode());
        $this->assertFalse($user->mobileSimulationAccepted());
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertTrue($user->needsMobileVerification());
        $this->assertNull(MobileOtpEmailBridge::prefill($user, true));
        $this->assertSame(['email' => 'Pending', 'mobile' => 'Pending', 'account' => 'Pending', 'method' => 'Not verified yet'], VerificationStatus::for($user));

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('services.index'))->assertSuccessful();
        $this->actingAs($user)->post(route('user.services.submit', 'any-service'), [])->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->post(route('user.upload.store'), [])->assertRedirect(route('verification.notice'));

        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => 'signed_link'])->save();
        Auth::forgetUser();
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('verification.mobile.notice'));
    }

    public function test_a_simulated_notary_cannot_use_the_notary_area_in_production(): void
    {
        $notary = User::factory()->create(['role' => 'notary', 'email' => self::NOTARY, 'mobile' => '9123456780', 'email_verified_at' => null, 'mobile_verified_at' => null, 'notary_approved_at' => now()]);
        $notary = $this->complete($notary);
        $this->closeBridge();
        Auth::forgetUser();

        $this->actingAs($notary->fresh())->get(route('notary.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_a_signed_link_replaces_the_development_email_status(): void
    {
        $user = $this->complete($this->citizen());
        $this->closeBridge();
        Auth::forgetUser();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect();

        $this->assertSame('signed_link', $user->fresh()->email_verification_method);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_bridge_code_can_never_pass_as_a_real_sms_code(): void
    {
        $user = $this->citizen(['email_verified_at' => now()]);
        $code = $this->issue($user);

        $this->closeBridge();
        $this->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');

        $this->assertNull($user->fresh()->mobile_verified_at);
    }

    public function test_an_unapproved_address_gets_no_code_and_no_autofill(): void
    {
        config(['services.outbound.mail' => 'someone.else@example.test']);
        $user = $this->citizen();

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');

        Notification::assertNotSentTo($user, MobileOtpTestNotification::class);
        $this->assertNull(MobileOtpEmailBridge::prefill($user, true));
        $this->assertFalse(app(OtpService::class)->status(self::MOBILE)['active']);
        $this->assertSame(MessageDelivery::BLOCKED, MessageDelivery::where('channel', 'sms')->sole()->status);
    }

    public function test_a_mail_failure_gives_no_autofill_and_leaves_no_usable_code(): void
    {
        Notification::swap(new ChannelManager($this->app));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $user = $this->citizen();

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');

        $this->assertNull(MobileOtpEmailBridge::prefill($user, true));
        $this->assertFalse(app(OtpService::class)->status(self::MOBILE)['active']);
        $this->assertSame(MessageDelivery::FAILED, MessageDelivery::where('channel', 'sms')->sole()->status);
    }

    public function test_the_real_mail_goes_out_at_once_and_holds_the_code(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $user = $this->citizen();

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame(self::EMAIL, $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString((string) MobileOtpEmailBridge::prefill($user, true), $sent->first()->toString());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_password_recovery_never_uses_sms_even_with_an_sms_allowance(): void
    {
        $user = $this->citizen(['email_verified_at' => now(), 'mobile_verified_at' => now()]);
        config(['services.outbound.sms' => self::MOBILE, 'services.msg91.send_budget' => 1]);

        $this->post(route('password.email'), ['identifier' => self::MOBILE]);

        Notification::assertNotSentTo($user, MobileOtpTestNotification::class);
        Notification::assertSentTo($user, \App\Notifications\PasswordResetCodeNotification::class);
        Http::assertNothingSent();
    }

    public function test_a_simulated_number_cannot_recover_a_password_by_sms(): void
    {
        $user = $this->complete($this->citizen());
        config(['services.outbound.sms' => self::MOBILE, 'services.msg91.send_budget' => 1]);
        Auth::forgetUser();
        Auth::logout();

        $this->post(route('password.email'), ['identifier' => self::MOBILE]);

        Http::assertNothingSent();
        $this->assertFalse($user->fresh()->canRecoverByMobile());
    }

    private function realSmsConfig(array $overrides = []): void
    {
        config($overrides + [
            'services.msg91.production_ready' => true, 'services.msg91.dlt_pe_id' => 'test-pe', 'services.msg91.dlt_template_id' => 'test-dlt',
            'services.outbound.sms' => self::MOBILE, 'services.msg91.send_budget' => 3, 'services.msg91.budget_id' => 'real-'.uniqid(),
        ]);
    }

    private function fakeProvider(mixed $balance = 25): void
    {
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function ($request) use ($balance) {
            return str_contains($request->url(), 'balance.php')
                ? Http::response($balance)
                : Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });
    }

    /** @return array<string, array{array<string, mixed>, mixed}> */
    public static function notReady(): array
    {
        return [
            'not declared by the owner' => [['services.msg91.production_ready' => false], 25],
            'no PE ID' => [['services.msg91.dlt_pe_id' => null], 25],
            'no DLT template ID' => [['services.msg91.dlt_template_id' => null], 25],
            'no send budget' => [['services.msg91.send_budget' => null], 25],
            'no SMS allowlist' => [['services.outbound.sms' => null], 25],
            'a stand-in provider' => [['services.msg91.endpoint' => 'http://127.0.0.1:8003/otp'], 25],
            'provider refuses this IP' => [[], ['msg' => '418', 'msgType' => 'error']],
            'provider refuses permission' => [[], ['msg' => '204', 'msgType' => 'error']],
            'provider answers nonsense' => [[], ['msg' => '999', 'msgType' => 'error']],
        ];
    }

    #[DataProvider('notReady')]
    public function test_configuration_alone_never_switches_to_real_sms(array $overrides, mixed $balance): void
    {
        $this->realSmsConfig($overrides);
        $this->fakeProvider($balance);

        $this->assertFalse(SmsService::realSmsReady());
        $this->assertSame(VerificationStatus::MODE_DEVELOPMENT, VerificationStatus::mode());
        $this->assertTrue(MobileOtpEmailBridge::enabled());
    }

    public function test_request_values_cannot_choose_the_mode(): void
    {
        $user = $this->citizen();

        $this->actingAs($user)->post(route('mobile.verification.send').'?mode=real_sms', ['mode' => 'real_sms', 'production_ready' => 1])->assertSessionHas('status');

        Http::assertNothingSent();
        Notification::assertSentToTimes($user, MobileOtpTestNotification::class, 1);
    }

    public function test_once_everything_is_ready_real_sms_replaces_the_simulation(): void
    {
        $user = $this->complete($this->citizen());
        $this->realSmsConfig();
        $this->fakeProvider();
        Auth::forgetUser();
        $user = $user->fresh();

        $this->assertSame(VerificationStatus::MODE_REAL_SMS, VerificationStatus::mode());
        $this->assertFalse(MobileOtpEmailBridge::enabled());
        $this->assertFalse($user->mobileSimulationAccepted());
        $this->assertSame('Pending', VerificationStatus::for($user)['mobile']);

        // The development email is no longer accepted, so a signed link comes first.
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => 'signed_link'])->save();
        Auth::forgetUser();
        $user = $user->fresh();

        $this->actingAs($user)->get(route('verification.mobile.notice'))->assertOk()
            ->assertSee('Send code by SMS')->assertDontSee('Local Development Verification');

        $code = null;
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function ($request) use (&$code) {
            if (str_contains($request->url(), 'balance.php')) {
                return Http::response(25);
            }

            $code = $request->data()['otp'] ?? null;

            return Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });

        $this->post(route('mobile.verification.send'))->assertSessionHas('status');
        Notification::assertSentToTimes($user, MobileOtpTestNotification::class, 1);
        $this->assertNull($user->fresh()->mobile_verified_at, 'Provider acceptance alone proves nothing.');

        $this->post(route('mobile.verification.verify'), ['otp' => $code])->assertRedirect(route('dashboard'));

        $user = $user->fresh();
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertSame(User::METHOD_SMS, $user->mobile_verification_method);
        $this->assertSame(User::STATUS_SMS_VERIFIED, $user->mobile_verification_status);
        $this->assertNull($user->mobile_simulated_at);
        $this->assertSame(['email' => 'Verified', 'mobile' => 'Mobile Verified', 'account' => 'Production verified', 'method' => 'SMS OTP'], VerificationStatus::for($user));
    }

    public function test_a_failed_real_send_does_not_verify_anything(): void
    {
        $user = $this->citizen(['email_verified_at' => now(), 'email_verification_method' => 'signed_link']);
        $this->realSmsConfig();
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn ($request) => str_contains($request->url(), 'balance.php') ? Http::response(25) : Http::response(['type' => 'error', 'message' => 'DLT template mismatch']));

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');

        $this->assertNull($user->fresh()->mobile_verified_at);
        $this->assertSame('Pending', VerificationStatus::for($user->fresh())['mobile']);
    }

    public function test_the_local_stand_in_is_never_production_verified(): void
    {
        $this->closeBridge();
        $this->app['env'] = 'local';
        config(['services.msg91.endpoint' => 'http://127.0.0.1:8003/otp']);
        $user = $this->citizen(['email_verified_at' => now(), 'mobile_verified_at' => now()] + User::smsVerifiedAttributes());

        $user->forceFill(User::smsVerifiedAttributes())->save();

        $this->assertSame(User::METHOD_MOCK_SMS, $user->mobile_verification_method);
        $this->assertFalse(VerificationStatus::productionVerified($user->fresh()));
    }
}
