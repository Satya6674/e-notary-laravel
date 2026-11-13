<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Sign-up policy: a citizen gets normal access only after BOTH the email link
 * and the SMS OTP for the mobile number succeed. Providers are mocked, so a
 * green run proves the gates and messages, not real delivery.
 */
class RegistrationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $captured = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->resetSupportCache();
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function citizen(array $overrides = []): User
    {
        static $i = 700;
        $i++;

        return User::create(array_merge([
            'name' => "Cit {$i}", 'email' => "cit{$i}@example.com", 'mobile' => '9300000'.$i,
            'password' => Hash::make('Secret123!'), 'role' => 'public',
            'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ], $overrides));
    }

    private function mockSms(int $times = 1): void
    {
        $this->captured = null;
        $this->mock(SmsService::class, function ($m) use ($times) {
            $m->shouldReceive('send')->times($times)->withArgs(function ($mobile, $otp) {
                $this->captured = $otp;

                return true;
            })->andReturn(true);
            $m->lastRequestId = null;
            $m->lastError = null;
        });
    }

    private function citizenPaths(): array
    {
        return ['/dashboard', '/user/upload', '/user/documents', '/user/requests', '/user/profile'];
    }

    /** User::mobileVerificationSupported() is cached per process; clear it between schema changes. */
    private function resetSupportCache(): void
    {
        $property = new ReflectionProperty(User::class, 'mobileVerificationSupported');
        $property->setValue(null, null);
    }

    // ------------------------------------------------------------- flows

    public function test_registration_requires_email_link_then_sms_code_before_the_dashboard(): void
    {
        $this->postJson('/custom-register', [
            'name' => 'New', 'email' => 'new@example.com', 'mobile' => '+91 93000 00001',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'public', 'accept_terms' => '1',
        ])->assertOk()->assertJsonPath('redirect', route('verification.notice'));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->mobile_verified_at);
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
        // No paid SMS before the email is verified. The one row is the email code, keyed to the account.
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', '9300000001')->count());
        $this->assertSame(1, DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->count());

        // Nothing verified: every citizen page and the mobile step itself go to the email notice.
        foreach (array_merge($this->citizenPaths(), ['/mobile/verify']) as $path) {
            $this->actingAs($user)->get($path)->assertRedirect(route('verification.notice'));
        }

        // Step 1: the signed email link verifies the email and lands on the mobile step.
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($link)->assertRedirect(route('verification.mobile.notice'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $user->refresh();

        foreach ($this->citizenPaths() as $path) {
            $this->actingAs($user)->get($path)->assertRedirect(route('verification.mobile.notice'));
        }
        $this->actingAs($user)->get('/mobile/verify')->assertOk()->assertSee('******0001')->assertSee('Send code by SMS');

        // Step 2: the SMS code.
        $this->mockSms();
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertRedirect()->assertSessionHas('status');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->captured);
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])
            ->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $user->refresh();
        $this->assertNotNull($user->mobile_verified_at);
        foreach ($this->citizenPaths() as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
        $this->actingAs($user)->get('/mobile/verify')->assertRedirect(route('dashboard'));
    }

    public function test_email_verified_but_mobile_unverified_is_blocked_everywhere_in_the_citizen_area(): void
    {
        $user = $this->citizen(['mobile_verified_at' => null]);

        foreach ($this->citizenPaths() as $path) {
            $this->actingAs($user)->get($path)->assertRedirect(route('verification.mobile.notice'));
        }
        $this->actingAs($user)->post('/user/upload', ['title' => 'x'])->assertRedirect(route('verification.mobile.notice'));
        $this->actingAs($user)->getJson('/dashboard')->assertForbidden();

        // Login lands on the mobile step, not the dashboard.
        $this->postJson('/custom-login', ['mobile' => $user->mobile, 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('redirect', route('verification.mobile.notice'));

        // Admin and notary areas are not affected by the citizen rule.
        $admin = $this->citizen(['role' => 'admin', 'mobile_verified_at' => null]);
        $notary = $this->citizen(['role' => 'notary', 'notary_approved_at' => now(), 'mobile_verified_at' => null]);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($notary)->get('/notary/dashboard')->assertOk();
    }

    public function test_mobile_verified_but_email_unverified_must_verify_email_first(): void
    {
        $user = $this->citizen(['email_verified_at' => null]);

        $this->postJson('/custom-login', ['mobile' => $user->mobile, 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('redirect', route('verification.notice'));

        foreach (array_merge($this->citizenPaths(), ['/mobile/verify']) as $path) {
            $this->actingAs($user)->get($path)->assertRedirect(route('verification.notice'));
        }

        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertRedirect(route('verification.notice'));
    }

    public function test_login_redirect_follows_the_verification_state(): void
    {
        $none = $this->citizen(['email_verified_at' => null, 'mobile_verified_at' => null]);
        $emailOnly = $this->citizen(['mobile_verified_at' => null]);
        $both = $this->citizen();

        foreach ([[$none, 'verification.notice'], [$emailOnly, 'verification.mobile.notice'], [$both, 'dashboard']] as [$u, $route]) {
            $this->postJson('/custom-login', ['mobile' => $u->email, 'password' => 'Secret123!'])
                ->assertOk()->assertJsonPath('redirect', route($route));
            $this->post('/logout');
        }

        // The email notice page itself forwards a verified email to the next step.
        $this->actingAs($emailOnly)->get('/email/verify')->assertRedirect(route('verification.mobile.notice'));
        $this->actingAs($both)->get('/email/verify')->assertRedirect(route('dashboard'));
    }

    public function test_wrong_code_limit_expiry_and_resend_cooldown_on_the_mobile_step(): void
    {
        $user = $this->citizen(['mobile_verified_at' => null]);
        $this->mockSms();
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');

        // Resend inside the cooldown: clear message, no second SMS.
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');
        $this->assertDatabaseCount('otp_verifications', 1);

        // Wrong codes: five attempts, then blocked even for the right code.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => '000000'])
                ->assertRedirect()->assertSessionHasErrors(['otp' => $i < 4 ? 'Incorrect code. You have '.(4 - $i).' attempts left.' : 'Too many incorrect attempts. Please request a new code later.']);
        }
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])
            ->assertSessionHasErrors(['otp' => 'Too many incorrect attempts. Please request a new code later.']);
        $this->assertNull($user->fresh()->mobile_verified_at);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.mobile.notice'));

        // Expiry: a fresh code is useless after ten minutes.
        // The lock-out must hold against requesting a new code, until it ends.
        $this->mockSms(0);
        $this->travel(61)->seconds();
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');

        $this->travel(16)->minutes();
        $this->mockSms();
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');
        $this->travel(11)->minutes();
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])
            ->assertSessionHasErrors(['otp' => 'The code is invalid or has expired. Please request a new one.']);

        // A new code after the cooldown works and unlocks the account.
        $this->mockSms();
        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_changing_the_number_on_the_mobile_step_needs_a_new_code_and_rejects_duplicates(): void
    {
        $other = $this->citizen();
        $user = $this->citizen(['mobile_verified_at' => null]);

        $this->actingAs($user)->post(route('mobile.verification.number'), ['mobile' => $other->mobile, 'current_password' => 'Secret123!'])->assertSessionHasErrors('mobile');
        $this->actingAs($user)->post(route('mobile.verification.number'), ['mobile' => '12345', 'current_password' => 'Secret123!'])->assertSessionHasErrors('mobile');
        $this->assertSame($user->mobile, $user->fresh()->mobile);

        $this->actingAs($user)->post(route('mobile.verification.number'), ['mobile' => '+91 93999 99999', 'current_password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('9399999999', $user->fresh()->mobile);
        $this->assertNull($user->fresh()->mobile_verified_at);

        $this->mockSms();
        $this->actingAs($user)->post(route('mobile.verification.send'));
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->captured])->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->mobile_verified_at);

        // Verified: neither the step page nor the profile form can change the number.
        $this->actingAs($user->fresh())->post(route('mobile.verification.number'), ['mobile' => '9377777777'])->assertRedirect(route('dashboard'));
        $this->assertSame('9399999999', $user->fresh()->mobile);

        $this->actingAs($user->fresh())->put(route('user.profile.update'), ['name' => $user->name, 'email' => $user->email, 'mobile' => '9388888888'])
            ->assertSessionHasErrors('mobile');
        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_verification_cannot_be_bypassed_with_client_supplied_fields_or_duplicate_sign_ups(): void
    {
        $this->postJson('/custom-register', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'mobile' => '9311111111', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'public', 'accept_terms' => '1',
            'email_verified_at' => now()->toDateTimeString(), 'mobile_verified_at' => now()->toDateTimeString(),
        ])->assertOk();
        $user = User::where('email', 'sneaky@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->mobile_verified_at);

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        // No code was ever issued: any code is rejected, and extra fields are ignored.
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => '123456', 'mobile_verified_at' => now()->toDateTimeString()])
            ->assertSessionHasErrors('otp');
        $this->actingAs($user)->post(route('mobile.verification.number'), ['mobile' => $user->mobile, 'mobile_verified_at' => now()->toDateTimeString()]);
        $this->assertNull($user->fresh()->mobile_verified_at);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.mobile.notice'));

        // Duplicate registration with the same email or the same (formatted) mobile is refused.
        $this->postJson('/custom-register', ['name' => 'Dup', 'email' => 'sneaky@example.com', 'mobile' => '9322222222', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'public', 'accept_terms' => '1'])
            ->assertStatus(422);
        $this->postJson('/custom-register', ['name' => 'Dup', 'email' => 'dup@example.com', 'mobile' => '+91 93111 11111', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'public', 'accept_terms' => '1'])
            ->assertStatus(422);
        $this->assertSame(1, User::where('mobile', '9311111111')->count());
    }

    public function test_password_reset_by_emailed_code_works_from_the_mobile_number(): void
    {
        $user = $this->citizen();
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        $this->post('/forgot-password', ['identifier' => $user->mobile])->assertRedirect();
        $code = Notification::sent($user, \App\Notifications\PasswordResetCodeNotification::class)->last()->code;
        $this->post('/verify-otp', ['otp' => $code])->assertRedirect(route('password.reset.form'));
        $this->post('/reset-password-form', ['password' => 'Newpass123!', 'password_confirmation' => 'Newpass123!'])->assertRedirect(route('home'));

        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->postJson('/custom-login', ['mobile' => $user->mobile, 'password' => 'Newpass123!'])
            ->assertOk()->assertJsonPath('redirect', route('dashboard'));
    }

    public function test_requirement_is_not_enforced_until_the_mobile_verified_at_column_exists(): void
    {
        // Mirrors the live database before migration 2026_10_29_000001 has run.
        Schema::table('users', fn ($table) => $table->dropColumn('mobile_verified_at'));
        $this->resetSupportCache();
        $this->assertFalse(User::mobileVerificationSupported());

        $user = User::create([
            'name' => 'Legacy', 'email' => 'legacy@example.com', 'mobile' => '9344444444',
            'password' => Hash::make('Secret123!'), 'role' => 'public', 'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/mobile/verify')->assertRedirect(route('dashboard'));
        $this->postJson('/custom-login', ['mobile' => $user->mobile, 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('redirect', route('dashboard'));
    }
}
