<?php

namespace Tests\Feature;

use App\Models\AdminMfaFactor;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\AdminMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin two-factor sign-in (TOTP + recovery codes): enrollment, the sign-in challenge, wrong codes,
 * lock-out, replay, recovery codes, the "required" setting, encryption at rest and console recovery.
 * Every account is synthetic.
 */
class AdminMfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Admin-demo-pass-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = new User;
        $this->admin->forceFill([
            'name' => 'MFA Admin', 'email' => 'mfa.admin@example.test', 'mobile' => '9466600001', 'password' => Hash::make(self::PASSWORD),
            'role' => 'admin', 'email_verified_at' => now(),
        ])->save();
    }

    /** Enrolls the Admin through the real pages and returns [secret, recovery codes]. */
    private function enroll(): array
    {
        $this->actingAs($this->admin)->post(route('admin.mfa.enroll'))->assertRedirect(route('admin.mfa.show'));
        $secret = AdminMfaFactor::where('user_id', $this->admin->id)->firstOrFail()->secret;

        $this->get(route('admin.mfa.show'))->assertOk()->assertSee(trim(chunk_split($secret, 4, ' ')));

        $response = $this->post(route('admin.mfa.confirm'), ['code' => AdminMfa::codeAt($secret, time())])->assertOk();
        $codes = $response->viewData('codes');

        return [$secret, $codes];
    }

    /** A new sign-in of the same Admin: a fresh session that has not passed the challenge. */
    private function newSession(): void
    {
        $this->flushSession();
        $this->actingAs($this->admin->fresh());
    }

    public function test_the_migration_creates_the_table_without_touching_users(): void
    {
        $this->assertTrue(AdminMfa::available());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'mfa_secret'));
    }

    public function test_an_admin_without_two_factor_is_not_held_up_when_it_is_optional(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_enrollment_needs_a_valid_code_and_is_audited_and_alerted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.mfa.enroll'));
        $this->post(route('admin.mfa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse(app(AdminMfa::class)->enrolled($this->admin));

        [$secret, $codes] = $this->enroll();

        $this->assertTrue(app(AdminMfa::class)->enrolled($this->admin));
        $this->assertCount(AdminMfa::RECOVERY_CODES, $codes);
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_enabled')->count());
        Notification::assertSentTo($this->admin, SecurityAlertNotification::class, fn ($n) => $n->event === 'mfa_enabled');
    }

    public function test_secret_and_recovery_codes_are_not_readable_in_the_database(): void
    {
        [$secret, $codes] = $this->enroll();
        $row = DB::table('admin_mfa_factors')->where('user_id', $this->admin->id)->first();

        $this->assertStringNotContainsString($secret, $row->secret);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), $row->recovery_codes);
        }
        $this->assertArrayNotHasKey('secret', AdminMfaFactor::first()->toArray());
    }

    public function test_after_enrollment_a_new_sign_in_is_held_at_the_challenge_until_a_valid_code(): void
    {
        [$secret] = $this->enroll();
        $this->newSession();

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
        $this->get(route('admin.users.index'))->assertRedirect(route('admin.mfa.challenge'));
        $this->getJson(route('admin.users.index'))->assertStatus(423);
        $this->get(route('admin.mfa.challenge'))->assertOk();

        $this->post(route('admin.mfa.challenge.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_failed')->count());

        // The enrollment used the current step, so the next step's code is the one that works now.
        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)])
            ->assertRedirect($this->admin->landingUrl());
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        [$secret] = $this->enroll();
        $code = AdminMfa::codeAt($secret, time() + 30);

        $this->newSession();
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $code])->assertRedirect();

        $this->newSession();
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_five_wrong_codes_lock_the_challenge_even_for_a_right_code(): void
    {
        [$secret] = $this->enroll();
        $this->newSession();

        for ($i = 0; $i < AdminMfa::MAX_ATTEMPTS; $i++) {
            $this->post(route('admin.mfa.challenge.verify'), ['code' => sprintf('%06d', $i)]);
        }

        $this->post(route('admin.mfa.challenge.verify'), ['code' => AdminMfa::codeAt($secret, time() + 30)])
            ->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_a_recovery_code_works_once_and_is_audited(): void
    {
        [, $codes] = $this->enroll();

        $this->newSession();
        $this->post(route('admin.mfa.challenge.verify'), ['code' => strtolower($codes[0])])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(AdminMfa::RECOVERY_CODES - 1, app(AdminMfa::class)->remainingRecoveryCodes($this->admin));
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_recovery_code_used')->count());
        Notification::assertSentTo($this->admin, SecurityAlertNotification::class, fn ($n) => $n->event === 'mfa_recovery_used');

        $this->newSession();
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
    }

    public function test_regenerating_recovery_codes_replaces_the_old_ones(): void
    {
        [, $old] = $this->enroll();

        $new = $this->post(route('admin.mfa.recovery'))->assertOk()->viewData('codes');
        $this->assertCount(AdminMfa::RECOVERY_CODES, $new);

        $this->newSession();
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $old[1]])->assertSessionHasErrors('code');
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $new[1]])->assertRedirect();
    }

    public function test_turning_it_off_needs_a_code_and_is_audited(): void
    {
        [$secret] = $this->enroll();

        $this->delete(route('admin.mfa.disable'), ['code' => '999999'])->assertSessionHasErrors('code');
        $this->assertTrue(app(AdminMfa::class)->enrolled($this->admin));

        $this->delete(route('admin.mfa.disable'), ['code' => AdminMfa::codeAt($secret, time() + 30)])->assertRedirect(route('admin.mfa.show'));
        $this->assertFalse(app(AdminMfa::class)->enrolled($this->admin));
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_disabled')->count());
    }

    public function test_when_required_an_admin_without_it_can_only_reach_the_setup_page(): void
    {
        config(['accounts.admin_mfa_required' => true]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.mfa.show'))->assertOk();
        $this->post(route('admin.mfa.enroll'))->assertRedirect(route('admin.mfa.show'));

        // Other roles are not affected.
        $citizen = new User;
        $citizen->forceFill(['name' => 'C', 'email' => 'mfa.citizen@example.test', 'mobile' => '9466600002', 'password' => Hash::make(self::PASSWORD), 'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => now()])->save();
        $this->actingAs($citizen)->get(route('dashboard'))->assertOk();
    }

    public function test_when_required_it_cannot_be_turned_off(): void
    {
        [$secret] = $this->enroll();
        config(['accounts.admin_mfa_required' => true]);

        $this->delete(route('admin.mfa.disable'), ['code' => AdminMfa::codeAt($secret, time() + 30)])->assertSessionHas('error');
        $this->assertTrue(app(AdminMfa::class)->enrolled($this->admin));
    }

    public function test_the_console_reset_removes_it_and_is_audited(): void
    {
        $this->enroll();

        $this->artisan('enotary:admin-mfa-reset', ['email' => 'MFA.Admin@example.test'])
            ->expectsConfirmation('Remove two-factor sign-in from m********@example.test?', 'yes')
            ->assertSuccessful();

        $this->assertFalse(app(AdminMfa::class)->enrolled($this->admin));
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_reset_cli')->count());
    }

    public function test_the_totp_algorithm_matches_the_rfc_6238_test_vector(): void
    {
        // RFC 6238 appendix B, SHA-1 secret "12345678901234567890", T = 59 s → 94287082 (8 digits),
        // whose last 6 digits are 287082.
        $this->assertSame('287082', AdminMfa::codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        $this->assertSame('081804', AdminMfa::codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1111111109));
    }

    public function test_without_the_table_nothing_changes_for_the_admin(): void
    {
        \Illuminate\Support\Facades\Schema::drop('admin_mfa_factors');
        AdminMfa::flushAvailability();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.mfa.show'))->assertOk()->assertSee('2026_11_10_000031');
    }

    public function test_the_setup_page_shows_a_locally_made_qr_code_and_is_never_cached(): void
    {
        $this->actingAs($this->admin)->post(route('admin.mfa.enroll'));
        $response = $this->get(route('admin.mfa.show'))->assertOk();

        $qr = $response->viewData('qr');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr);
        $this->assertStringContainsString('<svg', base64_decode(substr($qr, strlen('data:image/svg+xml;base64,'))));
        $response->assertSee('src="'.$qr.'"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // The recovery-code page is not cached either.
        $secret = AdminMfaFactor::where('user_id', $this->admin->id)->firstOrFail()->secret;
        $confirm = $this->post(route('admin.mfa.confirm'), ['code' => AdminMfa::codeAt($secret, time())])->assertOk();
        $this->assertStringContainsString('no-store', $confirm->headers->get('Cache-Control'));

        // Once enrolled, the page no longer shows a QR code or the key.
        $this->assertNull($this->get(route('admin.mfa.show'))->viewData('qr'));
    }

    public function test_no_qr_code_or_key_is_shown_to_another_admin_or_without_starting_setup(): void
    {
        $this->actingAs($this->admin)->get(route('admin.mfa.show'))->assertOk()->assertViewHas('qr', null)->assertViewHas('pendingSecret', null);

        $this->post(route('admin.mfa.enroll'));
        $other = new User;
        $other->forceFill(['name' => 'Other Admin', 'email' => 'mfa.other@example.test', 'mobile' => '9466600009', 'password' => Hash::make(self::PASSWORD), 'role' => 'admin', 'email_verified_at' => now()])->save();

        $this->actingAs($other)->get(route('admin.mfa.show'))->assertOk()->assertViewHas('qr', null)->assertViewHas('pendingSecret', null);
    }

    public function test_a_refused_turn_off_does_not_spend_the_code(): void
    {
        [$secret] = $this->enroll();
        config(['accounts.admin_mfa_required' => true]);
        $code = AdminMfa::codeAt($secret, time() + 30);

        $this->delete(route('admin.mfa.disable'), ['code' => $code])->assertSessionHas('error');

        // The same code still works for sign-in.
        $this->newSession();
        $this->post(route('admin.mfa.challenge.verify'), ['code' => $code])->assertRedirect($this->admin->landingUrl());
    }

    public function test_the_admin_navigation_and_security_page_link_to_two_factor_settings(): void
    {
        $url = route('admin.mfa.show');

        $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $dashboard->assertSee('Security &amp; Two-Factor Authentication', false)->assertSee('href="'.$url.'"', false);

        $this->get(route('admin.security.index'))->assertOk()
            ->assertSee('Your two-factor sign-in')->assertSee('Not set up')->assertSee('href="'.$url.'"', false);

        $this->get($url)->assertOk()->assertSee('Security &amp; Two-Factor Authentication', false)->assertSee('name="_token"', false);

        $this->enroll();
        $this->get(route('admin.security.index'))->assertOk()->assertDontSee('Not set up');
    }

    public function test_citizens_and_notaries_get_no_two_factor_link_and_cannot_open_the_pages(): void
    {
        foreach (['public' => 'dashboard', 'notary' => 'notary.dashboard'] as $role => $home) {
            $user = new User;
            $user->forceFill(['name' => ucfirst($role), 'email' => "mfa.{$role}@example.test", 'mobile' => $role === 'public' ? '9466600011' : '9466600012',
                'password' => Hash::make(self::PASSWORD), 'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
                'notary_approved_at' => $role === 'notary' ? now() : null])->save();

            $this->actingAs($user)->get(route($home))->assertOk()->assertDontSee('Two-Factor Authentication');
            $this->get(route('admin.mfa.show'))->assertStatus(403);
            $this->post(route('admin.mfa.enroll'))->assertStatus(403);
            $this->post(route('admin.mfa.confirm'), ['code' => '123456'])->assertStatus(403);
        }

        $this->assertSame(0, AdminMfaFactor::count());
    }

    public function test_the_two_factor_routes_are_csrf_protected_and_rate_limited(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['admin.mfa.enroll', 'admin.mfa.confirm', 'admin.mfa.recovery', 'admin.mfa.disable', 'admin.mfa.challenge.verify'] as $name) {
            $middleware = $routes->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware, $name);
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => str_starts_with($m, 'throttle:')), $name.' is throttled');
        }

        // The real CSRF check (tests normally skip it): a POST without a token is refused.
        $this->actingAs($this->admin);
        $this->app->instance('env', 'local');
        $response = $this->withMiddleware()->call('POST', route('admin.mfa.enroll'));
        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(0, AdminMfaFactor::count());
    }
}
