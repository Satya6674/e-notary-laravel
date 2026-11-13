<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Forgot Password by email code stands alone: each person's own code, nothing shared with sign-up codes. */
class PasswordResetIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'A-brand-new-password1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
    }

    private function person(string $email, string $role = 'public'): User
    {
        return User::factory()->create(['email' => $email, 'role' => $role, 'password' => Hash::make('the-old-password'), 'email_verified_at' => now()]);
    }

    /** Asks for a code in a fresh session and returns the code emailed to this person. */
    private function code(User $user): string
    {
        $this->flushSession();
        $this->post(route('password.email'), ['identifier' => $user->email]);

        return Notification::sent($user, PasswordResetCodeNotification::class)->last()->code;
    }

    private function reset(string $code)
    {
        $this->post(route('password.verify.otp'), ['otp' => $code]);

        return $this->post(route('password.reset.submit'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
    }

    private function assertPasswordChanged(User $user, bool $changed): void
    {
        $this->assertSame($changed, Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_each_person_is_sent_their_own_code_and_can_reset(): void
    {
        $a = $this->person('reset.a@example.test');
        $b = $this->person('reset.b@example.test');
        $c = $this->person('reset.c@example.test', 'notary');

        foreach ([$a, $b, $c] as $user) {
            $this->reset($this->code($user))->assertRedirect(route('home'));
            $this->assertPasswordChanged($user, true);
            Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 1);
        }
    }

    public function test_a_used_code_is_rejected(): void
    {
        $user = $this->person('reset.a@example.test');
        $code = $this->code($user);
        $this->reset($code);
        $user->fresh()->forceFill(['password' => Hash::make('the-old-password')])->save();

        // Same identifier again in a new attempt, but the used code is gone.
        $this->flushSession();
        $this->travel(61)->seconds();
        $this->post(route('password.email'), ['identifier' => $user->email]);
        $this->post(route('password.verify.otp'), ['otp' => $code === Notification::sent($user, PasswordResetCodeNotification::class)->last()->code ? '000000' : $code])
            ->assertSessionHasErrors('otp');

        $this->assertPasswordChanged($user, false);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->person('reset.a@example.test');
        $code = $this->code($user);

        $this->travel(OtpService::ttl() + 1)->minutes();

        $this->reset($code)->assertRedirect(route('password.request'));
        $this->assertPasswordChanged($user, false);
    }

    public function test_one_persons_code_does_not_work_for_another(): void
    {
        $a = $this->person('reset.a@example.test');
        $b = $this->person('reset.b@example.test');

        $codeA = $this->code($a);
        $this->code($b);

        // In b's attempt, a's code is refused (unless the two random codes happen to match).
        if ($codeA !== Notification::sent($b, PasswordResetCodeNotification::class)->last()->code) {
            $this->reset($codeA)->assertRedirect(route('password.request'));
        }

        $this->assertPasswordChanged($b, false);
        $this->assertPasswordChanged($a, false);
    }

    public function test_a_registration_code_is_not_a_reset_code_and_the_reverse(): void
    {
        $user = User::factory()->create(['email' => 'reset.a@example.test', 'role' => 'public', 'email_verified_at' => null, 'password' => Hash::make('the-old-password')]);

        $this->actingAs($user)->post(route('verification.send'));
        $registrationCode = Notification::sent($user, VerifyEmailNotification::class)->last()->code;
        $this->post(route('logout'));

        // An unverified address gets no reset code at all, and the sign-up code opens nothing.
        $this->flushSession();
        $this->post(route('password.email'), ['identifier' => $user->email]);
        Notification::assertNotSentTo($user, PasswordResetCodeNotification::class);
        $this->reset($registrationCode)->assertRedirect(route('password.request'));
        $this->assertPasswordChanged($user, false);

        // Verified, the address gets its own reset code; that code does not verify a registration.
        $user->forceFill(['email_verified_at' => now()])->save();
        $resetCode = $this->code($user);
        $this->assertNotSame(OtpService::OK, app(OtpService::class)->verifyEmailCode($user->fresh(), $resetCode), 'not a registration code');
        $this->reset($resetCode)->assertRedirect(route('home'));
        $this->assertPasswordChanged($user, true);
    }
}
