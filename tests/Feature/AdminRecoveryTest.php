<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * enotary:admin-reset: console recovery of the admin account.
 */
class AdminRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Str0ng!Recovery#Pass';

    private function admin(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'mobile' => '9000000001',
            'password' => Hash::make('old-password'),
            'role' => 'admin',
        ], $overrides));
    }

    private function runReset(string $first, string $second)
    {
        return $this->artisan('enotary:admin-reset')
            ->expectsQuestion('New admin password (hidden, minimum 12 characters)', $first)
            ->expectsQuestion('Repeat the new password', $second);
    }

    public function test_sets_a_bcrypt_password_rotates_the_remember_token_and_allows_login(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        // Same shape as the live defect: a value that is not a hash at all.
        $admin = $this->admin();
        $admin->forceFill(['password' => 'plaintext', 'remember_token' => 'old-token'])->save();

        $this->runReset(self::STRONG, self::STRONG)
            ->doesntExpectOutputToContain(self::STRONG)
            ->assertSuccessful();

        $admin->refresh();
        $this->assertStringStartsWith('$2y$', $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::STRONG, $admin->password));
        $this->assertNotSame('old-token', $admin->remember_token);

        // Email is still unverified: the admin must land on the dashboard regardless.
        $this->postJson('/custom-login', ['mobile' => $admin->email, 'password' => self::STRONG])
            ->assertOk()
            ->assertJson(['success' => true, 'redirect' => route('admin.dashboard')]);
    }

    public function test_mismatched_or_weak_passwords_change_nothing(): void
    {
        $admin = $this->admin();
        $hash = $admin->password;

        $this->runReset(self::STRONG, self::STRONG.'x')->assertFailed();
        $this->runReset('short', 'short')->assertFailed();
        $this->runReset('alllowercaseletters', 'alllowercaseletters')->assertFailed();

        $this->assertSame($hash, $admin->fresh()->password);
    }

    // ---------------------------------------------------------------- 12 characters minimum, 72 bytes maximum

    private const TOO_LONG = 'A password can be at most 72 bytes long (72 English letters, fewer if it uses other scripts).';

    /** @return array<string, array{string, bool}> password, accepted */
    public static function cliLengthSamples(): array
    {
        return [
            '12 characters (minimum)'     => ['Abcdef1#ghij', true],
            '11 characters'               => ['Abcdef1#ghi', false],
            '72 ASCII bytes'              => [str_repeat('Aa1#', 18), true],
            '73 ASCII bytes'              => [str_repeat('Aa1#', 18).'x', false],
            'multibyte, 64 bytes'         => ['Aa1#'.str_repeat('अ', 20), true],   // 24 characters
            'multibyte, 73 bytes'         => ['Aa1#'.str_repeat('अ', 23), false],  // only 27 characters
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cliLengthSamples')]
    public function test_the_command_needs_12_characters_and_at_most_72_bytes(string $password, bool $accepted): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $admin = $this->admin();
        $hash = $admin->password;

        $run = $this->runReset($password, $password)->doesntExpectOutputToContain($password);

        if (! $accepted) {
            if (strlen($password) > 72) {
                $run->expectsOutputToContain(self::TOO_LONG);
            }
            $run->assertFailed()->run();

            $this->assertSame($hash, $admin->fresh()->password, 'a refused password changes nothing');
            $this->assertTrue(Hash::check('old-password', $admin->fresh()->password));

            return;
        }

        $run->assertSuccessful()->run();

        $this->assertTrue(Hash::check($password, $admin->fresh()->password));
        $this->postJson('/custom-login', ['mobile' => $admin->email, 'password' => $password])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_the_limit_counts_bytes_not_characters(): void
    {
        $samples = self::cliLengthSamples();

        $this->assertSame(72, strlen($samples['72 ASCII bytes'][0]));
        $this->assertSame([64, 24], [strlen($p = $samples['multibyte, 64 bytes'][0]), mb_strlen($p)]);
        $this->assertSame([73, 27], [strlen($p = $samples['multibyte, 73 bytes'][0]), mb_strlen($p)], 'over in bytes, far under 72 characters');
    }

    public function test_an_existing_admin_password_longer_than_72_bytes_keeps_working_and_is_not_rewritten(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        // Set before the limit (stored directly); signing in has no length limit.
        $long = str_repeat('Long#Pass1-', 10);   // 110 bytes
        $admin = $this->admin(['password' => Hash::make($long)]);
        $hash = $admin->password;

        // A refused console attempt leaves the existing password exactly as it was.
        $this->runReset(str_repeat('Aa1#', 18).'x', str_repeat('Aa1#', 18).'x')->assertFailed()->run();

        $this->assertSame($hash, $admin->fresh()->password, 'not rehashed, not reset');
        $this->postJson('/custom-login', ['mobile' => $admin->email, 'password' => $long])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame($hash, $admin->fresh()->password);
    }

    public function test_refuses_to_guess_when_there_is_not_exactly_one_admin(): void
    {
        $this->artisan('enotary:admin-reset')->assertFailed();

        $this->admin();
        $this->admin(['email' => 'second@example.test', 'mobile' => '9000000002']);

        $this->artisan('enotary:admin-reset')->assertFailed();
    }

    public function test_refuses_non_interactive_use(): void
    {
        $this->admin();

        $this->artisan('enotary:admin-reset', ['--no-interaction' => true])->assertFailed();
    }

    public function test_only_the_admin_skips_the_verification_steps(): void
    {
        $this->assertNull($this->admin()->nextVerificationRoute());

        $citizen = User::create([
            'name' => 'Citizen', 'email' => 'citizen@example.test', 'mobile' => '9000000003',
            'password' => Hash::make('password'), 'role' => 'public',
        ]);

        $this->assertSame('verification.notice', $citizen->nextVerificationRoute());
    }
}
