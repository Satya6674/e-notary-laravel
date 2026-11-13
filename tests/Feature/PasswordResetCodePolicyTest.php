<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may reset a password by email code (approved 2026-11-08; replaces the SMS policy BR-003).
 *
 * The person types a registered email address or mobile number; the code always goes to the account's
 * registered, verified email address, so it proves the mailbox, never the phone. Refused: an unverified
 * address, a deactivated or deleted account, and an administrator who types a mobile number. Every
 * check is made again at the last step. No SMS exists in this flow.
 *
 * Messages go to the in-memory array mailer; nothing leaves the process.
 */
class PasswordResetCodePolicyTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9600000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyCsrfToken::class, ThrottleRequests::class]);
    }

    private function account(string $role, array $extra = []): User
    {
        static $i = 100;
        $i++;

        // forceFill: deactivated_at and pending_mobile are not mass assignable.
        $user = new User(['name' => "Person {$i}", 'email' => "r{$i}@example.test", 'password' => Hash::make('original-password')]);
        $user->forceFill(array_merge([
            'mobile' => self::MOBILE, 'role' => $role, 'email_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ], $extra))->save();

        return $user->fresh();
    }

    private function sent(): array
    {
        return array_values(iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
    }

    private function code(): string
    {
        $sent = $this->sent();
        $last = end($sent);
        $this->assertNotFalse($last, 'no email was sent');
        $this->assertSame(1, preg_match('~verification code is (\d{6})~', (string) $last->getOriginalMessage()->getTextBody(), $m));

        return $m[1];
    }

    private function ask(string $identifier)
    {
        return $this->post('/forgot-password', ['identifier' => $identifier]);
    }

    private function reset(string $password = 'Taken-over-1')
    {
        return $this->post('/reset-password-form', ['password' => $password, 'password_confirmation' => $password]);
    }

    public static function refusedAccounts(): array
    {
        return [
            'citizen, unverified email'        => ['public', ['email_verified_at' => null], 'email'],
            'citizen, deactivated'             => ['public', ['deactivated_at' => '2026-01-01 00:00:00'], 'email'],
            'citizen, deleted'                 => ['public', ['deleted_at' => '2026-01-01 00:00:00', 'deactivated_at' => '2026-01-01 00:00:00'], 'email'],
            'notary, unverified email'         => ['notary', ['email_verified_at' => null], 'mobile'],
            'notary, deactivated'              => ['notary', ['deactivated_at' => '2026-01-01 00:00:00'], 'mobile'],
            'admin, by mobile number'          => ['admin', [], 'mobile'],
            'admin, unverified email'          => ['admin', ['email_verified_at' => null], 'email'],
        ];
    }

    public static function allowedAccounts(): array
    {
        return [
            'citizen by email'           => ['public', [], 'email'],
            'citizen by mobile'          => ['public', [], 'mobile'],
            'notary by mobile'           => ['notary', [], 'mobile'],
            'notary awaiting approval'   => ['notary', ['notary_approved_at' => null], 'email'],
            'admin by email'             => ['admin', [], 'email'],
            'recovered, awaiting a new password' => ['public', ['password_reset_required_at' => '2026-01-01 00:00:00'], 'email'],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function test_these_accounts_get_no_code_and_keep_their_password(string $role, array $extra, string $via): void
    {
        $user = $this->account($role, $extra);

        $this->ask($via === 'email' ? $user->email : self::MOBILE)->assertRedirect()->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);

        $this->assertSame([], $this->sent());
        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset_refused')->count());
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    #[DataProvider('allowedAccounts')]
    public function test_these_accounts_can_reset_with_the_emailed_code(string $role, array $extra, string $via): void
    {
        $user = $this->account($role, $extra);

        $this->ask($via === 'email' ? $user->email : self::MOBILE);
        $this->assertCount(1, $this->sent());
        $this->assertSame($user->email, $this->sent()[0]->getEnvelope()->getRecipients()[0]->getAddress(), 'always the registered email');

        $this->post('/verify-otp', ['otp' => $this->code()])->assertRedirect(route('password.reset.form'));
        $this->reset('New-password-1')->assertRedirect(route('home'));

        $user->refresh();
        $this->assertTrue(Hash::check('New-password-1', $user->password));
        $this->assertFalse($user->mustResetPassword(), 'the code is the mailbox proof a recovered account needs');
    }

    public function test_the_page_answers_the_same_whatever_was_typed(): void
    {
        $this->account('public', ['email_verified_at' => null, 'mobile' => '9600000002']);
        $this->account('admin', ['mobile' => '9600000003']);
        $real = $this->account('public');

        $answers = [];

        foreach ([self::MOBILE, '9600000002', '9600000003', '9600000099', $real->email, 'nobody@example.test'] as $identifier) {
            $this->flushSession();
            $answers[] = $this->ask($identifier)->getSession()->get('status');
        }

        $this->assertSame([ForgotPasswordController::GENERIC_STATUS], array_values(array_unique($answers)));
    }

    public function test_a_reset_never_marks_a_number_verified(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Auth/ForgotPasswordController.php'));

        $this->assertStringNotContainsString('mobile_verified_at', $source);
    }

    public function test_losing_email_verification_after_the_code_stops_the_reset(): void
    {
        $user = $this->account('public');
        $this->ask($user->email);
        $this->post('/verify-otp', ['otp' => $this->code()]);

        $user->forceFill(['email_verified_at' => null])->save();

        $this->reset()->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_deactivation_after_the_code_stops_the_reset(): void
    {
        $user = $this->account('notary');
        $this->ask(self::MOBILE);
        $this->post('/verify-otp', ['otp' => $this->code()]);

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->reset()->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_a_number_waiting_to_be_proven_finds_no_account(): void
    {
        $this->account('public', ['pending_mobile' => '9600000020', 'pending_mobile_requested_at' => now()]);

        $this->ask('9600000020')->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);

        $this->assertSame([], $this->sent());
    }

    public function test_the_old_number_finds_nothing_once_it_has_been_replaced(): void
    {
        $this->account('public', ['mobile' => '9600000030']);

        $this->ask(self::MOBILE)->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);

        $this->assertSame([], $this->sent());
    }

    public function test_a_reset_cancels_a_number_change_started_with_the_old_password(): void
    {
        $user = $this->account('public', ['pending_mobile' => '9600000040', 'pending_mobile_requested_at' => now()]);

        $this->ask($user->email);
        $this->post('/verify-otp', ['otp' => $this->code()]);
        $this->reset('New-password-1');

        $this->assertNull($user->fresh()->pending_mobile);
    }

    public function test_an_old_proof_in_a_session_cannot_ride_on_the_owners_fresh_one(): void
    {
        $user = $this->account('public');
        $this->ask($user->email);
        $this->post('/verify-otp', ['otp' => $this->code()]);

        $this->travel(OtpService::VERIFIED_WINDOW_MINUTES + 20)->minutes();

        // The owner has just passed a code in a session of their own.
        DB::table('otp_verifications')->insert([
            'mobile' => OtpService::emailKey($user), 'otp' => OtpService::FLOW_PASSWORD_RESET_EMAIL.'|'.Hash::make('x'), 'expires_at' => now()->addMinutes(5),
            'verified_at' => now(), 'attempts' => 0, 'created_at' => now(),
        ]);

        $this->reset()->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_passing_the_code_step_starts_a_new_session(): void
    {
        // Called directly: in a test each HTTP request gets a new id anyway, which would prove nothing.
        $user = $this->account('public');
        $this->partialMock(OtpService::class, fn ($mock) => $mock->shouldReceive('verifyPasswordResetCode')->andReturn(OtpService::OK));

        session(['reset.identifier' => 'email:'.$user->email, 'reset.requested_at' => now()->timestamp]);
        $before = session()->getId();

        app(ForgotPasswordController::class)->verifyOtp(Request::create('/verify-otp', 'POST', ['otp' => '123456']), app(OtpService::class));

        $this->assertSame($user->id, session('reset.user_id'));
        $this->assertNotSame($before, session()->getId());
    }

    // ---- Kept from the SMS policy: about the mobile verification step, not password reset.

    public function test_a_number_changed_while_its_code_is_checked_does_not_become_verified(): void
    {
        $user = $this->account('public', ['mobile_verified_at' => null]);

        $this->partialMock(OtpService::class, function ($mock) use ($user) {
            $mock->shouldReceive('verify')->andReturnUsing(function () use ($user) {
                User::whereKey($user->id)->update(['mobile' => '9600000050']);

                return OtpService::OK;
            });
        });

        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => '123456'])->assertSessionHas('error');

        $fresh = $user->fresh();
        $this->assertSame('9600000050', $fresh->mobile);
        $this->assertNull($fresh->mobile_verified_at);
        $this->assertFalse($fresh->canRecoverByMobile());
    }
}
