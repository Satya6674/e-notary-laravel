<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\PrivacyRequest;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\DeliveryLog;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Providers are mocked here. These results say nothing about real delivery. */
class Phase6SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9123456789';

    private ?string $code = null;

    private function user(string $role = 'public', array $values = []): User
    {
        return User::factory()->create($values + [
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    /** Captures the generated code the way only a test can. */
    private function fakeSms(bool $accepted = true): void
    {
        $this->mock(SmsService::class, function ($mock) use ($accepted) {
            $mock->lastRequestId = $accepted ? 'request1234' : null;
            $mock->lastError = $accepted ? null : 'provider:no balance';
            $mock->shouldReceive('send')->andReturnUsing(function ($mobile, $otp) use ($accepted) {
                $this->code = $otp;

                return $accepted;
            });
        });
    }

    private function msg91(): void
    {
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template']);
    }

    private function newestRow(): object
    {
        return DB::table('otp_verifications')->orderByDesc('id')->first();
    }

    public function test_code_is_six_digits_hashed_and_never_stored_or_logged_in_plain(): void
    {
        $this->fakeSms();
        Log::spy();

        $this->assertSame(OtpService::ISSUED, app(OtpService::class)->issue(self::MOBILE));

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->code);
        $this->assertNotSame($this->code, $this->newestRow()->otp);
        // Module 6: the flow tag, then the bcrypt hash; the code never appears.
        $this->assertStringStartsWith('mobile_verification|$2y$', $this->newestRow()->otp);
        $this->assertStringNotContainsString($this->code, $this->newestRow()->otp);

        Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains(json_encode([$message, $context]), $this->code));
    }

    public function test_expiry_comes_from_configuration(): void
    {
        config(['services.msg91.otp_ttl' => 2]);
        $this->fakeSms();

        app(OtpService::class)->issue(self::MOBILE);

        $this->assertLessThanOrEqual(120, now()->diffInSeconds($this->newestRow()->expires_at));
    }

    public function test_correct_code_works_once_and_cannot_be_replayed(): void
    {
        $this->fakeSms();
        $otp = app(OtpService::class);
        $otp->issue(self::MOBILE);

        $this->assertSame(OtpService::OK, $otp->verify(self::MOBILE, $this->code));
        $this->assertSame(OtpService::EXPIRED, $otp->verify(self::MOBILE, $this->code));
    }

    public function test_a_new_code_cancels_the_older_one_so_it_cannot_work_later(): void
    {
        $this->fakeSms();
        $otp = app(OtpService::class);
        $otp->issue(self::MOBILE);
        $first = $this->code;

        DB::table('otp_verifications')->update(['created_at' => now()->subMinutes(2)]);
        $otp->issue(self::MOBILE);

        $this->assertNotSame($first, $this->code);
        $this->assertSame(OtpService::INVALID, $otp->verify(self::MOBILE, $first));
        $this->assertSame(OtpService::OK, $otp->verify(self::MOBILE, $this->code));
        $this->assertSame(OtpService::EXPIRED, $otp->verify(self::MOBILE, $first), 'The older code must not work once the new one is used.');
    }

    public function test_expired_code_is_refused(): void
    {
        $this->fakeSms();
        $otp = app(OtpService::class);
        $otp->issue(self::MOBILE);

        DB::table('otp_verifications')->update(['expires_at' => now()->subSecond()]);

        $this->assertSame(OtpService::EXPIRED, $otp->verify(self::MOBILE, $this->code));
    }

    public function test_max_attempts_block_even_the_correct_code_and_a_new_request(): void
    {
        $this->fakeSms();
        $otp = app(OtpService::class);
        $otp->issue(self::MOBILE);

        foreach (range(1, OtpService::MAX_ATTEMPTS) as $i) {
            $this->assertSame(OtpService::INVALID, $otp->verify(self::MOBILE, '000000'));
        }

        $this->assertSame(OtpService::BLOCKED, $otp->verify(self::MOBILE, $this->code));
        $this->assertSame(OtpService::BLOCKED_NOW, $otp->issue(self::MOBILE));
    }

    public function test_resend_cooldown_sends_nothing(): void
    {
        $this->fakeSms();
        $otp = app(OtpService::class);

        $otp->issue(self::MOBILE);

        $this->assertSame(OtpService::COOLDOWN, $otp->issue(self::MOBILE));
        $this->assertDatabaseCount('otp_verifications', 1);
    }

    public function test_a_code_that_failed_to_send_cannot_be_used(): void
    {
        $this->fakeSms(false);
        $otp = app(OtpService::class);

        $this->assertSame(OtpService::SEND_FAILED, $otp->issue(self::MOBILE));
        $this->assertSame(OtpService::EXPIRED, $otp->verify(self::MOBILE, $this->code));

        $row = MessageDelivery::firstOrFail();
        $this->assertSame(MessageDelivery::FAILED, $row->status);
        $this->assertSame('******6789', $row->recipient);
    }

    public function test_sms_is_recorded_as_accepted_with_no_code_or_full_number(): void
    {
        $this->fakeSms();
        app(OtpService::class)->issue(self::MOBILE);

        $row = MessageDelivery::firstOrFail();

        $this->assertSame(MessageDelivery::ACCEPTED, $row->status);
        $this->assertSame('request1234', $row->provider_reference);
        $this->assertNull($row->confirmed_at);
        $this->assertStringNotContainsString($this->code, json_encode($row->toArray()));
        $this->assertStringNotContainsString(self::MOBILE, json_encode($row->toArray()));
    }

    public function test_account_check_stops_a_paid_send_when_msg91_refuses_this_address(): void
    {
        $this->msg91();
        config(['services.msg91.preflight' => true]);
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response(['msg' => '418', 'msgType' => 'error'])]);

        $sms = new SmsService();

        $this->assertFalse($sms->send(self::MOBILE, '123456'));
        $this->assertSame('preflight:ip_blocked', $sms->lastError);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/otp'));
    }

    public function test_account_check_allows_a_send_when_the_key_works(): void
    {
        $this->msg91();
        config(['services.msg91.preflight' => true]);
        Http::fake([
            SmsService::BALANCE_ENDPOINT.'*' => Http::response('125.5'),
            SmsService::ENDPOINT => Http::response(['type' => 'success', 'request_id' => 'abcdef123456']),
        ]);

        $this->assertTrue((new SmsService())->send(self::MOBILE, '123456'));
    }

    public function test_a_failed_send_is_attempted_once_only(): void
    {
        $this->msg91();
        Http::fake([SmsService::ENDPOINT => Http::response('', 500)]);

        (new SmsService())->send(self::MOBILE, '123456');

        Http::assertSentCount(1);
    }

    public function test_allowlist_stops_sms_and_mail_to_anyone_else(): void
    {
        $this->msg91();
        config(['services.outbound.sms' => '9000000000', 'services.outbound.mail' => 'allowed@example.test']);
        Http::fake();

        $sms = new SmsService();
        $this->assertFalse($sms->send(self::MOBILE, '123456'));
        $this->assertSame('blocked_by_allowlist', $sms->lastError);
        Http::assertNothingSent();

        Mail::raw('Hello', fn ($m) => $m->to('someone@example.test'));
        Mail::raw('Hello', fn ($m) => $m->to('allowed@example.test'));

        $this->assertCount(1, Mail::getSymfonyTransport()->messages());
    }

    public function test_blocked_mail_is_recorded_as_blocked_and_never_as_accepted(): void
    {
        config(['services.outbound.mail' => 'allowed@example.test']);
        $user = $this->user('public', ['email' => 'someone@example.test']);

        $user->notify(new VerifyEmailNotification());

        $this->assertSame(MessageDelivery::BLOCKED, MessageDelivery::firstOrFail()->status);
    }

    public function test_email_is_tracked_with_a_masked_address_and_confirmed_only_by_the_link(): void
    {
        $user = $this->user('public', ['email' => 'person@example.test', 'email_verified_at' => null]);

        $user->notify(new VerifyEmailNotification());

        $row = MessageDelivery::firstOrFail();
        $this->assertSame(MessageDelivery::ACCEPTED, $row->status);
        $this->assertSame('pe***@example.test', $row->recipient);
        $this->assertSame('verify_email', $row->purpose);
        $this->assertNull($row->confirmed_at);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($row->fresh()->confirmed_at);
    }

    public function test_email_link_expires_and_cannot_be_forged_or_used_by_another_account(): void
    {
        $user = $this->user('public', ['email_verified_at' => null]);
        $other = $this->user();

        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($expired)->assertForbidden();

        $good = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($good.'x')->assertForbidden();
        $this->actingAs($other)->get($good)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_link_works_signed_out_and_signs_nobody_in(): void
    {
        $user = $this->user('public', ['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified', 'user_id' => $user->id]);
    }

    public function test_email_link_signed_out_refuses_bad_links_and_deactivated_accounts(): void
    {
        $user = $this->user('public', ['email_verified_at' => null]);
        $make = fn ($expires, $hash) => URL::temporarySignedRoute('verification.verify', $expires, ['id' => $user->id, 'hash' => $hash]);

        $this->get($make(now()->subMinute(), sha1($user->email)))->assertForbidden();
        $this->get($make(now()->addHour(), sha1('someone-else@example.test')))->assertForbidden();
        $this->get($make(now()->addHour(), sha1($user->email)).'x')->assertForbidden();
        $this->get('/email/verify/'.$user->id.'/'.sha1($user->email))->assertForbidden();

        $user->forceFill(['deactivated_at' => now()])->save();
        $this->get($make(now()->addHour(), sha1($user->email)))->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_link_used_twice_changes_nothing(): void
    {
        $user = $this->user('public', ['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect();
        $first = $user->fresh()->email_verified_at;

        $this->travel(5)->minutes();
        $this->actingAs($user->fresh())->get($url)->assertRedirect();

        $this->assertEquals($first, $user->fresh()->email_verified_at);
    }

    public function test_email_and_mobile_verification_stay_independent(): void
    {
        $this->fakeSms();
        $user = $this->user('public', ['mobile' => self::MOBILE, 'mobile_verified_at' => null]);

        $this->actingAs($user)->post(route('mobile.verification.send'));
        $this->assertNull($user->fresh()->mobile_verified_at);

        $this->post(route('mobile.verification.verify'), ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertNull($user->fresh()->mobile_verified_at);

        Notification::fake();
        $this->post(route('mobile.verification.verify'), ['otp' => $this->code])->assertRedirect();
        $this->assertNotNull($user->fresh()->mobile_verified_at);
        Notification::assertSentTo($user, SecurityAlertNotification::class);

        $unverifiedEmail = $this->user('public', ['mobile' => '9123456780', 'mobile_verified_at' => null, 'email_verified_at' => null]);
        $this->actingAs($unverifiedEmail)->post(route('mobile.verification.send'))->assertRedirect(route('verification.notice'));
    }

    public function test_one_account_cannot_verify_with_a_code_sent_to_another(): void
    {
        $this->fakeSms();
        $owner = $this->user('public', ['mobile' => self::MOBILE, 'mobile_verified_at' => null]);
        $other = $this->user('public', ['mobile' => '9123456780', 'mobile_verified_at' => null]);

        $this->actingAs($owner)->post(route('mobile.verification.send'));

        $this->actingAs($other)->post(route('mobile.verification.verify'), ['otp' => $this->code])->assertSessionHasErrors('otp');

        $this->assertNull($other->fresh()->mobile_verified_at);
    }

    public function test_verify_page_shows_only_a_masked_number_and_server_timers(): void
    {
        $this->fakeSms();
        $user = $this->user('public', ['mobile' => self::MOBILE, 'mobile_verified_at' => null]);

        $this->actingAs($user)->post(route('mobile.verification.send'));

        $page = $this->get(route('verification.mobile.notice'))->assertOk();

        $page->assertSee('******6789')->assertDontSee(self::MOBILE)->assertDontSee($this->code)->assertSee('data-wait', false)->assertSee('autocomplete="one-time-code"', false);
    }

    public function test_provider_failure_tells_the_person_no_code_was_sent(): void
    {
        $this->fakeSms(false);
        $user = $this->user('public', ['mobile' => self::MOBILE, 'mobile_verified_at' => null]);

        $this->actingAs($user)->post(route('mobile.verification.send'))
            ->assertSessionHas('error', 'SMS is unavailable right now, so no code was sent. Please try again later.');
    }

    public function test_password_change_sends_a_security_alert(): void
    {
        Notification::fake();
        $user = $this->user('public', ['mobile' => self::MOBILE, 'password' => bcrypt('old-password')]);

        $this->actingAs($user)->put(route('user.profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'mobile' => self::MOBILE,
            'current_password' => 'old-password', 'password' => 'New-password-1', 'password_confirmation' => 'New-password-1',
        ])->assertRedirect();

        Notification::assertSentTo($user, SecurityAlertNotification::class, fn ($n) => $n->event === 'password_changed');
    }

    public function test_only_admins_reach_security_pages(): void
    {
        $citizen = $this->user();
        $notary = $this->user('notary');

        foreach (['admin.security.index', 'admin.security.permissions', 'admin.security.privacy', 'admin.security.incidents'] as $route) {
            $this->actingAs($citizen)->get(route($route))->assertForbidden();
            $this->actingAs($notary)->get(route($route))->assertForbidden();
            $this->actingAs($this->user('admin'))->get(route($route))->assertOk();
        }

        $this->actingAs($citizen)->post(route('admin.security.incidents.store'), ['title' => 'x'])->assertForbidden();
        $this->actingAs($citizen)->post(route('admin.security.check'))->assertForbidden();
    }

    public function test_security_page_shows_no_secret_and_integration_check_sends_nothing(): void
    {
        config(['services.msg91.auth_key' => 'SECRET-KEY-VALUE', 'services.msg91.template_id' => 'tpl', 'mail.mailers.smtp.password' => 'SECRET-MAIL-VALUE', 'services.msg91.preflight' => false]);
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response(['msg' => '418', 'msgType' => 'error'])]);
        Mail::fake();

        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.security.check'))->assertRedirect();

        $this->get(route('admin.security.index'))->assertOk()->assertSee('IP not allowed')->assertDontSee('SECRET-KEY-VALUE')->assertDontSee('SECRET-MAIL-VALUE');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/otp'));
        Mail::assertNothingSent();
    }

    public function test_citizen_privacy_request_flow_and_duplicates(): void
    {
        $citizen = $this->user();

        $this->actingAs($citizen)->post(route('privacy-center.store'), ['type' => 'access'])->assertSessionHas('status');
        $this->post(route('privacy-center.store'), ['type' => 'access'])->assertSessionHas('error');
        $this->post(route('privacy-center.store'), ['type' => 'nonsense'])->assertSessionHasErrors('type');

        $request = PrivacyRequest::firstOrFail();
        $admin = $this->user('admin');

        $this->actingAs($admin)->patch(route('admin.security.privacy.update', $request), ['status' => 'completed'])->assertSessionHasErrors('resolution');
        $this->patch(route('admin.security.privacy.update', $request), ['status' => 'completed', 'resolution' => 'Copy provided.'])->assertSessionHas('status');

        $this->assertSame($admin->id, $request->fresh()->resolved_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'privacy.updated', 'model_id' => $request->id]);
        $this->actingAs($citizen)->get(route('privacy-center.index'))->assertSee('Copy provided.');
    }

    public function test_data_export_holds_only_the_persons_own_records_and_no_secrets(): void
    {
        $citizen = $this->user('public', ['password' => bcrypt('hidden')]);
        $other = $this->user();

        NotaryRequest::create(['request_number' => 'NR-MINE', 'user_id' => $citizen->id, 'document_type' => 'affidavit', 'purpose' => 'Mine']);
        NotaryRequest::create(['request_number' => 'NR-THEIRS', 'user_id' => $other->id, 'document_type' => 'affidavit', 'purpose' => 'Theirs']);

        $response = $this->actingAs($citizen)->get(route('privacy-center.export'))->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('NR-MINE', $body);
        $this->assertStringNotContainsString('NR-THEIRS', $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'privacy.exported']);
    }

    public function test_incidents_are_recorded_validated_and_audited(): void
    {
        $this->actingAs($this->user('admin'))->post(route('admin.security.incidents.store'), ['title' => 'x', 'severity' => 'nonsense', 'description' => 'y'])->assertSessionHasErrors('severity');

        $this->post(route('admin.security.incidents.store'), ['title' => 'Sample', 'severity' => 'high', 'description' => 'Sample event'])->assertSessionHas('status');

        $incident = SecurityIncident::firstOrFail();

        $this->patch(route('admin.security.incidents.update', $incident), ['status' => 'resolved', 'actions' => 'Fixed'])->assertSessionHas('status');

        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame(2, AuditLog::where('action', 'like', 'security.incident_%')->count());
    }

    public function test_account_security_shows_masked_contacts_and_own_activity_only(): void
    {
        $user = $this->user('public', ['email' => 'person@example.test', 'mobile' => self::MOBILE]);
        AuditLog::create(['user_id' => $user->id, 'action' => 'auth.login']);
        AuditLog::create(['user_id' => $this->user()->id, 'action' => 'auth.login_failed']);

        $this->actingAs($user)->get(route('settings.edit'))->assertOk()
            ->assertSee('pe***@example.test')->assertSee('******6789')->assertDontSee(self::MOBILE)
            ->assertSee('Signed in')->assertDontSee('Failed sign-in');
    }

    public function test_responses_carry_security_headers(): void
    {
        $response = $this->get('/');

        $this->assertSame('SAMEORIGIN', $response->headers->get('x-frame-options'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('content-security-policy'));
    }

    public function test_masking_helpers(): void
    {
        $this->assertSame('pe***@example.test', DeliveryLog::maskEmail('person@example.test'));
        $this->assertSame('***', DeliveryLog::maskEmail(null));
        $this->assertSame('******6789', SmsService::mask(self::MOBILE));
    }

    public function test_account_check_result_is_cached_so_it_does_not_repeat(): void
    {
        $this->msg91();
        Cache::flush();
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response('10')]);

        $sms = new SmsService();
        $this->assertSame('ok', $sms->accountStatus());
        $this->assertSame('ok', $sms->accountStatus());

        Http::assertSentCount(1);
    }
}
