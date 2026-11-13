<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\PreviousAccountController;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\AccountDeletionService;
use App\Services\DeliveryLog;
use App\Services\OtpService;
use App\Support\Platform;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The safety architecture for real Gmail tests. Every mailer here is in memory: the "smtp"
 * transport is replaced by an array transport, so nothing can leave this process, and no
 * request can reach MSG91 (TestCase prevents stray HTTP requests).
 */
class LocalGmailSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVED = 'approved@example.test';

    private const OTHER = 'other@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function user(string $role, string $email, array $values = []): User
    {
        return User::factory()->create($values + ['role' => $role, 'email' => $email, 'email_verified_at' => now(), 'mobile_verified_at' => now()]);
    }

    /** The notification channel keeps the mailer it was built with, so it is rebuilt as well. */
    private function freshMailer(): void
    {
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        app()->forgetInstance(\Illuminate\Notifications\ChannelManager::class);
        Mail::clearResolvedInstances();
        \Illuminate\Support\Facades\Notification::clearResolvedInstances();
    }

    private function safePreview(): void
    {
        config(['mail.default' => 'log']);
        $this->freshMailer();
    }

    /** What Start-Local-Gmail.ps1 sets up, with the SMTP transport swapped for an in-memory one. */
    private function localGmailOverSmtp(string $approved = self::APPROVED, string $confirmed = ''): void
    {
        config(['mail.default' => 'smtp', 'mail.mode' => 'local_gmail', 'services.outbound.mail' => $approved, 'services.outbound.mail_confirmed' => $confirmed]);
        $this->freshMailer();
        Mail::extend('smtp', fn () => new ArrayTransport);
    }

    private function sentTo(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => array_map(fn ($a) => $a->getAddress(), $sent->getEnvelope()->getRecipients()))
            ->values()->all();
    }

    /** A deleted account plus a new verified account with the same email: the recovery-code step. */
    private function recoveryScenario(): User
    {
        $old = $this->user('public', 'returning@example.test', ['mobile' => '9876543210']);
        app(AccountDeletionService::class)->delete($this->user('admin', 'admin@example.test', ['mobile' => '9000000001']), $old, 'Requested by the account holder in writing.');

        Auth::logout();
        $this->postJson(route('register.submit'), ['name' => 'New Name', 'email' => 'returning@example.test', 'mobile' => '9876543210', 'password' => 'New-account-password1', 'password_confirmation' => 'New-account-password1', 'role' => 'public', 'accept_terms' => '1'])->assertOk();
        Auth::logout();

        $new = User::where('email', 'returning@example.test')->whereNull('deleted_at')->sole();
        $new->forceFill(['email_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verified_at' => now(), 'mobile_verification_method' => User::METHOD_MOCK_SMS, 'mobile_verification_status' => User::STATUS_SIMULATED])->save();

        return $new->fresh();
    }

    // ---- 1. Safe Preview never claims delivery

    public function test_forgot_password_in_safe_preview_sends_nothing_and_shows_no_preview_wording(): void
    {
        $this->safePreview();
        $this->user('public', 'citizen@example.test');

        $this->get(route('password.request'))->assertOk()
            ->assertDontSee('Safe Preview')->assertDontSee('test copy')->assertDontSee('SMS');

        $this->from(route('password.request'))->post(route('password.email'), ['identifier' => 'citizen@example.test'])
            ->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);
        $this->flushSession();
        $this->from(route('password.request'))->post(route('password.email'), ['identifier' => 'nobody@example.test'])
            ->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);

        // The real account's code went to the log only: recorded as simulated, never as accepted.
        $this->assertSame([MessageDelivery::SIMULATED], MessageDelivery::where('purpose', 'password_reset_code')->pluck('status')->all());
    }

    public function test_forgot_password_never_claims_delivery_for_an_unknown_answer(): void
    {
        $this->user('public', 'citizen@example.test');

        $this->from(route('password.request'))->post(route('password.email'), ['identifier' => 'citizen@example.test'])
            ->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);
        // It says what will happen if an account matches, never that an email was sent.
        $this->assertStringContainsString('If these details match an account', ForgotPasswordController::GENERIC_STATUS);
        $this->assertStringNotContainsString('has been sent', ForgotPasswordController::GENERIC_STATUS);
    }

    public function test_registration_in_safe_preview_never_claims_the_code_was_sent(): void
    {
        $this->safePreview();

        $response = $this->postJson(route('register.submit'), ['name' => 'Preview Person', 'email' => 'preview@example.test', 'mobile' => '9000000055', 'password' => 'Preview-password1', 'password_confirmation' => 'Preview-password1', 'role' => 'public', 'accept_terms' => '1']);
        $this->assertSame(\App\Http\Controllers\Auth\RegisterController::CODE_NOT_SENT, $response->json('message'));

        $user = User::where('email', 'preview@example.test')->sole();
        $this->assertSame(MessageDelivery::SIMULATED, MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'verify_email'])->value('status'));

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee("We couldn't send your verification code. Please try again later.")
            ->assertDontSee('has been sent')
            ->assertDontSee('Safe Preview')->assertDontSee('preview only');

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertSessionHas('error', \App\Http\Controllers\Auth\EmailVerificationController::CODE_NOT_SENT);
    }

    public function test_a_verification_email_not_yet_accepted_is_not_called_sent(): void
    {
        // The verification email is sent at once now, so a message still waiting is one recorded
        // as queued or sending (an older message, or one interrupted). The page must not call it sent.
        $user = User::factory()->create(['email' => 'queued@example.test', 'email_verified_at' => null]);
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'qu***@example.test', 'status' => MessageDelivery::QUEUED, 'user_id' => $user->id]);

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertSee('Enter the 6-digit code from the email to complete your registration.')
            ->assertDontSee('has been sent');
    }

    public function test_the_verification_email_is_sent_at_once_even_with_a_database_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->postJson(route('register.submit'), ['name' => 'Queued Person', 'email' => 'queued@example.test', 'mobile' => '9000000056', 'password' => 'Queued-password1', 'password_confirmation' => 'Queued-password1', 'role' => 'public', 'accept_terms' => '1'])->assertOk();
        $user = User::where('email', 'queued@example.test')->sole();

        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%VerifyEmailNotification%')->count());
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'verify_email'])->value('status'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertSee('A verification code has been sent to your registered email address.');
    }

    public function test_recovery_in_safe_preview_makes_no_code_and_says_so_plainly(): void
    {
        $new = $this->recoveryScenario();
        $this->safePreview();

        $this->actingAs($new)->get(route('account.previous.code'))->assertOk()
            ->assertDontSee('Safe Preview');

        $this->actingAs($new)->post(route('account.previous.send'))
            ->assertRedirect(route('account.previous.code'))
            ->assertSessionHas('error', PreviousAccountController::SAFE_PREVIEW_REFUSED)
            ->assertSessionMissing('status');
        $this->assertSame("We couldn't send your verification code. Please try again later.", PreviousAccountController::SAFE_PREVIEW_REFUSED);

        $this->assertSame(0, DB::table('otp_verifications')->where('otp', 'like', 'account_recovery|%')->count(), 'no recovery code exists');
        $this->assertSame(0, MessageDelivery::where('purpose', 'account_recovery_code')->count(), 'no message was built');
    }

    public function test_recovery_claims_acceptance_only_when_the_mail_service_accepted_it(): void
    {
        $new = $this->recoveryScenario();
        $this->localGmailOverSmtp('returning@example.test');

        $this->actingAs($new)->post(route('account.previous.send'))->assertSessionHas('status', PreviousAccountController::ACCEPTED_STATUS);
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('purpose', 'account_recovery_code')->latest('id')->value('status'));

        // Off the list: no code, no message, and an error instead of a success.
        $this->travel(2)->minutes();
        config(['services.outbound.mail' => self::APPROVED]);
        $before = count($this->sentTo());

        $this->actingAs($new)->post(route('account.previous.send'))->assertSessionHas('error')->assertSessionMissing('status');
        $this->assertCount($before, $this->sentTo());
        $this->assertSame(0, DB::table('otp_verifications')->where('otp', 'like', 'account_recovery|%')->where('expires_at', '>', now())->whereNull('verified_at')->count(), 'no usable recovery code was left behind');
    }

    // ---- 2. SMTP in Local Gmail never reaches an unapproved recipient

    public function test_smtp_local_gmail_sends_only_to_approved_addresses(): void
    {
        $this->localGmailOverSmtp();
        $approved = $this->user('public', self::APPROVED);
        $other = $this->user('public', self::OTHER);

        $approved->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        $other->notify(new PortalNotification('Title', 'Body', 'http://example.test'));

        $this->assertSame([[self::APPROVED]], $this->sentTo());
        $this->assertSame(MessageDelivery::BLOCKED, MessageDelivery::where('user_id', $other->id)->value('status'));
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('user_id', $approved->id)->value('status'));
    }

    public function test_a_copy_to_an_unapproved_address_stops_the_whole_message(): void
    {
        $this->localGmailOverSmtp();

        Mail::raw('Body', fn ($m) => $m->to(self::APPROVED)->cc(self::OTHER));
        Mail::raw('Body', fn ($m) => $m->to(self::APPROVED)->bcc(self::OTHER));

        $this->assertSame([], $this->sentTo());
    }

    public function test_an_empty_list_in_local_gmail_sends_to_nobody(): void
    {
        $this->localGmailOverSmtp('');

        $this->user('public', self::APPROVED)->notify(new PortalNotification('Title', 'Body', 'http://example.test'));

        $this->assertSame([], $this->sentTo());
    }

    public function test_an_admin_account_needs_confirmation_even_when_listed(): void
    {
        $admin = $this->user('admin', 'Owner.Admin@example.test');

        $this->localGmailOverSmtp('owner.admin@example.test');
        $admin->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        $this->assertSame([], $this->sentTo(), 'listed but not confirmed');

        $this->localGmailOverSmtp('owner.admin@example.test', 'owner.admin@example.test');
        $admin->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        $this->assertSame([['Owner.Admin@example.test']], $this->sentTo());
    }

    public function test_the_check_refuses_an_unconfirmed_admin_and_a_confirmation_off_the_list(): void
    {
        $this->localGmailOverSmtp('owner.admin@example.test');
        $this->user('admin', 'owner.admin@example.test');

        $this->assertContains('An approved recipient is an Admin account and was not confirmed: ow***@example.test.', $this->checkFailures());

        config(['services.outbound.mail_confirmed' => 'owner.admin@example.test,stranger@example.test']);
        $failures = $this->checkFailures();
        $this->assertNotContains('An approved recipient is an Admin account and was not confirmed: ow***@example.test.', $failures);
        $this->assertContains('A confirmed recipient is not on the approved list.', $failures);
    }

    // ---- 3. Apache and a plain artisan serve stay in Safe Preview

    public function test_the_permanent_env_file_is_safe_preview_or_deliberate_live_mail_and_has_no_start_script_settings(): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->markTestSkipped('No .env on this machine.');
        }

        $env = Dotenv::parse(File::get($path));

        // Either Safe Preview (the log mailer, Live Mail off) or deliberate Live Mail (approved for this
        // local copy on 2026-11-08): never a real mailer without the Live Mail switch and its rules.
        $live = strtolower(trim($env['LIVE_MAIL_ENABLED'] ?? 'false')) === 'true';
        $mailer = strtolower(trim($env['MAIL_MAILER'] ?? ''));
        $this->assertTrue($live ? $mailer === 'smtp' : $mailer === 'log', '.env must be Safe Preview (MAIL_MAILER=log) or Live Mail (LIVE_MAIL_ENABLED=true with MAIL_MAILER=smtp)');

        if ($live) {
            $this->assertSame('false', strtolower(trim($env['MSG91_ENABLED'] ?? '')), 'Live Mail is email only: MSG91 stays off');
            $this->assertContains(strtolower(trim($env['LIVE_MAIL_RECIPIENTS'] ?? 'allowlist')), ['allowlist', 'any']);
        }

        // OUTBOUND_ALLOW_MAIL belongs in .env only as the Live Mail allowlist.
        $startScriptKeys = ['MAIL_MODE', 'OUTBOUND_CONFIRM_MAIL', 'OUTBOUND_ALLOW_SMS', 'MSG91_SEND_BUDGET'];
        if (! ($live && strtolower(trim($env['LIVE_MAIL_RECIPIENTS'] ?? 'allowlist')) === 'allowlist')) {
            $startScriptKeys[] = 'OUTBOUND_ALLOW_MAIL';
        }

        foreach ($startScriptKeys as $key) {
            $this->assertArrayNotHasKey($key, $env, "{$key} belongs to a start script, never to .env");
        }

        $this->assertNotSame(Platform::LOCAL_GMAIL_DATABASE, $env['DB_DATABASE'] ?? null, 'the normal site does not run on the test database');
        $this->assertFileDoesNotExist(base_path('bootstrap/cache/config.php'), 'a cached configuration would hide .env from Apache');
    }

    public function test_nothing_serves_a_different_mailer_to_apache(): void
    {
        foreach (['.htaccess', 'public/.htaccess'] as $file) {
            if (is_file(base_path($file))) {
                $this->assertDoesNotMatchRegularExpression('/SetEnv\s+(MAIL_|OUTBOUND_|MSG91_|DB_DATABASE)/i', File::get(base_path($file)), $file);
            }
        }
    }

    public function test_a_log_mailer_is_always_safe_preview_whatever_else_is_set(): void
    {
        config(['mail.default' => 'log', 'mail.mode' => 'local_gmail', 'services.outbound.mail' => self::APPROVED]);

        $this->assertSame('safe_preview', Platform::mailMode());
    }

    public function test_the_check_refuses_when_the_env_file_turns_on_real_mail(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enotary-env-'.uniqid();
        File::ensureDirectoryExists($dir);
        // The file the application reads: .env in the app, .env.testing under PHPUnit (isolation).
        $file = $dir.DIRECTORY_SEPARATOR.$this->app->environmentFile();
        File::put($file, "MAIL_MAILER=smtp\n");
        $message = 'The permanent .env must keep MAIL_MAILER=log, so Apache and a plain artisan serve never send real mail.';

        try {
            $this->app->useEnvironmentPath($dir);
            $this->assertContains($message, $this->checkFailures());

            File::put($file, "MAIL_MAILER=log\n");
            $this->assertNotContains($message, $this->checkFailures());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    // ---- 4. Local Gmail runs only on the disposable database

    public function test_local_gmail_on_any_other_database_sends_nothing(): void
    {
        $this->localGmailOverSmtp();
        $approved = $this->user('public', self::APPROVED);
        config(['mail.local_gmail_database' => Platform::LOCAL_GMAIL_DATABASE]);

        $this->assertFalse(Platform::onLocalGmailDatabase());
        $this->assertFalse(DeliveryLog::allowed('mail', self::APPROVED));

        $approved->notify(new PortalNotification('Title', 'Body', 'http://example.test'));
        $this->assertSame([], $this->sentTo());

        $this->assertSame(1, Artisan::call('enotary:local-gmail-test', ['user' => $approved->id]));
        $this->assertStringContainsString('Local Gmail runs only on the enotary_gmail_test database', Artisan::output());
    }

    public function test_a_permanent_database_never_counts_as_the_test_database(): void
    {
        $connection = DB::connection();
        $name = $connection->getDatabaseName();

        try {
            foreach (Platform::PERMANENT_DATABASES as $permanent) {
                $connection->setDatabaseName($permanent);
                config(['mail.local_gmail_database' => $permanent]);

                $this->assertFalse(Platform::onLocalGmailDatabase(), $permanent);
            }
        } finally {
            $connection->setDatabaseName($name);
        }
    }

    public function test_the_check_names_the_disposable_database_and_no_permanent_one(): void
    {
        $this->assertSame('enotary_gmail_test', Platform::LOCAL_GMAIL_DATABASE);
        $this->assertNotContains(Platform::LOCAL_GMAIL_DATABASE, Platform::PERMANENT_DATABASES);
        $this->assertContains('enotary_local', Platform::PERMANENT_DATABASES);

        $this->localGmailOverSmtp();
        $this->assertContains('The database must be enotary_gmail_test, and is not.', $this->checkFailures());
    }

    public function test_the_check_refuses_an_address_with_an_account_in_a_permanent_database(): void
    {
        [$permanent, $cleanUp] = $this->permanentDatabaseFixture();

        try {
            $this->localGmailOverSmtp();
            config(['mail.local_gmail_permanent_databases' => [$permanent]]);

            $this->assertContains("An approved recipient has an account in the permanent database {$permanent} and was not confirmed: Ap***@example.test.", $this->checkFailures());

            config(['services.outbound.mail_confirmed' => self::APPROVED]);
            $this->assertSame([], array_values(array_filter($this->checkFailures(), fn ($f) => str_contains($f, 'permanent database'))));

            config(['services.outbound.mail_confirmed' => '', 'mail.local_gmail_permanent_databases' => [$permanent.'-missing']]);
            $this->assertContains("The permanent database {$permanent}-missing could not be read, so the approved recipients could not be cleared against it.", $this->checkFailures());
        } finally {
            DB::purge('local_gmail_permanent_check');
            $cleanUp();
        }
    }

    /**
     * A stand-in "permanent database" holding an account for the approved address, which the check
     * can open the way it opens a real one: with the test connection's own driver and user, and a
     * different database name. Never a real database.
     *
     * - SQLite: a temporary file with its own users table.
     * - MariaDB: the throwaway enotary_phpunit itself (TestCase allows no other). The row is written
     *   through a second connection, so it is committed and visible to the check's own connection,
     *   which cannot see this test's open transaction. It is removed again afterwards.
     *
     * @return array{0: string, 1: callable(): void} the database name for the check, and the clean-up
     */
    private function permanentDatabaseFixture(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $file = tempnam(sys_get_temp_dir(), 'enotary-permanent-');
            $pdo = new \PDO('sqlite:'.$file);
            $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT)');
            $pdo->exec("INSERT INTO users (email, role) VALUES ('Approved@example.test', 'public')");
            $pdo = null;

            return [$file, fn () => @unlink($file)];
        }

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('enotary_phpunit', $database, 'The stand-in must be the throwaway test database.');

        $name = 'phpunit_permanent_fixture';
        config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
        $id = DB::connection($name)->table('users')->insertGetId([
            'name' => 'Permanent Fixture', 'email' => 'Approved@example.test', 'password' => Hash::make('not-used-1'), 'role' => 'public',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$database, function () use ($name, $id) {
            DB::connection($name)->table('users')->where('id', $id)->delete();
            DB::purge($name);
        }];
    }

    public function test_the_start_script_keeps_smtp_and_the_test_database_inside_its_own_processes(): void
    {
        $script = File::get(base_path('scripts/Start-Local-Gmail.ps1'));

        $this->assertMatchesRegularExpression("/^\\\$env:MAIL_MAILER = 'smtp'\$/m", $script);
        $this->assertMatchesRegularExpression("/^\\\$env:DB_DATABASE = '".Platform::LOCAL_GMAIL_DATABASE."'\$/m", $script);
        $this->assertMatchesRegularExpression("/^\\\$env:MAIL_MODE = 'local_gmail'\$/m", $script);
        $this->assertStringContainsString('Remove-Item Env:OUTBOUND_CONFIRM_MAIL', $script, 'a confirmation is never inherited');

        foreach (Platform::PERMANENT_DATABASES as $permanent) {
            $this->assertDoesNotMatchRegularExpression("/DB_DATABASE\\s*=\\s*'{$permanent}'/", $script);
        }

        // Nothing in the script writes a file in the project, least of all .env.
        $this->assertDoesNotMatchRegularExpression('/(Set-Content|Add-Content|Out-File)[^\n]*\.env/i', $script);

        // -TestUser queues mail only after the same readiness check as a full start.
        $this->assertLessThan(strpos($script, 'enotary:local-gmail-test'), strpos($script, "if (-not \$ready.ok)"));
    }

    public function test_the_database_script_never_drops_and_creates_only_the_test_database(): void
    {
        $script = File::get(base_path('scripts/New-LocalGmailDatabase.ps1'));

        $this->assertStringNotContainsStringIgnoringCase('DROP ', $script);
        $this->assertStringNotContainsStringIgnoringCase('migrate:fresh', $script);
        $this->assertStringNotContainsStringIgnoringCase('db:seed', $script);
        $this->assertMatchesRegularExpression("/^\\\$database = '".Platform::LOCAL_GMAIL_DATABASE."'\$/m", $script);
    }

    // ---- 5. SMS stays off

    public function test_msg91_disabled_removes_the_key_whatever_env_holds(): void
    {
        $previous = [getenv('MSG91_ENABLED'), $_ENV['MSG91_ENABLED'] ?? null, $_SERVER['MSG91_ENABLED'] ?? null];
        putenv('MSG91_ENABLED=false');
        $_ENV['MSG91_ENABLED'] = $_SERVER['MSG91_ENABLED'] = 'false';

        try {
            $services = require config_path('services.php');
            $this->assertNull($services['msg91']['auth_key']);
        } finally {
            putenv($previous[0] === false ? 'MSG91_ENABLED' : 'MSG91_ENABLED='.$previous[0]);
            foreach (['_ENV' => 1, '_SERVER' => 2] as $global => $i) {
                if ($previous[$i] === null) {
                    unset($GLOBALS[$global]['MSG91_ENABLED']);
                } else {
                    $GLOBALS[$global]['MSG91_ENABLED'] = $previous[$i];
                }
            }
        }
    }

    public function test_the_start_script_turns_sms_off_unless_an_sms_approval_is_named(): void
    {
        $script = File::get(base_path('scripts/Start-Local-Gmail.ps1'));

        $this->assertMatchesRegularExpression("/\\} else \\{\\s*\\\$env:MSG91_ENABLED = 'false'\\s*\\}/", $script);
        $this->assertMatchesRegularExpression('/if \(\$SmsApproval\) \{/', $script);
    }

    public function test_a_mobile_code_in_local_gmail_with_sms_off_reaches_no_provider(): void
    {
        Http::fake();
        $this->localGmailOverSmtp();
        config(['services.msg91.auth_key' => null]);
        $user = $this->user('public', self::APPROVED, ['mobile' => '9000000077']);

        $this->assertSame('off', Platform::smsMode());
        $this->assertSame(OtpService::SEND_FAILED, app(OtpService::class)->issue('9000000077', '127.0.0.1', OtpService::FLOW_PASSWORD_RESET, $user->id));

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('otp_verifications')->where('expires_at', '>', now())->count(), 'no usable code was left behind');
    }

    // ---- Test runs leave the real log alone

    public function test_test_runs_write_nothing_to_the_real_log(): void
    {
        $this->assertSame('discard', config('logging.default'));

        $log = storage_path('logs/laravel.log');
        $before = is_file($log) ? [filesize($log), hash_file('sha256', $log)] : null;

        Log::error('LocalGmailSafetyTest probe: this line must never reach laravel.log');
        Log::warning('OTP SMS accepted by provider', ['mobile' => '******0000']);

        clearstatcache();
        $this->assertSame($before, is_file($log) ? [filesize($log), hash_file('sha256', $log)] : null);
    }

    /** @return list<string> */
    private function checkFailures(): array
    {
        Artisan::call('enotary:local-gmail-check', ['--json' => true]);

        return json_decode(Artisan::output(), true)['failures'];
    }
}
