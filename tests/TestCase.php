<?php

namespace Tests;

use App\Support\Platform;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test has its own schema, but statics outlive the application.
        Platform::forgetSchemaState();
        \App\Services\VerificationLedger::forgetSchemaState();
        \App\Models\TemporaryAccessGrant::forgetSchemaState();

        // .env holds a live MSG91 key: no test may ever reach a real provider.
        Http::preventStrayRequests();

        // The throwaway test database stands in for the Local Gmail database, which the check
        // above has already proved is not a real one. LocalGmailSafetyTest puts the real name back.
        config([
            'mail.local_gmail_database' => DB::connection()->getDatabaseName(),
            'mail.local_gmail_permanent_databases' => [],
            // The email emergency stop is a file the live web server and worker read. Tests use
            // their own, per process, so a test can never lift (or set) the real stop.
            'mail.stop_file' => storage_path('framework/testing/mail-stopped-'.getmypid()),
        ]);
        @unlink(config('mail.stop_file'));

        // Tests switch users inside one session, which no browser does. This
        // middleware has its own test in AccountSafetyTest.
        $this->withoutMiddleware(AuthenticateSession::class);

        \App\Services\AdminMfa::flushAvailability();

        // Tests written before the Admin step-up model an Admin who has just confirmed the password
        // (the "sudo" window is open). The step-up itself, its refusals and its expiry are covered by
        // AdminStepUpTest, which sets $adminPasswordConfirmed = false. Nothing is bypassed in the app.
        if ($this->adminPasswordConfirmed) {
            $this->withSession([\App\Support\AdminReauth::SESSION_KEY => time()]);
        }
    }

    /** See setUp(): false in tests of the Admin step-up itself. */
    protected bool $adminPasswordConfirmed = true;

    /**
     * Traits run during parent::setUp(), and RefreshDatabase wipes the database as it
     * starts. The check must come first, or it would only ever report the damage.
     */
    protected function setUpTraits()
    {
        $this->assertIsolatedEnvironment();

        return parent::setUpTraits();
    }

    /** In-memory SQLite, or the one throwaway database that scripts/Test-MariaDB.ps1 creates. */
    private function usesThrowawayDatabase(): bool
    {
        $default = config('database.default');
        $connection = config("database.connections.{$default}");

        // A URL overrides the fields below, so they would no longer describe the real target.
        if (! empty($connection['url'])) {
            return false;
        }

        return match ($default) {
            'sqlite' => $connection['database'] === ':memory:',
            'mysql', 'mariadb' => $connection['database'] === 'enotary_phpunit' && in_array($connection['host'], ['127.0.0.1', 'localhost'], true),
            default => false,
        };
    }

    /** The test-only APP_KEY from phpunit.xml. It encrypts nothing outside the test database. */
    public const TEST_APP_KEY = 'base64:yv0nszAzIq6zHk175e3THX3l2qQk2PJBKzLSFEyTrRc=';

    private function usesLiveStorage(): bool
    {
        $live = realpath(base_path('storage'));
        $used = realpath(storage_path());

        return $used === false || $live === false || strcasecmp($used, $live) === 0;
    }

    /** Refuses to run against anything but the throwaway test resources. */
    private function assertIsolatedEnvironment(): void
    {
        $unsafe = array_filter([
            'database'  => $this->usesThrowawayDatabase() ? null : config('database.default'),
            'mail'      => config('mail.default') === 'array' ? null : config('mail.default'),
            'queue'     => config('queue.default') === 'sync' ? null : config('queue.default'),
            'session'   => config('session.driver') === 'array' ? null : config('session.driver'),
            'cache'     => config('cache.default') === 'array' ? null : config('cache.default'),
            // Anything else can reach the real storage/logs/laravel.log, directly or through the
            // emergency logger that an undefined channel falls back to.
            'log'       => config('logging.default') === 'discard' ? null : (config('logging.default') ?? '(none)'),
            'msg91 key' => filled(config('services.msg91.auth_key')) ? 'set' : null,
            // The live .env must never be the loaded environment file (phpunit.xml + .env.testing).
            'env file'  => basename(app()->environmentFilePath()) === '.env.testing' ? null : basename(app()->environmentFilePath()),
            // Compiled views, fake disks and the test stop file stay out of the live storage folder.
            'storage'   => $this->usesLiveStorage() ? 'live storage/ folder' : null,
            // The same test-only key phpunit.xml sets; never the application's own APP_KEY.
            'app key'   => config('app.key') === self::TEST_APP_KEY ? null : 'not the test key',
        ]);

        if ($unsafe) {
            throw new RuntimeException('Tests refused, environment is not isolated: '.json_encode($unsafe));
        }
    }
}
