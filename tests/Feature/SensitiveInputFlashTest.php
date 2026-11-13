<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SensitiveInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * After a validation error, the submitted form is kept in the session to refill it. Codes, reset
 * tokens and passwords are never kept there; every other field still is, and the errors still
 * show. Every account here is synthetic.
 */
class SensitiveInputFlashTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETS = [
        'otp' => '482913',
        'token' => 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f',
        'password' => 'Secret-pass-77',
        'password_confirmation' => 'Different-pass-88',
        'current_password' => 'current-pass-99',
    ];

    private function user(string $role, string $email, string $mobile, bool $mobileVerified = true): User
    {
        $user = User::create([
            'name' => 'Flash '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make('demo-pass-123'),
            'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => $mobileVerified ? now() : null])->save();

        return $user->fresh();
    }

    /** The old input kept in the session, and the raw session data, hold no secret value. */
    private function assertNoSecretFlashed(): void
    {
        foreach (SensitiveInput::FIELDS as $field) {
            $this->assertFalse(session()->hasOldInput($field), "{$field} was kept in the session");
        }

        $stored = serialize(session()->all());
        foreach (self::SECRETS as $field => $value) {
            $this->assertStringNotContainsString($value, $stored, "the value of {$field} is in the session");
        }
    }

    public function test_the_list_is_exactly_the_approved_fields(): void
    {
        $this->assertSame(['otp', 'token', 'password', 'password_confirmation', 'current_password', 'admin_password', 'code', 'step_up_code'], SensitiveInput::FIELDS);
    }

    public function test_a_failed_reset_form_keeps_the_email_but_no_token_password_or_code(): void
    {
        $this->post(route('password.update'), self::SECRETS + ['email' => 'someone@example.test'])
            ->assertRedirect()->assertSessionHasErrors('password');

        $this->assertNoSecretFlashed();
        $this->assertSame('someone@example.test', session()->getOldInput('email'), 'Ordinary fields still refill the form.');
    }

    public function test_a_failed_otp_form_does_not_keep_the_code(): void
    {
        $citizen = $this->user('public', 'otp.person@example.test', '9411100001', mobileVerified: false);

        $this->actingAs($citizen)->post(route('mobile.verification.verify'), ['otp' => 'abc'.self::SECRETS['otp']])
            ->assertRedirect()->assertSessionHasErrors('otp');

        $this->assertFalse(session()->hasOldInput('otp'));
        $this->assertStringNotContainsString(self::SECRETS['otp'], serialize(session()->all()));
    }

    public function test_an_admin_edit_refused_by_the_controller_keeps_the_form_but_not_the_password(): void
    {
        $admin = $this->user('admin', 'flash.admin@example.test', '9411100002');
        $this->user('admin', 'flash.admin2@example.test', '9411100003');

        // Refused by the controller itself (own role), not by validation: a manual withInput().
        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => 'Renamed Admin', 'email' => $admin->email, 'mobile' => $admin->mobile, 'role' => 'public',
            'password' => self::SECRETS['password'], 'password_confirmation' => self::SECRETS['password'],
        ])->assertRedirect()->assertSessionHasErrors('role');

        $this->assertNoSecretFlashed();
        $this->assertSame('Renamed Admin', session()->getOldInput('name'));
        $this->assertSame('public', session()->getOldInput('role'));
        $this->assertTrue(Hash::check('demo-pass-123', $admin->fresh()->password), 'Nothing was saved.');
    }

    public function test_an_admin_edit_failing_validation_keeps_other_fields_and_shows_errors(): void
    {
        $admin = $this->user('admin', 'flash.admin3@example.test', '9411100004');
        $citizen = $this->user('public', 'flash.citizen@example.test', '9411100005');

        $this->actingAs($admin)->put(route('admin.users.update', $citizen), [
            'name' => 'Kept Name', 'email' => 'not-an-email', 'mobile' => $citizen->mobile, 'role' => 'public',
            'password' => self::SECRETS['password'], 'password_confirmation' => self::SECRETS['password_confirmation'],
        ])->assertRedirect()->assertSessionHasErrors(['email', 'password']);

        $this->assertNoSecretFlashed();
        $this->assertSame('Kept Name', session()->getOldInput('name'));
        $this->assertSame('not-an-email', session()->getOldInput('email'));
    }
}
