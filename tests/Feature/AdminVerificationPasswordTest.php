<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AdminReauth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SEC-1: manual verification and temporary access need a fresh two-factor step-up AND the Admin's
 * own password. The password is limited to 5 wrong tries per 15 minutes per Admin, each audited,
 * and it never opens or extends the step-up window.
 */
class AdminVerificationPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'admin-own-password-1';

    private User $admin;

    private User $citizen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'sec1.admin@example.test', 'password' => Hash::make(self::PASSWORD)]);
        $this->citizen = User::factory()->create(['role' => 'public', 'email' => 'sec1.citizen@example.test', 'mobile' => '9876500301',
            'email_verified_at' => null, 'mobile_verified_at' => null]);
    }

    private function verify(string $password, array $session = [])
    {
        return $this->actingAs($this->admin)->withSession($session)->post(route('admin.verification.verify', $this->citizen), [
            'purpose' => 'email', 'reason' => 'Called the registered number and confirmed identity on video.',
            'evidence_method' => 'video_call_id', 'confirm' => '1', 'admin_password' => $password,
        ]);
    }

    public function test_verification_and_access_routes_need_the_step_up_first(): void
    {
        $this->actingAs($this->admin)->withSession([AdminReauth::SESSION_KEY => 0]);

        foreach (['admin.verification.verify', 'admin.verification.access.grant', 'admin.verification.access.revoke'] as $name) {
            $this->assertContains('admin.reauth', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_five_wrong_passwords_lock_and_then_even_the_right_one_is_refused(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->verify('wrong-password-'.$i)->assertSessionHasErrors('admin_password');
        }

        $this->verify(self::PASSWORD)->assertSessionHasErrors(['admin_password' => 'Too many incorrect passwords. Please wait 15 minutes. Nothing was changed.']);

        $this->assertNull($this->citizen->fresh()->email_verified_at);
        $this->assertSame(5, AuditLog::where('action', 'admin.reauth_failed')->where('model_id', $this->admin->id)->count());
        $this->assertFalse(session()->hasOldInput('admin_password'));
    }

    public function test_the_lock_ends_after_15_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->verify('wrong-password-'.$i);
        }

        $this->travel(16)->minutes();
        $this->verify(self::PASSWORD, [AdminReauth::SESSION_KEY => time()])->assertSessionHasNoErrors();
        $this->assertNotNull($this->citizen->fresh()->email_verified_at);
    }

    public function test_a_correct_password_neither_opens_nor_extends_the_window(): void
    {
        $openedAt = time() - 60;

        $this->verify(self::PASSWORD, [AdminReauth::SESSION_KEY => $openedAt])->assertSessionHasNoErrors();

        $this->assertNotNull($this->citizen->fresh()->email_verified_at);
        $this->assertSame($openedAt, session(AdminReauth::SESSION_KEY));
    }

    public function test_the_password_budget_is_shared_with_two_factor_setup(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->verify('wrong-password-'.$i);
        }

        $this->actingAs($this->admin)->withSession([AdminReauth::SESSION_KEY => 0])
            ->post(route('admin.mfa.enroll'), ['current_password' => self::PASSWORD])
            ->assertSessionHasErrors(['current_password' => 'Too many incorrect passwords. Please wait 15 minutes.']);
    }
}
