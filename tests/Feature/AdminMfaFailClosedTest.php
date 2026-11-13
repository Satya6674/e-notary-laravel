<?php

namespace Tests\Feature;

use App\Models\AdminMfaFactor;
use App\Models\User;
use App\Services\AdminMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H-1 (pre-deployment audit 2026-11-11): mandatory Admin two-factor sign-in FAILS CLOSED. When its
 * state cannot be established (table missing while required, factor lookup failing, the service
 * throwing), Admin pages answer 503 and nobody gets in on a password alone. Sign-out and the language
 * switch stay open. Citizens and Notaries are never affected.
 *
 * Synthetic accounts only, on the isolated test database (TestCase refuses any other). No mail leaves
 * (Notification::fake); logs go to the discard channel.
 */
class AdminMfaFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'FailClosed-demo-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['accounts.admin_mfa_required' => true]);
    }

    // ---------------------------------------------------------------- helpers

    private function user(string $role, string $email, string $mobile): User
    {
        $user = new User;
        $user->forceFill(['name' => ucfirst($role).' Person', 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null])->save();

        return $user->fresh();
    }

    private function login(string $email)
    {
        return $this->postJson(route('login.submit'), ['mobile' => $email, 'password' => self::PASSWORD]);
    }

    private function signOut(): void
    {
        $this->post(route('logout'));
        $this->flushSession();
        auth()->forgetGuards();
    }

    /**
     * An Admin enrolled through the real pages; returns [admin, secret, recovery codes]. Enrollment
     * spends the current 30-second step, so sign-in tests use the next one (replay protection).
     */
    private function enrolledAdmin(): array
    {
        $admin = $this->user('admin', 'failclosed.admin@example.test', '9422200001');
        config(['accounts.admin_mfa_required' => false]);
        $this->actingAs($admin)->post(route('admin.mfa.enroll'));
        $secret = AdminMfaFactor::where('user_id', $admin->id)->firstOrFail()->secret;
        $codes = $this->post(route('admin.mfa.confirm'), ['code' => AdminMfa::codeAt($secret, time())])->viewData('codes');
        config(['accounts.admin_mfa_required' => true]);
        $this->signOut();

        return [$admin->fresh(), $secret, $codes];
    }

    private function dropTable(): void
    {
        Schema::drop('admin_mfa_factors');
        AdminMfa::flushAvailability();
    }

    /** The table was there when first checked, then queries against it fail (a real QueryException). */
    private function breakLookups(): void
    {
        AdminMfa::flushAvailability();
        $this->assertTrue(AdminMfa::available());
        Schema::rename('admin_mfa_factors', 'admin_mfa_factors_unreachable');
    }

    private function assertClosed($response): void
    {
        $response->assertStatus(503)->assertSee('Two-factor sign-in cannot be checked');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------- normal behaviour (unchanged)

    public function test_a_valid_code_completes_a_normal_mfa_sign_in(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->login($admin->email)->assertOk()->assertJsonPath('success', true);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)])->assertRedirect($admin->landingUrl());
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_an_invalid_code_keeps_the_admin_at_the_challenge(): void
    {
        [$admin] = $this->enrolledAdmin();

        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
        $this->getJson(route('admin.users.index'))->assertStatus(423);
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$admin, , $codes] = $this->enrolledAdmin();

        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $codes[0]])->assertRedirect($admin->landingUrl());
        $this->get(route('admin.dashboard'))->assertOk();

        $this->signOut();
        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_a_required_factor_that_is_missing_sends_the_admin_to_setup_only(): void
    {
        $admin = $this->user('admin', 'unenrolled.admin@example.test', '9422200002');

        $this->login($admin->email)->assertOk();
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.users.index'))->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.mfa.show'))->assertOk();
    }

    // ---------------------------------------------------------------- fail closed

    public function test_a_missing_table_while_required_closes_every_admin_page(): void
    {
        [$admin] = $this->enrolledAdmin();
        $this->login($admin->email)->assertOk();
        Log::spy();
        $this->dropTable();

        foreach (['admin.dashboard', 'admin.users.index', 'admin.mfa.show', 'admin.mfa.challenge'] as $name) {
            $this->assertClosed($this->get(route($name)));
        }
        $this->post(route('admin.mfa.challenge.verify'), ['code' => '123456'])->assertStatus(503);
        $this->getJson(route('admin.users.index'))->assertStatus(503)->assertJsonMissingPath('redirect');

        Log::shouldHaveReceived('critical')->withArgs(fn ($message, $context) => ($context['reason'] ?? null) === 'table_missing');

        // Leaving is always possible.
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_a_missing_table_closes_admin_pages_even_for_an_admin_who_never_enrolled(): void
    {
        $admin = $this->user('admin', 'never.enrolled@example.test', '9422200003');
        $this->dropTable();

        $this->login($admin->email)->assertOk();
        $this->assertClosed($this->get(route('admin.dashboard')));
        $this->assertClosed($this->get(route('admin.mfa.show')));
    }

    public function test_while_optional_and_not_installed_the_feature_is_simply_off(): void
    {
        config(['accounts.admin_mfa_required' => false]);
        $admin = $this->user('admin', 'optional.admin@example.test', '9422200004');
        $this->dropTable();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_failing_factor_lookup_closes_admin_pages_whether_required_or_optional(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)]);
        $this->get(route('admin.dashboard'))->assertOk();

        Log::spy();
        $this->breakLookups();
        $this->assertClosed($this->get(route('admin.dashboard')));
        Log::shouldHaveReceived('critical')->withArgs(fn ($message, $context) => ($context['reason'] ?? null) === 'lookup_failed'
            && str_contains($context['exception'] ?? '', 'QueryException'));

        // Optional mode cannot tell whether this Admin enrolled, so it refuses too.
        config(['accounts.admin_mfa_required' => false]);
        $this->assertClosed($this->get(route('admin.users.index')));
    }

    public function test_an_exception_from_the_mfa_service_closes_admin_pages(): void
    {
        $admin = $this->user('admin', 'service.admin@example.test', '9422200005');
        $this->app->instance(AdminMfa::class, new class extends AdminMfa
        {
            public function enrolled(User $user): bool
            {
                throw new \RuntimeException('simulated two-factor service failure');
            }
        });

        $this->actingAs($admin);
        $this->assertClosed($this->get(route('admin.dashboard')));
        $this->assertClosed($this->get(route('admin.mfa.challenge')));
        $this->post(route('logout'))->assertRedirect();
    }

    public function test_an_exception_while_checking_a_code_never_marks_the_challenge_passed(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $this->app->instance(AdminMfa::class, new class extends AdminMfa
        {
            public function verify(User $user, string $input): string
            {
                throw new \RuntimeException('simulated failure while checking a code');
            }
        });

        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)])->assertStatus(500);
        $this->assertNull(session(AdminMfa::SESSION_KEY));
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
    }

    // ---------------------------------------------------------------- other roles

    public function test_citizen_and_notary_sign_in_are_unaffected_even_when_admin_mfa_cannot_be_checked(): void
    {
        $citizen = $this->user('public', 'failclosed.citizen@example.test', '9422200006');
        $notary = $this->user('notary', 'failclosed.notary@example.test', '9422200007');
        $this->dropTable();

        $this->login($citizen->email)->assertOk()->assertJsonPath('success', true);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('admin.dashboard'))->assertStatus(403);
        $this->signOut();

        $this->login($notary->email)->assertOk()->assertJsonPath('success', true);
        $this->get(route('notary.dashboard'))->assertOk();
        $this->get(route('admin.users.index'))->assertStatus(403);
    }

    public function test_public_pages_stay_open_when_admin_mfa_cannot_be_checked(): void
    {
        $this->dropTable();

        $this->get(route('home'))->assertOk();
        $this->get(route('login'))->assertOk();
    }

    // ---------------------------------------------------------------- deploy check

    public function test_the_deploy_check_fails_when_required_and_the_table_is_missing(): void
    {
        $this->artisan('enotary:deploy-check')->expectsOutputToContain('Admin two-factor table present');
        $this->dropTable();

        $this->artisan('enotary:deploy-check')
            ->expectsOutputToContain('Table admin_mfa_factors is missing')
            ->assertFailed();
    }
}
