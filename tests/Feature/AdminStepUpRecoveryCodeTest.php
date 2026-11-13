<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminMfa;
use App\Support\AdminReauth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A recovery code may satisfy the two-factor step-up (owner decision Q1, 2026-11-11): single use,
 * stored hashed, consumed under a row lock, reuse refused exactly like an unknown code, rate
 * limited, audited without the code, and never longer than the normal window.
 */
class AdminStepUpRecoveryCodeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $adminPasswordConfirmed = false;

    private User $admin;

    private User $citizen;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = $this->person('admin', 'recovery.stepup@example.test', '9477800001');
        $this->citizen = $this->person('public', 'recovery.citizen@example.test', '9477800002');

        $mfa = app(AdminMfa::class);
        $secret = $mfa->start($this->admin);
        $this->codes = $mfa->confirm($this->admin, AdminMfa::codeAt($secret, time()));
    }

    private function person(string $role, string $email, string $mobile): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Demo '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make('Recovery-demo-1'),
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function rename(string $name, string $code, array $session = [])
    {
        return $this->actingAs($this->admin)->withSession([AdminMfa::SESSION_KEY => $this->admin->id] + $session)
            ->from(route('admin.users.edit', $this->citizen))
            ->put(route('admin.users.update', $this->citizen), ['name' => $name, 'email' => $this->citizen->email, 'mobile' => $this->citizen->mobile, 'role' => 'public', 'step_up_code' => $code]);
    }

    private function remaining(): int
    {
        return app(AdminMfa::class)->remainingRecoveryCodes($this->admin);
    }

    public function test_a_valid_recovery_code_passes_once_and_is_audited_without_the_code(): void
    {
        $before = $this->remaining();

        $this->rename('Recovered Once', $this->codes[0])->assertRedirect(route('admin.users.index'));

        $this->assertSame('Recovered Once', $this->citizen->fresh()->name);
        $this->assertSame($before - 1, $this->remaining());
        $this->assertSame(['method' => 'recovery_code'], AuditLog::where('action', 'admin.stepup_granted')->sole()->meta);
        $this->assertSame(1, AuditLog::where('action', 'admin.mfa_recovery_code_used')->count());

        $plain = strtolower(str_replace('-', '', $this->codes[0]));
        foreach (AuditLog::all() as $row) {
            $text = strtolower(json_encode($row->meta).$row->action);
            $this->assertStringNotContainsString($plain, str_replace('-', '', $text));
        }
    }

    public function test_a_used_recovery_code_is_refused_exactly_like_an_unknown_one(): void
    {
        $this->rename('First Use', $this->codes[1])->assertRedirect(route('admin.users.index'));

        // A new session without the window: the same code again, then a code that never existed.
        $this->flushSession();
        $reused = $this->rename('Second Use', $this->codes[1]);
        $reusedError = session('errors')->first('step_up_code');
        $this->flushSession();
        $unknown = $this->rename('Never Existed', 'ABCDE-FGHJK');
        $unknownError = session('errors')->first('step_up_code');

        $reused->assertSessionHasErrors('step_up_code');
        $unknown->assertSessionHasErrors('step_up_code');
        $this->assertSame($unknownError, $reusedError, 'A used code must not be distinguishable from an unknown one.');
        $this->assertSame('First Use', $this->citizen->fresh()->name);
        $this->assertSame(2, AuditLog::where('action', 'admin.mfa_failed')->count());
    }

    public function test_wrong_recovery_codes_are_rate_limited_even_for_a_valid_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->flushSession();
            $this->rename('Guess '.$i, 'WRONG-CODE'.$i)->assertSessionHasErrors('step_up_code');
        }

        $this->flushSession();
        $before = $this->remaining();
        $this->rename('Valid But Locked', $this->codes[2])->assertSessionHasErrors('step_up_code');

        $this->assertNotSame('Valid But Locked', $this->citizen->fresh()->name);
        $this->assertSame($before, $this->remaining(), 'A refused attempt must not spend a code.');
    }

    public function test_a_recovery_code_window_expires_like_any_other(): void
    {
        $this->rename('Within Window', $this->codes[3])->assertRedirect(route('admin.users.index'));

        $this->flushSession();
        $this->actingAs($this->admin)->withSession([AdminMfa::SESSION_KEY => $this->admin->id, AdminReauth::SESSION_KEY => time() - AdminReauth::windowSeconds() - 1])
            ->put(route('admin.users.update', $this->citizen), ['name' => 'After Window', 'email' => $this->citizen->email, 'role' => 'public'])
            ->assertRedirect(route('admin.reauth.show'));

        $this->assertSame('Within Window', $this->citizen->fresh()->name);
    }

    public function test_the_step_up_page_accepts_a_recovery_code_and_says_it_is_spent(): void
    {
        $this->actingAs($this->admin)->withSession([AdminMfa::SESSION_KEY => $this->admin->id])
            ->post(route('admin.reauth.confirm'), ['code' => strtolower($this->codes[4])])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'recovery code'));

        $this->assertTrue(session()->has(AdminReauth::SESSION_KEY));
        $this->assertFalse(session()->hasOldInput('code'));
    }

    public function test_recovery_codes_are_stored_hashed(): void
    {
        $stored = json_encode(app(AdminMfa::class)->factor($this->admin)->recovery_codes);

        foreach ($this->codes as $code) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), $stored);
        }
    }
}
