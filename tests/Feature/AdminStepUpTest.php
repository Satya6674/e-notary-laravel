<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\AdminMfa;
use App\Support\AdminReauth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin step-up ("sudo mode"): sensitive Admin actions need a fresh two-factor code (authenticator
 * app or recovery code) within accounts.admin_reauth_minutes. A password never opens that window
 * (owner decisions 3 and 4). Without it nothing changes. Also: the person is told what an Admin
 * changed, and the Admin's edits are audited in the same transaction. Every account is synthetic.
 */
class AdminStepUpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $adminPasswordConfirmed = false;

    private const PASSWORD = 'Admin-demo-pass-1';

    private User $admin;

    private User $citizen;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = $this->user('admin', 'stepup.admin@example.test', '9477700001');
        $this->citizen = $this->user('public', 'stepup.citizen@example.test', '9477700002');

        // A confirmed factor, set up through the real service. The enrolment code uses the current
        // time step, so step-up codes below use the next one (time() + 30): a code works only once.
        $mfa = app(AdminMfa::class);
        $this->secret = $mfa->start($this->admin);
        $mfa->confirm($this->admin, AdminMfa::codeAt($this->secret, time()));
    }

    private function user(string $role, string $email, string $mobile): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Demo '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ])->save();

        return $user->fresh();
    }

    /** Signed in with both factors, as the browser would be after the sign-in challenge. */
    private function asAdmin(array $session = []): static
    {
        return $this->actingAs($this->admin)->withSession([AdminMfa::SESSION_KEY => $this->admin->id] + $session);
    }

    private function nextCode(): string
    {
        return AdminMfa::codeAt($this->secret, time() + 30);
    }

    private function edit(array $changes = [], array $extra = [])
    {
        return $this->asAdmin()->from(route('admin.users.edit', $this->citizen))->put(route('admin.users.update', $this->citizen), array_merge([
            'name' => $this->citizen->name, 'email' => $this->citizen->email, 'mobile' => $this->citizen->mobile, 'role' => 'public',
        ], $changes, $extra));
    }

    public function test_a_sensitive_action_without_a_fresh_code_changes_nothing_and_asks_for_the_code(): void
    {
        $hash = $this->citizen->password;

        $this->edit(['password' => 'Brand-new-pass-9', 'password_confirmation' => 'Brand-new-pass-9'])
            ->assertRedirect(route('admin.reauth.show'));

        $this->assertSame($hash, $this->citizen->fresh()->password);
        $this->assertSame(0, AuditLog::where('action', 'admin.user_updated')->count());
    }

    public function test_every_sensitive_admin_route_carries_the_step_up(): void
    {
        $routes = ['admin.users.update', 'admin.users.destroy', 'admin.users.reactivate', 'admin.users.delete', 'admin.users.restore',
            'admin.users.email-replacement.store', 'admin.recovery.approve', 'admin.recovery.reject', 'admin.notaries.approve',
            'admin.notaries.reject', 'admin.mfa.recovery', 'admin.mfa.disable',
            'admin.verification.verify', 'admin.verification.access.grant', 'admin.verification.access.revoke'];

        foreach ($routes as $name) {
            $this->assertContains('admin.reauth', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        // Setup checks the password itself (an Admin without a factor cannot pass a code step-up).
        $this->assertNotContains('admin.reauth', app('router')->getRoutes()->getByName('admin.mfa.enroll')->gatherMiddleware());
    }

    public function test_deactivate_and_role_change_are_refused_without_a_fresh_code(): void
    {
        $this->asAdmin()->delete(route('admin.users.destroy', $this->citizen), ['reason' => 'Synthetic test'])
            ->assertRedirect(route('admin.reauth.show'));
        $this->assertNull($this->citizen->fresh()->deactivated_at);

        $this->edit(['role' => 'admin'])->assertRedirect(route('admin.reauth.show'));
        $this->assertSame('public', $this->citizen->fresh()->role);
    }

    public function test_a_correct_inline_password_no_longer_passes_the_step_up_and_opens_nothing(): void
    {
        $this->edit(['name' => 'Password Only'], ['current_password' => self::PASSWORD])->assertRedirect(route('admin.reauth.show'));

        $this->assertNotSame('Password Only', $this->citizen->fresh()->name);
        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY));
    }

    public function test_a_correct_inline_code_lets_the_action_through_opens_the_window_and_is_audited(): void
    {
        $this->edit(['name' => 'Renamed Person'], ['step_up_code' => $this->nextCode()])->assertRedirect(route('admin.users.index'));

        $this->assertSame('Renamed Person', $this->citizen->fresh()->name);
        $this->assertTrue(session()->has(AdminReauth::SESSION_KEY));
        $this->assertSame(['method' => 'authenticator'], AuditLog::where('action', 'admin.stepup_granted')->sole()->meta);
    }

    public function test_a_wrong_inline_code_is_refused_audited_and_changes_nothing(): void
    {
        $this->edit(['name' => 'Should Not Save'], ['step_up_code' => '000000'])
            ->assertSessionHasErrors('step_up_code');

        $this->assertSame($this->citizen->name, $this->citizen->fresh()->name);
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_failed')->count());
        $this->assertFalse(session()->hasOldInput('step_up_code'));
    }

    public function test_the_step_up_page_accepts_a_fresh_code_rejects_a_password_and_returns_to_the_page(): void
    {
        $this->asAdmin()->from(route('admin.users.edit', $this->citizen))
            ->put(route('admin.users.update', $this->citizen), ['name' => 'x', 'email' => $this->citizen->email, 'role' => 'public'])
            ->assertRedirect(route('admin.reauth.show'));

        $this->get(route('admin.reauth.show'))->assertOk()->assertSee('Confirm it is you');

        // The password is not a way in here: the code field is required and a password is not a code.
        $this->post(route('admin.reauth.confirm'), ['current_password' => self::PASSWORD])->assertSessionHasErrors('code');
        $this->post(route('admin.reauth.confirm'), ['code' => self::PASSWORD])->assertSessionHasErrors('code');
        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY));

        $this->post(route('admin.reauth.confirm'), ['code' => $this->nextCode()])
            ->assertRedirect(route('admin.users.edit', $this->citizen));

        // Within the window the action goes through without asking again.
        $this->edit(['name' => 'After Confirm'])->assertRedirect(route('admin.users.index'));
        $this->assertSame('After Confirm', $this->citizen->fresh()->name);
    }

    public function test_the_window_expires_exactly_at_its_boundary(): void
    {
        $window = AdminReauth::windowSeconds();

        $this->asAdmin([AdminReauth::SESSION_KEY => time() - $window + 2]);
        $this->edit(['name' => 'Just In Time'])->assertRedirect(route('admin.users.index'));
        $this->assertSame('Just In Time', $this->citizen->fresh()->name);

        $this->asAdmin([AdminReauth::SESSION_KEY => time() - $window - 1]);
        $this->edit(['name' => 'Too Late'])->assertRedirect(route('admin.reauth.show'));
        $this->assertSame('Just In Time', $this->citizen->fresh()->name);
    }

    public function test_five_wrong_codes_lock_the_step_up_and_the_sign_in_challenge_together(): void
    {
        $this->asAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.reauth.confirm'), ['code' => '00000'.$i]);
        }

        $this->post(route('admin.reauth.confirm'), ['code' => $this->nextCode()])->assertSessionHasErrors('code');
        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY));
        $this->assertSame('locked', app(AdminMfa::class)->verify($this->admin, $this->nextCode()));
        $this->assertSame(5, AuditLog::where('action', 'admin.mfa_failed')->count());
    }

    public function test_a_json_request_gets_423_not_a_redirect(): void
    {
        $this->asAdmin()->putJson(route('admin.users.update', $this->citizen), ['name' => 'x', 'email' => $this->citizen->email, 'role' => 'public'])
            ->assertStatus(423)->assertJsonPath('confirm_url', route('admin.reauth.show'));
    }

    public function test_a_window_opened_by_a_password_before_this_change_is_void_but_nobody_is_signed_out(): void
    {
        // The state the old password step-up left in a session: only the old key.
        $this->asAdmin([AdminReauth::LEGACY_KEY => time()]);

        $this->edit(['name' => 'Legacy Window'])->assertRedirect(route('admin.reauth.show'));
        $this->assertNotSame('Legacy Window', $this->citizen->fresh()->name);
        $this->get(route('admin.dashboard'))->assertOk();

        $this->post(route('admin.reauth.confirm'), ['code' => $this->nextCode()])->assertRedirect();
        $this->assertFalse(session()->has(AdminReauth::LEGACY_KEY));
        $this->assertTrue(session()->has(AdminReauth::SESSION_KEY));
    }

    public function test_nothing_in_the_application_reads_the_old_key(): void
    {
        foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
            $this->assertStringNotContainsString("'admin.reauth_at'", str_replace(
                "public const LEGACY_KEY = 'admin.reauth_at';", '', $file->getContents()), $file->getRelativePathname());
        }
    }

    public function test_a_password_sign_in_never_carries_over_a_step_up_window_or_a_two_factor_pass(): void
    {
        $this->withSession([AdminReauth::SESSION_KEY => time(), AdminMfa::SESSION_KEY => $this->admin->id, AdminReauth::LEGACY_KEY => time()]);

        $this->postJson('/custom-login', ['mobile' => $this->admin->email, 'password' => self::PASSWORD])->assertOk();

        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY));
        $this->assertFalse(session()->has(AdminReauth::LEGACY_KEY));
        $this->assertFalse(session()->has(AdminMfa::SESSION_KEY));
        // The two-factor challenge comes first again.
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_the_code_used_at_sign_in_cannot_be_used_again_for_step_up(): void
    {
        $this->actingAs($this->admin);
        $code = $this->nextCode();

        $this->post(route('admin.mfa.challenge.verify'), ['code' => $code])->assertRedirect();
        $this->assertTrue(session()->has(AdminReauth::SESSION_KEY));

        // The window lapses; the same code is refused for step-up.
        $this->withSession([AdminReauth::SESSION_KEY => time() - AdminReauth::windowSeconds() - 1]);
        $this->edit(['name' => 'Replayed'], ['step_up_code' => $code])->assertSessionHasErrors('step_up_code');
        $this->assertNotSame('Replayed', $this->citizen->fresh()->name);
    }

    public function test_a_successful_step_up_gives_the_session_a_new_id(): void
    {
        $this->asAdmin()->get(route('admin.reauth.show'))->assertOk();
        $before = session()->getId();

        $this->post(route('admin.reauth.confirm'), ['code' => $this->nextCode()])->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_an_admin_without_two_factor_cannot_obtain_the_window_at_all(): void
    {
        config(['accounts.admin_mfa_required' => false]);
        $plain = $this->user('admin', 'stepup.plain@example.test', '9477700005');

        $this->actingAs($plain)->put(route('admin.users.update', $this->citizen), ['name' => 'No Factor', 'email' => $this->citizen->email, 'role' => 'public', 'current_password' => self::PASSWORD])
            ->assertRedirect(route('admin.mfa.show'));
        $this->actingAs($plain)->put(route('admin.users.update', $this->citizen), ['name' => 'No Factor', 'email' => $this->citizen->email, 'role' => 'public', 'step_up_code' => '123456'])
            ->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.reauth.show'))->assertRedirect(route('admin.mfa.show'));
        $this->post(route('admin.reauth.confirm'), ['code' => '123456'])->assertRedirect(route('admin.mfa.show'));

        $this->assertNotSame('No Factor', $this->citizen->fresh()->name);
        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY));
    }

    public function test_setup_asks_for_the_password_which_is_limited_audited_and_opens_no_window(): void
    {
        $plain = $this->user('admin', 'stepup.setup@example.test', '9477700006');
        $this->actingAs($plain);

        $this->post(route('admin.mfa.enroll'))->assertSessionHasErrors('current_password');
        $this->post(route('admin.mfa.enroll'), ['current_password' => 'wrong-1'])->assertSessionHasErrors('current_password');
        $this->assertSame(1, AuditLog::where('action', 'admin.reauth_failed')->where('model_id', $plain->id)->count());

        $this->post(route('admin.mfa.enroll'), ['current_password' => self::PASSWORD])->assertRedirect(route('admin.mfa.show'));
        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY), 'A password never opens the step-up window.');

        $secret = app(AdminMfa::class)->factor($plain)->secret;
        $this->post(route('admin.mfa.confirm'), ['code' => AdminMfa::codeAt($secret, time())])->assertOk();
        $this->assertTrue(session()->has(AdminReauth::SESSION_KEY), 'Confirming the new factor is a fresh two-factor check.');
    }

    public function test_five_wrong_setup_passwords_lock_setup_even_for_the_right_password(): void
    {
        $plain = $this->user('admin', 'stepup.lock@example.test', '9477700007');
        $this->actingAs($plain);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.mfa.enroll'), ['current_password' => 'wrong-'.$i]);
        }

        $this->post(route('admin.mfa.enroll'), ['current_password' => self::PASSWORD])->assertSessionHasErrors('current_password');
        $this->assertFalse(app(AdminMfa::class)->enrolled($plain));
        $this->assertNull(app(AdminMfa::class)->factor($plain));
    }

    public function test_citizens_and_notaries_cannot_reach_the_step_up_or_two_factor_pages(): void
    {
        $notary = $this->user('notary', 'stepup.notary@example.test', '9477700003');

        foreach ([$this->citizen, $notary] as $user) {
            $this->actingAs($user)->get(route('admin.reauth.show'))->assertStatus(403);
            $this->actingAs($user)->get(route('admin.mfa.show'))->assertStatus(403);
            $this->actingAs($user)->put(route('admin.users.update', $this->citizen), ['name' => 'x', 'step_up_code' => '123456'])->assertStatus(403);
        }
    }

    public function test_the_person_is_told_about_an_admin_password_email_and_role_change_at_the_previous_address(): void
    {
        $previous = $this->citizen->email;

        $this->edit(['email' => 'stepup.new@example.test', 'role' => 'notary', 'password' => 'Brand-new-pass-9', 'password_confirmation' => 'Brand-new-pass-9'], ['step_up_code' => $this->nextCode()])
            ->assertRedirect(route('admin.users.index'));

        $events = [];
        Notification::assertSentTo(new AnonymousNotifiable, SecurityAlertNotification::class, function ($n, $channels, $notifiable) use ($previous, &$events) {
            if (($notifiable->routes['mail'] ?? null) === $previous) {
                $events[] = $n->event;
            }

            return true;
        });

        sort($events);
        $this->assertSame(['admin_email_changed', 'admin_password_set', 'admin_role_changed'], $events);
        // Never the new password or address in the alert text.
        foreach (['admin_email_changed', 'admin_password_set', 'admin_role_changed'] as $event) {
            $this->assertStringNotContainsString('Brand-new-pass-9', SecurityAlertNotification::EVENTS[$event]);
            $this->assertStringNotContainsString('stepup.new@', SecurityAlertNotification::EVENTS[$event]);
        }
    }

    public function test_an_admin_password_change_rotates_the_remember_token_and_is_audited(): void
    {
        $this->citizen->forceFill(['remember_token' => 'old-token-value'])->save();

        $this->edit(['password' => 'Brand-new-pass-9', 'password_confirmation' => 'Brand-new-pass-9'], ['step_up_code' => $this->nextCode()]);

        $fresh = $this->citizen->fresh();
        $this->assertTrue(Hash::check('Brand-new-pass-9', $fresh->password));
        $this->assertNotSame('old-token-value', $fresh->remember_token);
        $this->assertSame(1, AuditLog::where('action', 'admin.user_updated')->where('model_id', $this->citizen->id)->count());
    }

    public function test_deactivation_and_reactivation_notify_the_person_and_are_audited(): void
    {
        $this->asAdmin([AdminReauth::SESSION_KEY => time()]);

        $this->delete(route('admin.users.destroy', $this->citizen), ['reason' => 'Synthetic test'])->assertRedirect(route('admin.users.index'));
        $this->assertNotNull($this->citizen->fresh()->deactivated_at);
        Notification::assertSentTo($this->citizen, SecurityAlertNotification::class, fn ($n) => $n->event === 'account_deactivated');

        $this->post(route('admin.users.reactivate', $this->citizen))->assertRedirect();
        $this->assertNull($this->citizen->fresh()->deactivated_at);
        Notification::assertSentTo($this->citizen, SecurityAlertNotification::class, fn ($n) => $n->event === 'account_reactivated');

        $this->assertSame(1, AuditLog::where('action', 'admin.user_deactivated')->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.user_reactivated')->count());
    }

    public function test_a_failed_audit_write_rolls_the_edit_back(): void
    {
        $this->asAdmin([AdminReauth::SESSION_KEY => time()]);

        // Every audit insert fails from here on.
        AuditLog::creating(fn () => throw new \RuntimeException('audit store unavailable'));

        $this->edit(['name' => 'Must Roll Back'])->assertSessionHasErrors('role');
        $this->assertNotSame('Must Roll Back', $this->citizen->fresh()->name);

        $this->delete(route('admin.users.destroy', $this->citizen), ['reason' => 'Synthetic test'])->assertSessionHas('error');
        $this->assertNull($this->citizen->fresh()->deactivated_at);
    }

    public function test_the_permanent_admin_stays_protected_even_with_a_fresh_confirmation(): void
    {
        config(['accounts.protected' => ['owner@example.com']]);
        $owner = $this->user('admin', 'owner@example.com', '9477700009');
        $this->asAdmin([AdminReauth::SESSION_KEY => time()]);

        $this->put(route('admin.users.update', $owner), ['name' => 'Hijack', 'email' => 'x@example.test', 'role' => 'public']);
        $this->delete(route('admin.users.destroy', $owner), ['reason' => 'Synthetic test']);

        $fresh = $owner->fresh();
        $this->assertSame('admin', $fresh->role);
        $this->assertSame('owner@example.com', $fresh->email);
        $this->assertNull($fresh->deactivated_at);
    }
}
