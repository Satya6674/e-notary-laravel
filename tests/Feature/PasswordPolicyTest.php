<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password as PasswordRule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The strong password policy (App\Support\PasswordPolicy) at every place a person chooses a password:
 * registration (Citizen and Notary), the emailed-code reset, the reset link, the Citizen profile, the
 * Notary password change and an Admin setting a password. At least 8 characters with an uppercase
 * letter, a lowercase letter, a number and a special character; numbers-only gets a plain message.
 *
 * Existing passwords are not touched: an account with an old weak password still signs in until its
 * owner changes it. Synthetic accounts only; mail goes to the in-memory array mailer.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** Illustrations of what is refused; never defaults or suggestions. */
    public const INVALID = ['12345678', '123456789', 'abcdefgh', 'ABCDEFGH', 'Password123', 'password@123', 'PASSWORD@123'];

    /** Illustrations of what is accepted; never defaults or suggestions. */
    public const VALID = ['Secure#Vault2026', 'Trust@Portal82', 'Digital#Access27', 'Secure!Account91'];

    private const ORIGINAL = 'Original#Pass2025';

    private const NUMBERS_ONLY = 'A password cannot be numbers only. Use uppercase and lowercase letters, a number and a special character.';

    private int $n = 0;

    // ---------------------------------------------------------------- helpers

    private function person(string $role = 'public', string $password = self::ORIGINAL): User
    {
        $n = ++$this->n;
        $user = new User;
        $user->forceFill([
            'name' => ucfirst($role)." Person {$n}", 'email' => "policy{$n}@example.test", 'mobile' => '94888'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make($password), 'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ])->save();

        return $user->fresh();
    }

    private function signOut(): void
    {
        $this->post(route('logout'));
        $this->flushSession();
        auth()->forgetGuards();
    }

    /** Signs in through the real login endpoint and reports whether it worked. */
    private function canSignIn(string $email, string $password): bool
    {
        Cache::flush();
        $this->signOut();

        return $this->postJson(route('login.submit'), ['mobile' => $email, 'password' => $password])->json('success') === true;
    }

    /**
     * Submits $password (and $confirmation) to one endpoint. Returns [response, the account whose
     * password it would change or null for a registration, the email to sign in with].
     */
    private function submit(string $endpoint, string $password, ?string $confirmation = null): array
    {
        $confirmation ??= $password;
        Cache::flush();

        switch ($endpoint) {
            case 'register-citizen':
            case 'register-notary':
                $n = ++$this->n;
                $email = "signup{$n}@example.test";
                $response = $this->postJson(route('register.submit'), [
                    'name' => 'New Person', 'email' => $email, 'mobile' => '93777'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                    'password' => $password, 'password_confirmation' => $confirmation,
                    'role' => $endpoint === 'register-notary' ? 'notary' : 'public', 'accept_terms' => '1',
                ]);

                return [$response, null, $email];

            case 'reset-code':
                $user = $this->person();
                $this->post(route('password.email'), ['identifier' => $user->email]);
                $sent = array_values(iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
                $this->assertSame(1, preg_match('~verification code is (\d{6})~', (string) end($sent)->getOriginalMessage()->getTextBody(), $m), 'the reset code was emailed');
                $this->post(route('password.verify.otp'), ['otp' => $m[1]])->assertRedirect(route('password.reset.form'));

                return [$this->post(route('password.reset.submit'), ['password' => $password, 'password_confirmation' => $confirmation]), $user, $user->email];

            case 'reset-link':
                $user = $this->person();

                return [$this->post(route('password.update'), ['token' => Password::broker()->createToken($user), 'email' => $user->email,
                    'password' => $password, 'password_confirmation' => $confirmation]), $user, $user->email];

            case 'citizen-profile':
                $user = $this->person();

                return [$this->actingAs($user)->put(route('user.profile.update'), ['name' => $user->name, 'email' => $user->email,
                    'current_password' => self::ORIGINAL, 'password' => $password, 'password_confirmation' => $confirmation]), $user, $user->email];

            case 'notary-change':
                $user = $this->person('notary');

                return [$this->actingAs($user)->post(route('notary.profile.password'), ['current_password' => self::ORIGINAL,
                    'password' => $password, 'password_confirmation' => $confirmation]), $user, $user->email];

            case 'admin-sets-password':
                $admin = $this->person('admin');
                $user = $this->person();

                return [$this->actingAs($admin)->put(route('admin.users.update', $user), ['name' => $user->name, 'email' => $user->email,
                    'mobile' => $user->mobile, 'role' => $user->role, 'password' => $password, 'password_confirmation' => $confirmation]), $user, $user->email];

            case 'admin-own-password':
                $admin = $this->person('admin');

                return [$this->actingAs($admin)->put(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => $admin->email,
                    'mobile' => $admin->mobile, 'role' => 'admin', 'password' => $password, 'password_confirmation' => $confirmation]), $admin, $admin->email];
        }

        $this->fail("unknown endpoint {$endpoint}");
    }

    public static function endpoints(): array
    {
        return array_combine($e = ['register-citizen', 'register-notary', 'reset-code', 'reset-link', 'citizen-profile', 'notary-change', 'admin-sets-password', 'admin-own-password'], array_map(fn ($x) => [$x], $e));
    }

    public static function invalidAtEveryEndpoint(): array
    {
        $cases = [];
        foreach (array_keys(self::endpoints()) as $endpoint) {
            foreach (self::INVALID as $password) {
                $cases["{$endpoint}: {$password}"] = [$endpoint, $password];
            }
        }

        return $cases;
    }

    public static function validAtEveryEndpoint(): array
    {
        $cases = [];
        foreach (array_keys(self::endpoints()) as $endpoint) {
            foreach (self::VALID as $password) {
                $cases["{$endpoint}: {$password}"] = [$endpoint, $password];
            }
        }

        return $cases;
    }

    private function assertRefused(string $endpoint, TestResponse $response, ?User $user, string $email): void
    {
        if (str_starts_with($endpoint, 'register')) {
            $response->assertStatus(422)->assertJsonPath('success', false);
            $this->assertNull(User::where('email', $email)->first(), 'no account was created');

            return;
        }

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check(self::ORIGINAL, $user->fresh()->password), 'the password was not changed');
    }

    // ---------------------------------------------------------------- the policy itself

    public function test_the_policy_refuses_every_weak_example_and_accepts_every_strong_one(): void
    {
        foreach (self::INVALID as $password) {
            $this->assertTrue(Validator::make(['password' => $password, 'password_confirmation' => $password], ['password' => PasswordPolicy::rules()])->fails(), $password);
        }

        foreach (self::VALID as $password) {
            $this->assertFalse(Validator::make(['password' => $password, 'password_confirmation' => $password], ['password' => PasswordPolicy::rules()])->fails(), $password);
        }
    }

    public function test_each_missing_requirement_is_named(): void
    {
        $first = fn (string $password) => Validator::make(['password' => $password, 'password_confirmation' => $password], ['password' => PasswordPolicy::rules()])->errors()->get('password');

        $this->assertSame(self::NUMBERS_ONLY, $first('12345678')[0], 'numbers only: one plain explanation first');
        $this->assertContains('The password field must contain at least one uppercase and one lowercase letter.', $first('abcdefgh'));
        $this->assertContains('The password field must contain at least one symbol.', $first('Password123'));
        $this->assertContains('The password field must contain at least one number.', $first('Password@abc'));
        $this->assertContains('The password field must be at least 8 characters.', $first('Ab1@'));
    }

    public function test_laravel_password_defaults_are_the_same_policy(): void
    {
        foreach (self::INVALID as $password) {
            $this->assertTrue(Validator::make(['p' => $password], ['p' => PasswordRule::defaults()])->fails(), $password);
        }
        foreach (self::VALID as $password) {
            $this->assertFalse(Validator::make(['p' => $password], ['p' => PasswordRule::defaults()])->fails(), $password);
        }
    }

    // ---------------------------------------------------------------- every endpoint

    #[DataProvider('invalidAtEveryEndpoint')]
    public function test_weak_passwords_are_refused_and_change_nothing(string $endpoint, string $password): void
    {
        [$response, $user, $email] = $this->submit($endpoint, $password);

        $this->assertRefused($endpoint, $response, $user, $email);
        $this->assertStringNotContainsString($password, (string) $response->getContent(), 'the password is not echoed back');
    }

    #[DataProvider('validAtEveryEndpoint')]
    public function test_strong_passwords_are_accepted_and_stored_hashed(string $endpoint, string $password): void
    {
        [$response, $user, $email] = $this->submit($endpoint, $password);

        str_starts_with($endpoint, 'register')
            ? $response->assertOk()->assertJsonPath('success', true)
            : $response->assertSessionHasNoErrors();

        $stored = User::where('email', $email)->value('password');
        $this->assertNotSame($password, $stored, 'never stored as typed');
        $this->assertTrue(Hash::check($password, $stored), 'stored as a hash of the new password');
        $this->assertTrue(Hash::info($stored)['algoName'] !== 'unknown');
        $this->assertStringNotContainsString($password, (string) $response->getContent());
    }

    #[DataProvider('endpoints')]
    public function test_a_mismatched_confirmation_is_refused(string $endpoint): void
    {
        [$response, $user, $email] = $this->submit($endpoint, 'Secure#Vault2026', 'Secure#Vault2027');

        $this->assertRefused($endpoint, $response, $user, $email);
    }

    #[DataProvider('endpoints')]
    public function test_the_new_password_signs_in_after_a_valid_change(string $endpoint): void
    {
        [, , $email] = $this->submit($endpoint, 'Trust@Portal82');

        $this->assertTrue($this->canSignIn($email, 'Trust@Portal82'), 'the new password works');
        if (! str_starts_with($endpoint, 'register')) {
            $this->assertFalse($this->canSignIn($email, self::ORIGINAL), 'the old one no longer does');
        }
    }

    public function test_numbers_only_gets_the_plain_explanation_on_the_forms(): void
    {
        [$response] = $this->submit('register-citizen', '12345678');
        $response->assertJsonPath('message', self::NUMBERS_ONLY);

        [$response] = $this->submit('citizen-profile', '12345678');
        $this->assertSame(self::NUMBERS_ONLY, session('errors')->first('password'));
    }

    // ---------------------------------------------------------------- bcrypt's 72-byte limit

    private const TOO_LONG = 'A password can be at most 72 bytes long (72 English letters, fewer if it uses other scripts).';

    /** @return array<string, array{0: string, 1: bool}> password, accepted? (every other rule is met) */
    private static function byteSamples(): array
    {
        return [
            '72 ASCII bytes'          => [str_repeat('Aa1#', 18), true],
            '73 ASCII bytes'          => [str_repeat('Aa1#', 18).'x', false],
            'multibyte, 64 bytes'     => ['Aa1#'.str_repeat('अ', 20), true],        // 24 characters
            'multibyte, 73 bytes'     => ['Aa1#'.str_repeat('अ', 23), false],       // only 27 characters
        ];
    }

    public static function byteLimitAtEveryEndpoint(): array
    {
        $cases = [];
        foreach (array_keys(self::endpoints()) as $endpoint) {
            foreach (self::byteSamples() as $label => [$password, $accepted]) {
                $cases["{$endpoint}: {$label}"] = [$endpoint, $password, $accepted];
            }
        }

        return $cases;
    }

    public function test_the_limit_is_72_bytes_not_72_characters(): void
    {
        $errors = fn (string $p) => Validator::make(['password' => $p, 'password_confirmation' => $p], ['password' => PasswordPolicy::rules()])->errors()->get('password');

        foreach (self::byteSamples() as $label => [$password, $accepted]) {
            $this->assertSame($accepted, $errors($password) === [], $label);
            $this->assertSame(! $accepted, in_array(self::TOO_LONG, $errors($password), true), $label);
        }

        $this->assertSame(72, strlen(self::byteSamples()['72 ASCII bytes'][0]));
        $this->assertSame([73, 27], [strlen($p = self::byteSamples()['multibyte, 73 bytes'][0]), mb_strlen($p)], 'over in bytes, far under 72 characters');

        // Why: bcrypt ignores everything after byte 72, so two such passwords would be the same one.
        $hash = Hash::make(str_repeat('Aa1#', 18).'first-tail');
        $this->assertTrue(Hash::check(str_repeat('Aa1#', 18).'other-tail', $hash));
    }

    #[DataProvider('byteLimitAtEveryEndpoint')]
    public function test_the_72_byte_limit_applies_at_every_endpoint(string $endpoint, string $password, bool $accepted): void
    {
        [$response, $user, $email] = $this->submit($endpoint, $password);

        if (! $accepted) {
            $this->assertRefused($endpoint, $response, $user, $email);
            $message = str_starts_with($endpoint, 'register') ? $response->json('errors') : session('errors')->get('password');
            $this->assertContains(self::TOO_LONG, $message);
            $this->assertStringNotContainsString($password, (string) $response->getContent());

            return;
        }

        str_starts_with($endpoint, 'register') ? $response->assertJsonPath('success', true) : $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check($password, User::where('email', $email)->value('password')));
        $this->assertTrue($this->canSignIn($email, $password), 'a password at the limit signs in');
    }

    public function test_existing_accounts_with_passwords_longer_than_72_bytes_still_sign_in_unchanged(): void
    {
        // Set before the limit (stored directly); signing in has no length limit.
        $long = str_repeat('Long#Pass1-', 10);   // 110 bytes
        $legacy = $this->person('public', $long);
        $hash = $legacy->password;

        $this->assertTrue($this->canSignIn($legacy->email, $long));
        $this->actingAs($legacy)->put(route('user.profile.update'), ['name' => 'Renamed Person', 'email' => $legacy->email])->assertSessionHasNoErrors();
        $this->assertSame($hash, $legacy->fresh()->password, 'not rehashed, not reset');
        $this->assertTrue($this->canSignIn($legacy->email, $long));
    }

    public function test_over_long_passwords_never_reach_logs_audit_entries_flashed_input_or_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $secrets = [str_repeat('Aa1#', 18).'x', 'Aa1#'.str_repeat('अ', 23)];

        foreach (['register-citizen', 'reset-code', 'reset-link', 'citizen-profile', 'notary-change', 'admin-sets-password'] as $endpoint) {
            foreach ($secrets as $password) {
                [$response] = $this->submit($endpoint, $password);

                $this->assertStringNotContainsString($password, (string) $response->getContent(), "{$endpoint} response");
                foreach (['password', 'password_confirmation', 'current_password'] as $field) {
                    $this->assertNull(session("_old_input.{$field}"), "{$endpoint}: {$field} is never flashed");
                }
            }
        }

        $stored = implode("\n", array_merge($logged,
            AuditLog::query()->get()->map(fn ($row) => json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE))->all(),
            DB::table('message_deliveries')->get()->map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE))->all()));

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    // ---------------------------------------------------------------- existing accounts

    public function test_existing_accounts_with_older_weak_passwords_still_sign_in_and_are_not_forced_to_change(): void
    {
        // Created before the policy (stored directly): the policy does not touch it.
        $legacy = $this->person('public', '12345678');
        $hash = $legacy->password;

        $this->assertTrue($this->canSignIn($legacy->email, '12345678'), 'an existing password keeps working');

        // Editing the profile without a new password leaves the password exactly as it was.
        $this->actingAs($legacy)->put(route('user.profile.update'), ['name' => 'Renamed Person', 'email' => $legacy->email])->assertSessionHasNoErrors();
        $this->assertSame($hash, $legacy->fresh()->password);

        // So does an Admin edit that leaves the password field blank.
        $admin = $this->person('admin');
        $this->actingAs($admin)->put(route('admin.users.update', $legacy), ['name' => 'Renamed Again', 'email' => $legacy->email,
            'mobile' => $legacy->mobile, 'role' => 'public', 'password' => '', 'password_confirmation' => ''])->assertSessionHasNoErrors();
        $this->assertSame($hash, $legacy->fresh()->password);
    }

    // ---------------------------------------------------------------- no password anywhere

    public function test_passwords_never_reach_logs_audit_entries_flashed_input_or_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $secrets = ['12345678', 'Secure#Vault2026', 'Secure#Vault2027', self::ORIGINAL];

        foreach (['register-citizen', 'citizen-profile', 'notary-change', 'admin-sets-password', 'reset-link'] as $endpoint) {
            foreach ([['12345678', null], ['Secure#Vault2026', 'Secure#Vault2027'], ['Secure#Vault2026', null]] as [$password, $confirmation]) {
                [$response] = $this->submit($endpoint, $password, $confirmation);

                foreach ($secrets as $secret) {
                    $this->assertStringNotContainsString($secret, (string) $response->getContent(), "{$endpoint} response");
                }
                foreach (['password', 'password_confirmation', 'current_password'] as $field) {
                    $this->assertNull(session("_old_input.{$field}"), "{$endpoint}: {$field} is never flashed");
                }
            }
        }

        $stored = implode("\n", array_merge($logged,
            AuditLog::query()->get()->map(fn ($row) => json_encode($row->getAttributes()))->all(),
            DB::table('message_deliveries')->get()->map(fn ($row) => json_encode($row))->all()));

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $stored, 'no password in logs, audit entries or delivery records');
        }
    }

    // ---------------------------------------------------------------- the forms

    public function test_every_password_form_shows_the_requirements_and_a_visibility_toggle(): void
    {
        $citizen = $this->person();
        $notary = $this->person('notary');
        $admin = $this->person('admin');

        $pages = [
            'register'     => fn () => $this->get(route('register')),
            'reset link'   => fn () => $this->get(route('password.reset.token', ['token' => 'example-token', 'email' => $citizen->email])),
            'citizen'      => fn () => $this->actingAs($citizen)->get(route('user.profile')),
            'notary'       => fn () => $this->actingAs($notary)->get(route('notary.profile')),
            'admin edit'   => fn () => $this->actingAs($admin)->get(route('admin.users.edit', $citizen)),
        ];

        foreach ($pages as $page => $open) {
            $open()->assertOk()
                ->assertSee('data-password-toggle', false)
                ->assertSee('data-password-rules', false)
                ->assertSee('Password requirements')
                ->assertSee('One special character (for example @ # $ ! % &amp;)', false)
                ->assertSee('name="password_confirmation"', false);
            $this->signOut();
        }
    }
}
