<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The mobile email fallback for both portal roles: Citizen and Notary. Each code goes only to the
 * signed-in account's own verified address, records stay per account and per purpose, and neither
 * role reaches the other's pages. Every test ends by proving no HTTP request (no SMS provider) was made.
 */
class MobileEmailFallbackRolesTest extends TestCase
{
    use RefreshDatabase;

    private const CITIZEN_MOBILE = '9876500011';

    private const NOTARY_MOBILE = '9876500022';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.msg91.email_fallback' => true]);
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    private function account(string $role, string $email, string $mobile, array $extra = []): User
    {
        $user = User::factory()->create($extra + [
            'role' => $role, 'email' => $email, 'mobile' => $mobile, 'email_verified_at' => now(), 'mobile_verified_at' => null,
            'notary_approved_at' => $role === 'notary' ? now() : null, 'password' => Hash::make('a-good-password'),
        ]);

        return $user->fresh();
    }

    private function citizen(): User
    {
        return $this->account('public', 'citizen.roles@example.test', self::CITIZEN_MOBILE);
    }

    private function notary(array $extra = []): User
    {
        return $this->account('notary', 'notary.roles@example.test', self::NOTARY_MOBILE, $extra);
    }

    /** @return list<array{to: string, body: string}> */
    private function sent(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'body' => (string) $m->getOriginalMessage()->getTextBody()])
            ->values()->all();
    }

    private function codeFor(string $address): ?string
    {
        $mail = collect($this->sent())->where('to', $address)->last();
        preg_match('/mobile verification code is (\d{6})/', $mail['body'] ?? '', $m);

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

    private function wrong(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }

    public function test_an_approved_notary_completes_the_fallback_through_their_own_address_only(): void
    {
        $notary = $this->notary();
        $this->citizen();

        $this->send($notary)->assertRedirect(route('verification.mobile.notice'));

        $this->assertSame(['notary.roles@example.test'], array_column($this->sent(), 'to'), 'one email, to the notary only');

        $this->verify($notary, $this->codeFor('notary.roles@example.test'))->assertRedirect(route('notary.dashboard'))
            ->assertSessionHas('status', 'Mobile step complete: Verified via Email Fallback. No SMS was sent, so your number is not confirmed by SMS.');

        $notary->refresh();
        $this->assertTrue($notary->mobileFallbackVerified());
        $this->assertSame([User::METHOD_EMAIL_FALLBACK, User::STATUS_FALLBACK_COMPLETED, User::SMS_NOT_SENT, self::NOTARY_MOBILE],
            [$notary->mobile_verification_method, $notary->mobile_verification_status, $notary->mobile_sms_status, $notary->mobile_fallback_number]);
        $this->assertNull($notary->mobile_verified_at, 'never SMS proof');
        $this->assertFalse($notary->hasVerifiedMobile());
        $this->assertFalse($notary->canRecoverByMobile(), 'SMS password reset still needs a real SMS');
        $this->assertTrue($notary->isApprovedNotary(), 'approval untouched');
    }

    public function test_a_notary_wrong_code_counts_down_and_locks_and_an_expired_code_is_refused(): void
    {
        $notary = $this->notary();
        $this->send($notary);
        $code = $this->codeFor($notary->email);

        $this->verify($notary, $this->wrong($code))->assertSessionHasErrors(['otp' => 'Incorrect code. You have 4 attempts left.']);
        foreach (range(2, OtpService::MAX_ATTEMPTS) as $i) {
            $this->verify($notary, $this->wrong($code));
        }
        $this->verify($notary, $code)->assertSessionHasErrors(['otp' => 'Too many incorrect attempts. Please request a new code later.']);
        $this->assertFalse($notary->fresh()->mobileFallbackVerified());

        $this->travel(20)->minutes();
        $this->send($notary)->assertSessionHas('status');
        $fresh = $this->codeFor($notary->email);
        $this->travel(OtpService::ttl() * 60 + 1)->seconds();
        $this->verify($notary, $fresh)->assertSessionHasErrors(['otp' => 'The code is invalid or has expired. Please request a new one.']);
        $this->assertFalse($notary->fresh()->mobileFallbackVerified());
    }

    public function test_a_notary_has_the_same_cooldown_and_hourly_limit(): void
    {
        $notary = $this->notary();
        $this->send($notary);
        $this->send($notary)->assertSessionHas('error', OtpService::userMessage(OtpService::COOLDOWN));

        foreach (range(2, OtpService::MAX_PER_HOUR) as $i) {
            $this->travel(61)->seconds();
            $this->send($notary)->assertSessionHas('status');
        }
        $this->travel(61)->seconds();
        $this->send($notary)->assertSessionHas('error', OtpService::userMessage(OtpService::HOURLY_LIMIT));
        $this->assertCount(OtpService::MAX_PER_HOUR, $this->sent());
    }

    public function test_one_role_s_limits_and_lock_out_never_touch_the_other(): void
    {
        $citizen = $this->citizen();
        $notary = $this->notary();

        $this->send($notary);
        foreach (range(1, OtpService::MAX_ATTEMPTS) as $i) {
            $this->verify($notary, $this->wrong((string) $this->codeFor($notary->email)));
        }

        $this->send($citizen)->assertSessionHas('status');
        $this->verify($citizen, $this->codeFor($citizen->email))->assertRedirect(route('dashboard'));
        $this->assertTrue($citizen->fresh()->mobileFallbackVerified());
        $this->assertFalse($notary->fresh()->mobileFallbackVerified());
    }

    public function test_a_code_from_one_account_never_verifies_another(): void
    {
        $citizen = $this->citizen();
        $notary = $this->notary();

        $this->send($citizen);
        $this->send($notary);
        $citizenCode = $this->codeFor($citizen->email);
        $notaryCode = $this->codeFor($notary->email);

        if ($citizenCode !== $notaryCode) {
            $this->verify($notary, $citizenCode)->assertSessionHasErrors('otp');
            $this->verify($citizen, $notaryCode)->assertSessionHasErrors('otp');
        }
        $this->assertFalse($citizen->fresh()->mobileFallbackVerified());
        $this->assertFalse($notary->fresh()->mobileFallbackVerified());

        $this->verify($notary, $notaryCode)->assertRedirect(route('notary.dashboard'));
        $this->assertTrue($notary->fresh()->mobileFallbackVerified());
        $this->assertFalse($citizen->fresh()->mobileFallbackVerified(), 'the other account is unchanged');
    }

    public function test_email_otp_and_mobile_fallback_leave_separate_histories_for_each_role(): void
    {
        $service = app(OtpService::class);

        foreach ([['public', 'citizen.h@example.test', self::CITIZEN_MOBILE, 'dashboard'], ['notary', 'notary.h@example.test', self::NOTARY_MOBILE, 'notary.dashboard']] as [$role, $email, $mobile, $home]) {
            $user = $this->account($role, $email, $mobile, ['email_verified_at' => null]);

            // Email OTP proves the email only.
            [$result, $emailCode] = $service->issueEmailCode($user);
            $this->assertSame(OtpService::ISSUED, $result);
            $this->assertNotSame(OtpService::OK, $service->verifyMobileFallbackCode($user, $emailCode), 'an email code is not a mobile code');
            $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.code'), ['code' => $emailCode]);
            $user->refresh();
            $this->assertTrue($user->hasVerifiedEmail());
            $this->assertFalse($user->mobileFallbackVerified(), 'email OTP does not complete the mobile step');

            // The mobile fallback proves the mobile step only, and leaves the email record alone.
            $emailVerifiedAt = $user->email_verified_at;
            $this->send($user);
            $this->verify($user, $this->codeFor($email))->assertRedirect(route($home));
            $user->refresh();
            $this->assertEquals($emailVerifiedAt, $user->email_verified_at);
            $this->assertTrue($user->mobileFallbackVerified());

            $events = DB::table('verification_events')->where('user_id', $user->id)->orderBy('id')->get();
            $this->assertSame([['email', 'email_otp', 'email', null], ['mobile', 'email_fallback', 'email', 'not_sent']],
                $events->map(fn ($e) => [$e->purpose, $e->method, $e->delivered_via, $e->sms_status])->all(), "{$role} history");
        }

        $this->assertSame(4, DB::table('verification_events')->count());
        $this->assertSame(0, DB::table('otp_verifications')->count(), 'used codes are removed');
    }

    public function test_status_is_shown_on_each_role_s_own_pages_and_to_the_admin(): void
    {
        $citizen = $this->citizen();
        $notary = $this->notary();
        foreach ([$citizen, $notary] as $user) {
            $this->send($user);
            $this->verify($user, $this->codeFor($user->email));
        }

        $this->actingAs($citizen)->get(route('dashboard'))->assertOk()->assertSee('Verified via Email Fallback');
        $this->actingAs($citizen)->get(route('user.profile'))->assertOk()->assertSee('Verified via Email Fallback')->assertSee('No SMS sent');
        $this->actingAs($notary)->get(route('notary.profile'))->assertOk()->assertSee('Verified via Email Fallback')->assertSee('No SMS sent');

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin.roles@example.test', 'email_verified_at' => now()]);
        foreach ([$citizen, $notary] as $user) {
            $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()->assertSee('Verified via Email Fallback');
        }
    }

    public function test_neither_role_reaches_the_other_s_pages_or_any_account_record(): void
    {
        $citizen = $this->citizen();
        $notary = $this->notary();

        $this->assertSame(403, $this->actingAs($citizen)->get(route('notary.profile'))->status());
        $this->assertSame(403, $this->actingAs($citizen)->get(route('notary.dashboard'))->status());
        $this->assertSame(403, $this->actingAs($notary)->get(route('dashboard'))->status());
        $this->assertSame(403, $this->actingAs($notary)->get(route('user.profile'))->status());

        foreach ([[$citizen, $notary], [$notary, $citizen], [$citizen, $citizen], [$notary, $notary]] as [$viewer, $subject]) {
            $this->assertContains($this->actingAs($viewer)->get(route('admin.users.show', $subject))->status(), [302, 403], 'admin user details stay admin-only');
        }
    }

    public function test_a_notary_awaiting_approval_completes_the_fallback_but_stays_unapproved(): void
    {
        $pending = $this->notary(['notary_approved_at' => null]);

        $this->actingAs($pending)->get(route('notary.pending'))->assertOk()->assertSee(route('verification.mobile.notice'));
        $this->actingAs($pending)->get(route('verification.mobile.notice'))->assertOk();

        $this->send($pending)->assertRedirect(route('verification.mobile.notice'));
        $this->assertSame([$pending->email], array_column($this->sent(), 'to'), 'one email, to their own address');

        $this->verify($pending, $this->codeFor($pending->email))->assertRedirect(route('notary.pending'));

        $pending->refresh();
        $this->assertTrue($pending->mobileFallbackVerified());
        $this->assertNull($pending->mobile_verified_at);
        $this->assertNull($pending->notary_approved_at, 'the fallback never approves a Notary');
        $this->assertFalse($pending->isApprovedNotary());
        $this->actingAs($pending)->get(route('notary.dashboard'))->assertRedirect(route('notary.pending'));
        $this->actingAs($pending)->get(route('notary.profile'))->assertRedirect(route('notary.pending'));
        $this->actingAs($pending)->get(route('notary.pending'))->assertOk()->assertSee('Verified via Email Fallback');
        $this->assertSame(1, DB::table('verification_events')->where('user_id', $pending->id)->where('method', 'email_fallback')->count());
    }

    public function test_without_the_fallback_a_notary_awaiting_approval_still_gets_no_sms_step(): void
    {
        config(['services.msg91.email_fallback' => false]);
        $pending = $this->notary(['notary_approved_at' => null]);

        $this->actingAs($pending)->get(route('notary.pending'))->assertOk()->assertDontSee(route('verification.mobile.notice'));
        $this->actingAs($pending)->get(route('verification.mobile.notice'))->assertRedirect(route('notary.pending'));
        $this->send($pending)->assertRedirect(route('notary.pending'));

        $this->assertSame([], $this->sent());
        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertSame(0, DB::table('message_deliveries')->where('channel', 'sms')->count());
    }

    public function test_the_notary_dashboard_shows_the_verification_panel(): void
    {
        $notary = $this->notary();

        $this->actingAs($notary)->get(route('notary.dashboard'))->assertOk()
            ->assertSee('Verification status')->assertSee('Pending')->assertSee(route('verification.mobile.notice'));

        $this->send($notary);
        $this->verify($notary, $this->codeFor($notary->email));

        $this->actingAs($notary->fresh())->get(route('notary.dashboard'))->assertOk()
            ->assertSee('Verification status')->assertSee('Verified via Email Fallback')->assertSee('Not Sent / Temporarily Unavailable')
            ->assertDontSee('Verified (Email Fallback)')->assertDontSee('Delivered and verified by SMS')
            ->assertDontSee(route('verification.mobile.notice'));
    }

    public function test_the_admin_sees_a_read_only_history_for_each_account(): void
    {
        $service = app(OtpService::class);
        $citizen = $this->account('public', 'citizen.a@example.test', self::CITIZEN_MOBILE, ['email_verified_at' => null]);
        $notary = $this->notary();

        [, $emailCode] = $service->issueEmailCode($citizen);
        $this->actingAs($citizen)->from(route('verification.notice'))->post(route('verification.code'), ['code' => $emailCode]);
        foreach ([$citizen->fresh(), $notary] as $user) {
            $this->send($user);
            $this->verify($user, $this->codeFor($user->email));
        }

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin.h@example.test', 'email_verified_at' => now()]);
        $hash = DB::table('verification_events')->where('user_id', $citizen->id)->value('target_hash');

        $this->actingAs($admin)->get(route('admin.users.show', $citizen))->assertOk()
            ->assertSee('Verification history')->assertSeeInOrder(['Verified via Email Fallback', 'Email OTP'])
            ->assertSee('ci***@example.test')->assertSee('******0011')->assertSee('Not sent')
            ->assertDontSee($hash) // the account details above show the full address to the admin; the history never adds the hash
            ->assertDontSee('******0022', false)->assertDontSee('Verified (Email Fallback)');

        $this->actingAs($admin)->get(route('admin.users.show', $notary))->assertOk()
            ->assertSee('******0022')->assertDontSee('******0011')->assertDontSee('Email OTP');

        $fresh = User::factory()->create(['role' => 'public', 'email' => 'nobody@example.test']);
        $this->actingAs($admin)->get(route('admin.users.show', $fresh))->assertOk()
            ->assertSee('No verification has been recorded for this account yet.');
    }

    public function test_the_admin_page_still_works_before_the_history_table_exists(): void
    {
        $citizen = $this->citizen();
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin.m@example.test', 'email_verified_at' => now()]);

        \Illuminate\Support\Facades\Schema::drop('verification_events'); // the in-memory test database only
        \App\Services\VerificationLedger::forgetSchemaState();

        try {
            $this->actingAs($admin)->get(route('admin.users.show', $citizen))->assertOk()
                ->assertSee('Verification history appears here once the database update for it has been applied.');
        } finally {
            \App\Services\VerificationLedger::forgetSchemaState();
        }
    }
}
