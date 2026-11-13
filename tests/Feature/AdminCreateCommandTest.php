<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * enotary:admin-create: the first Admin on an empty installation, from the console only.
 */
class AdminCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Str0ng!FirstAdmin#Pass';

    private function runCreate(array $answers = [])
    {
        $a = $answers + [
            'name' => 'Demo Administrator', 'email' => 'Admin.Demo@Example.test', 'mobile' => '9000000099',
            'password' => self::STRONG, 'repeat' => self::STRONG,
        ];

        return $this->artisan('enotary:admin-create')
            ->expectsQuestion('Admin name', $a['name'])
            ->expectsQuestion('Admin email address', $a['email'])
            ->expectsQuestion('Admin 10-digit mobile number', $a['mobile'])
            ->expectsQuestion('Admin password (hidden, minimum 12 characters)', $a['password'])
            ->expectsQuestion('Repeat the password', $a['repeat']);
    }

    public function test_creates_one_admin_with_a_bcrypt_password_audits_it_and_can_sign_in(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->runCreate()->doesntExpectOutputToContain(self::STRONG)->assertSuccessful();

        $admin = User::where('role', 'admin')->sole();
        $this->assertSame('admin.demo@example.test', $admin->email);
        $this->assertStringStartsWith('$2y$', $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::STRONG, $admin->password));
        $this->assertSame(1, AuditLog::where('action', 'admin.created_cli')->where('model_id', $admin->id)->count());
        $this->assertStringNotContainsString(self::STRONG, (string) AuditLog::where('action', 'admin.created_cli')->value('meta'));

        $this->postJson('/custom-login', ['mobile' => $admin->email, 'password' => self::STRONG])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_refuses_when_any_admin_already_exists_and_asks_nothing(): void
    {
        User::create(['name' => 'Existing', 'email' => 'existing@example.test', 'mobile' => '9000000001',
            'password' => Hash::make('Old!Passw0rd#1'), 'role' => 'admin']);

        $this->artisan('enotary:admin-create')->expectsOutputToContain('An Admin account already exists')->assertFailed();

        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_refuses_without_an_interactive_terminal(): void
    {
        $this->artisan('enotary:admin-create', ['--no-interaction' => true])->assertFailed();

        $this->assertSame(0, User::count());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function rejectedAnswers(): array
    {
        return [
            'passwords differ' => [['repeat' => self::STRONG.'x']],
            'too short (11)' => [['password' => 'Abcdefg1!xy', 'repeat' => 'Abcdefg1!xy']],
            'no symbol' => [['password' => 'Abcdefgh1234', 'repeat' => 'Abcdefgh1234']],
            'over 72 bytes' => [['password' => str_repeat('Aa1!', 18).'x', 'repeat' => str_repeat('Aa1!', 18).'x']],
            'multibyte over 72 bytes' => [['password' => 'Aa1!'.str_repeat('क', 23), 'repeat' => 'Aa1!'.str_repeat('क', 23)]],
            'invalid email' => [['email' => 'not-an-email']],
            'invalid mobile' => [['mobile' => '12345']],
            'empty name' => [['name' => '']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedAnswers')]
    public function test_invalid_input_creates_nothing(array $answers): void
    {
        $this->runCreate($answers)->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::where('action', 'admin.created_cli')->count());
    }

    public function test_an_email_or_mobile_already_in_use_creates_nothing(): void
    {
        User::create(['name' => 'Citizen', 'email' => 'admin.demo@example.test', 'mobile' => '9000000002',
            'password' => Hash::make('Citizen!Pass1'), 'role' => 'public']);

        $this->runCreate()->assertFailed();
        $this->runCreate(['email' => 'other@example.test', 'mobile' => '9000000002'])->assertFailed();

        $this->assertSame(0, User::where('role', 'admin')->count());
    }

    public function test_a_second_run_is_refused_while_one_is_in_progress(): void
    {
        $held = Cache::lock('enotary:admin-create', 900);
        $this->assertTrue($held->get());

        try {
            $this->artisan('enotary:admin-create')->expectsOutputToContain('already running')->assertFailed();
        } finally {
            $held->release();
        }

        $this->assertSame(0, User::count());
        // Released afterwards, so a later run works.
        $this->runCreate()->assertSuccessful();
    }

    public function test_an_admin_that_appears_while_typing_stops_the_insert(): void
    {
        // Another way in (a second server, say) creates an Admin between the first check and the save.
        $calls = 0;
        DB::listen(function ($query) use (&$calls) {
            if (str_contains($query->sql, '"role" = ?') && ++$calls === 1) {
                DB::table('users')->insert(['name' => 'Raced', 'email' => 'raced@example.test', 'mobile' => '9000000077',
                    'password' => Hash::make('Raced!Passw0rd#1'), 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->runCreate()->expectsOutputToContain('An Admin account already exists')->assertFailed();

        $this->assertSame(['raced@example.test'], User::where('role', 'admin')->pluck('email')->all());
    }

    public function test_an_audit_failure_saves_nothing_and_prints_no_sql_or_hash(): void
    {
        AuditLog::creating(function (AuditLog $log) {
            if ($log->action === 'admin.created_cli') {
                throw new \RuntimeException('simulated audit failure');
            }
        });

        $this->runCreate()
            ->expectsOutputToContain('could not be saved. Nothing was changed.')
            ->doesntExpectOutputToContain('$2y$')
            ->doesntExpectOutputToContain('insert into')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::where('action', 'admin.created_cli')->count());
    }

    public function test_exactly_72_bytes_is_accepted(): void
    {
        $password = str_repeat('Aa1!', 18);
        $this->assertSame(72, strlen($password));

        $this->runCreate(['password' => $password, 'repeat' => $password])->assertSuccessful();
    }
}
