<?php

namespace Tests\Feature;

use App\Models\AdminMfaFactor;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin two-factor sign-in when it is MANDATORY (ADMIN_MFA_REQUIRED=true, as the live .env now sets it):
 * the real password login, the challenge, wrong and right codes, recovery codes, the setup-only state of
 * an Admin who is not enrolled, and that Citizen and Notary sign-in are unchanged. Every account is synthetic.
 */
class MandatoryAdminMfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Mandatory-demo-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['accounts.admin_mfa_required' => true]);
    }

    private function user(string $role, string $email, string $mobile): User
    {
        $user = new User;
        $user->forceFill(['name' => ucfirst($role).' Person', 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null])->save();

        return $user->fresh();
    }

    /** The real login endpoint, as the login page calls it. */
    private function login(string $email, string $password = self::PASSWORD)
    {
        return $this->postJson(route('login.submit'), ['mobile' => $email, 'password' => $password]);
    }

    /** An enrolled Admin, enrolled through the real pages; returns [admin, secret, recovery codes]. */
    private function enrolledAdmin(): array
    {
        $admin = $this->user('admin', 'mandatory.admin@example.test', '9433300001');
        config(['accounts.admin_mfa_required' => false]);
        $this->actingAs($admin)->post(route('admin.mfa.enroll'));
        $secret = AdminMfaFactor::where('user_id', $admin->id)->firstOrFail()->secret;
        $codes = $this->post(route('admin.mfa.confirm'), ['code' => AdminMfa::codeAt($secret, time())])->viewData('codes');
        config(['accounts.admin_mfa_required' => true]);
        $this->post(route('logout'));
        $this->flushSession();
        auth()->forgetGuards();

        return [$admin->fresh(), $secret, $codes];
    }

    public function test_an_enrolled_admin_signing_in_with_the_password_is_held_at_the_challenge(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->login($admin->email)->assertOk()->assertJsonPath('success', true);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
        $this->get(route('admin.users.index'))->assertRedirect(route('admin.mfa.challenge'));
        $this->getJson(route('admin.users.index'))->assertStatus(423);

        $this->post(route('admin.mfa.challenge.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));

        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)])->assertRedirect($admin->landingUrl());
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_failed')->count());
    }

    public function test_a_wrong_password_never_reaches_the_challenge(): void
    {
        [$admin] = $this->enrolledAdmin();

        $this->login($admin->email, 'not-the-password')->assertStatus(401);
        $this->get(route('admin.mfa.challenge'))->assertRedirect(route('login'));
    }

    public function test_a_recovery_code_signs_in_once_while_mandatory(): void
    {
        [$admin, , $codes] = $this->enrolledAdmin();

        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $codes[2]])->assertRedirect($admin->landingUrl());
        $this->get(route('admin.dashboard'))->assertOk();

        $this->post(route('logout'));
        $this->flushSession();
        auth()->forgetGuards();
        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $codes[2]])->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_it_cannot_be_turned_off_while_mandatory(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->login($admin->email);
        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)]);
        $this->delete(route('admin.mfa.disable'), ['code' => AdminMfa::codeAt($secret, time() + 30)])->assertSessionHas('error');

        $this->assertTrue(app(AdminMfa::class)->enrolled($admin));
    }

    public function test_an_admin_without_two_factor_can_only_reach_the_setup_page(): void
    {
        $admin = $this->user('admin', 'mandatory.new@example.test', '9433300002');

        $this->login($admin->email)->assertOk();
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.users.index'))->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.mfa.show'))->assertOk();
        // Step-up needs a two-factor code, so without a factor the step-up page sends the Admin to setup.
        $this->get(route('admin.reauth.show'))->assertRedirect(route('admin.mfa.show'));
    }

    public function test_citizen_and_notary_sign_in_are_unchanged(): void
    {
        $citizen = $this->user('public', 'mandatory.citizen@example.test', '9433300003');
        $notary = $this->user('notary', 'mandatory.notary@example.test', '9433300004');

        $this->login($citizen->email)->assertOk()->assertJsonPath('success', true);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('admin.mfa.challenge'))->assertStatus(403);
        $this->get(route('admin.dashboard'))->assertStatus(403);
        $this->post(route('logout'));
        $this->flushSession();
        auth()->forgetGuards();

        $this->login($notary->email)->assertOk()->assertJsonPath('success', true);
        $this->get(route('notary.dashboard'))->assertOk();
        $this->get(route('admin.users.index'))->assertStatus(403);
    }

    public function test_signed_out_visitors_cannot_reach_admin_or_two_factor_routes(): void
    {
        foreach (['admin.dashboard', 'admin.users.index', 'admin.mfa.show', 'admin.mfa.challenge', 'admin.reauth.show'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }

        $this->post(route('admin.mfa.challenge.verify'), ['code' => '123456'])->assertRedirect(route('login'));
    }
}
