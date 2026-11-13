<?php

namespace Tests\Feature;

use App\Console\ScheduledTasks;
use App\Support\Platform;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Phase 1B (2026-11-11): the test process, and any process a test starts, is isolated from the live
 * installation. No live .env value, database, mail account, SMS key, storage folder or stop file can
 * be reached. These are the guarantees tests/TestCase.php enforces, checked one by one so a
 * regression names the exact leak.
 */
class TestEnvironmentIsolationTest extends TestCase
{
    public function test_the_live_env_file_is_not_the_loaded_environment(): void
    {
        $this->assertSame('.env.testing', basename(app()->environmentFilePath()));
        $this->assertSame('testing', app()->environment());
        $this->assertSame(self::TEST_APP_KEY, config('app.key'));
        $this->assertSame('http://localhost', config('app.url'));
        $this->assertFalse(config('app.debug'));
    }

    public function test_the_committed_test_env_file_holds_no_values(): void
    {
        // .env.testing can be committed (.gitignore exception), so it must never carry a setting:
        // every test value lives in phpunit.xml, where it is visible and reviewed.
        $lines = array_filter(array_map('trim', file(base_path('.env.testing'))), fn ($line) => $line !== '' && ! str_starts_with($line, '#'));

        $this->assertSame([], array_values($lines));
    }

    public function test_no_live_database_can_be_reached(): void
    {
        $default = config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        $this->assertTrue($database === ':memory:' || $database === 'enotary_phpunit', "test database: {$database}");
        $this->assertNotContains(strtolower($database), Platform::PERMANENT_DATABASES);
        $this->assertNotSame('enotary_local', (string) config('database.connections.mysql.database'));
        $this->assertSame('', (string) config('database.connections.mysql_admin.username', ''));
    }

    public function test_no_real_mail_account_server_or_address_is_configured(): void
    {
        $this->assertSame('array', config('mail.default'));
        $this->assertFalse(config('mail.live_enabled'));
        $this->assertSame('127.0.0.1', config('mail.mailers.smtp.host'));
        $this->assertSame(9, (int) config('mail.mailers.smtp.port'));
        $this->assertSame('phpunit-no-account', config('mail.mailers.smtp.username'));
        $this->assertSame('phpunit-not-a-password', config('mail.mailers.smtp.password'));
        $this->assertSame('no-reply@example.test', config('mail.from.address'));
        $this->assertEmpty(config('mail.admin_address'));
        $this->assertEmpty(config('services.outbound.mail'));
        $this->assertNotSame('local_gmail', config('mail.mode'));
    }

    public function test_no_sms_or_external_api_credential_is_configured(): void
    {
        $this->assertEmpty(config('services.msg91.auth_key'));
        $this->assertSame('off', Platform::smsMode());
        $this->assertEmpty(config('services.resend.key'));
        $this->assertEmpty(config('services.postmark.key') ?? config('services.postmark.token'));
        $this->assertEmpty(config('filesystems.disks.s3.key'));
        $this->assertEmpty(config('filesystems.disks.s3.secret'));

        // TestCase blocks any HTTP call that a test did not fake.
        $this->expectException(\RuntimeException::class);
        Http::get('https://example.org/');
    }

    public function test_queue_session_cache_and_scheduler_are_test_only(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('discard', config('logging.default'));
        $this->assertTrue(ScheduledTasks::safeMode());
        $this->assertFalse(config('accounts.admin_mfa_required'));
    }

    public function test_storage_and_the_email_stop_file_are_outside_the_live_folder(): void
    {
        $live = realpath(base_path('storage'));

        $this->assertNotFalse(realpath(storage_path()));
        $this->assertNotSame(strtolower($live), strtolower(realpath(storage_path())));
        $this->assertStringStartsWith(storage_path(), Platform::mailStopFile());
        $this->assertNotSame(base_path('storage'.DIRECTORY_SEPARATOR.Platform::MAIL_STOP_FILE), Platform::mailStopFile());
        // Compiled views are written under the test storage folder too.
        $this->assertStringStartsWith(strtolower(realpath(storage_path())), strtolower((string) realpath((string) config('view.compiled'))));
    }

    public function test_a_child_process_started_by_a_test_is_isolated_too(): void
    {
        // As schedule:run does: a separate PHP process that boots the app with the inherited environment.
        $result = Process::path(base_path())->timeout(60)->run([PHP_BINARY, base_path('tests/Support/print-environment.php')]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        $child = json_decode($result->output(), true);
        $this->assertIsArray($child, $result->output());

        $this->assertSame('.env.testing', $child['env_file']);
        $this->assertSame('testing', $child['environment']);
        $this->assertSame(strtolower(realpath(storage_path())), strtolower((string) realpath($child['storage'])), 'same test storage folder as the parent');
        $this->assertNotSame(strtolower((string) realpath(base_path('storage'))), strtolower((string) realpath($child['storage'])));
        $this->assertSame(config('database.default'), $child['db_driver']);
        $this->assertNotContains(strtolower($child['database']), Platform::PERMANENT_DATABASES);
        $this->assertSame(['array', false, '127.0.0.1', 'dummy', '', 'empty', 'test', 'sync', true],
            [$child['mail'], $child['live_mail'], $child['smtp_host'], $child['smtp_account'], $child['admin_address'], $child['msg91_key'], $child['app_key'], $child['queue'], $child['scheduler_safe']]);
    }
}
