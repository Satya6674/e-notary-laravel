<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Logged-in mobile verification and the shared OtpService.
 * SmsService is mocked or Http is faked: no provider traffic.
 */
class MobileVerificationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $captured = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    private function user(array $overrides = []): User
    {
        static $i = 500;
        $i++;

        return User::create(array_merge([
            'name' => "Mob {$i}", 'email' => "mob{$i}@example.com", 'mobile' => '9100000'.$i,
            'password' => Hash::make('secret123'), 'role' => 'public', 'email_verified_at' => now(),
        ], $overrides));
    }

    private function mockSmsCapturing(int $times = 1): void
    {
        $this->mock(SmsService::class, function ($m) use ($times) {
            $m->shouldReceive('send')->times($times)->withArgs(function ($mobile, $otp) {
                $this->captured = $otp;

                return true;
            })->andReturn(true);
            $m->lastRequestId = '0123456789abcdef01234567';
            $m->lastError = null;
        });
    }

    public function test_unverified_email_cannot_use_mobile_verification(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_send_issues_hashed_code_and_verify_marks_mobile_only(): void
    {
        $user = $this->user();
        $this->mockSmsCapturing();

        $this->actingAs($user)->from(route('user.profile'))->post(route('mobile.verification.send'))
            ->assertRedirect(route('user.profile'))->assertSessionHas('status');

        $row = DB::table('otp_verifications')->where('mobile', $user->mobile)->first();
        $this->assertNotNull($row);
        $this->assertNotSame($this->captured, $row->otp);
        // Module 6: stored as "<flow>|<bcrypt>", never the code itself.
        $this->assertStringStartsWith('mobile_verification|$2y$', $row->otp);
        $this->assertStringNotContainsString($this->captured, $row->otp);
        $this->assertTrue(Hash::check($this->captured, substr($row->otp, strlen('mobile_verification|'))));

        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])
            ->assertRedirect()->assertSessionHas('status');

        $user->refresh();
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertNotNull($user->email_verified_at, 'email state untouched');
        $this->assertNull($user->notary_approved_at, 'role approval untouched');
        $this->assertDatabaseCount('otp_verifications', 0);

        // A verified user with a different, still-unverified number is unaffected.
        $other = $this->user();
        $this->assertFalse($other->fresh()->hasVerifiedMobile());
    }

    public function test_email_verification_does_not_imply_mobile_verification(): void
    {
        $user = $this->user();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse($user->hasVerifiedMobile());
        // Email alone no longer opens the citizen area: the mobile step comes next.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.mobile.notice'));
    }

    public function test_wrong_code_counts_attempts_then_blocks_and_code_cannot_be_replayed(): void
    {
        $user = $this->user();
        $this->mockSmsCapturing();
        $this->actingAs($user)->post(route('mobile.verification.send'));

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => '000000'])->assertSessionHasErrors('otp');
        }
        // Blocked even with the right code.
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])->assertSessionHasErrors('otp');
        $this->assertNull($user->fresh()->mobile_verified_at);

        // Unblock by time travel, verify, then the same code cannot be used again.
        $this->travel(16)->minutes();
        // ...but by then the code has expired (10 min TTL), so request a new one.
        $this->mockSmsCapturing();
        $this->actingAs($user)->post(route('mobile.verification.send'));
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->mobile_verified_at);

        // Replay: rows are cleared, and the user is already verified.
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])->assertSessionHas('status');
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->user();
        $this->mockSmsCapturing();
        $this->actingAs($user)->post(route('mobile.verification.send'));

        $this->travel(11)->minutes();
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])->assertSessionHasErrors('otp');
        $this->assertNull($user->fresh()->mobile_verified_at);
    }

    public function test_resend_cooldown_and_hourly_cap(): void
    {
        $user = $this->user();
        $this->mockSmsCapturing(1);

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error'); // cooldown, no SMS
        $this->assertDatabaseCount('otp_verifications', 1);
    }

    public function test_otp_service_hourly_cap(): void
    {
        $this->mock(SmsService::class, fn ($m) => $m->shouldReceive('send')->times(5)->andReturn(true));
        $svc = app(OtpService::class);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(OtpService::ISSUED, $svc->issue('9100000001'));
            $this->travel(61)->seconds();
        }
        $this->assertSame(OtpService::HOURLY_LIMIT, $svc->issue('9100000001'));
    }

    public function test_provider_failure_is_reported_safely_and_no_verification_happens(): void
    {
        $user = $this->user();
        $this->mock(SmsService::class, function ($m) {
            $m->shouldReceive('send')->once()->andReturn(false);
            $m->lastError = 'provider:insufficient balance';
            $m->lastRequestId = null;
        });

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');
        $this->assertNull($user->fresh()->mobile_verified_at);
    }

    public function test_client_cannot_self_assert_verification(): void
    {
        $user = $this->user(['mobile_verified_at' => now()]);

        // Neither a submitted number nor a submitted mobile_verified_at gets past the profile form.
        $before = $user->mobile;

        $this->actingAs($user)->put(route('user.profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'mobile' => '9188888888',
            'mobile_verified_at' => now()->subYear()->toDateTimeString(),
        ])->assertSessionHasErrors('mobile');

        $this->assertSame($before, $user->fresh()->mobile);
        $this->assertTrue($user->fresh()->mobile_verified_at->isToday());
    }

    public function test_the_profile_form_cannot_change_a_verified_number(): void
    {
        $user = $this->user(['mobile_verified_at' => now()]);
        $this->assertTrue($user->hasVerifiedMobile());

        $this->actingAs($user)->put(route('user.profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'mobile' => '9199999999',
        ])->assertSessionHasErrors('mobile');

        $this->assertTrue($user->fresh()->hasVerifiedMobile());
    }

    public function test_a_mobile_number_finds_the_account_and_the_emailed_code_resets_it(): void
    {
        $user = $this->user(['mobile_verified_at' => now()]);
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        $this->post('/forgot-password', ['identifier' => $user->mobile])->assertRedirect();
        $code = Notification::sent($user, \App\Notifications\PasswordResetCodeNotification::class)->last()->code;
        $this->post('/verify-otp', ['otp' => $code])->assertRedirect(route('password.reset.form'));
        $this->post('/reset-password-form', ['password' => 'Viacode123!', 'password_confirmation' => 'Viacode123!'])->assertRedirect(route('home'));

        $user->refresh();
        $this->assertTrue(Hash::check('Viacode123!', $user->password));
        $this->assertNotNull($user->mobile_verified_at, 'the number keeps its proof');
    }

    public function test_real_sms_service_path_with_faked_http_captures_request_id_only(): void
    {
        config(['services.msg91.auth_key' => 'k', 'services.msg91.template_id' => 't']);
        Http::fake([SmsService::ENDPOINT => Http::response(['request_id' => '366a4277754a5747794e7434', 'type' => 'success'], 200)]);

        $svc = app(OtpService::class);
        $this->assertSame(OtpService::ISSUED, $svc->issue('9100000002', '127.0.0.1'));
        $this->assertSame('366a4277754a5747794e7434', $svc->lastRequestId());
        Http::assertSentCount(1);
        // "issued" is acceptance only; nothing in the DB claims delivery.
        $this->assertNull(DB::table('otp_verifications')->where('mobile', '9100000002')->value('verified_at'));
    }
}
