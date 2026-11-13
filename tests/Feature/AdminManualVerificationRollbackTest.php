<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TemporaryAccessGrant;
use App\Models\User;
use App\Services\ManualVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A manual verification or a temporary access grant is kept only together with its records: when
 * the history row or the audit entry cannot be written, nothing is saved. The failure is forced by
 * a trigger in the dialect of the database under test (SQLite in phpunit, MariaDB through the
 * throwaway enotary_phpunit). DatabaseMigrations, not RefreshDatabase: on MariaDB CREATE TRIGGER
 * commits the open transaction, so each test gets a freshly migrated database instead.
 */
class AdminManualVerificationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const REASON = 'Called the registered number and confirmed identity with Aadhaar on video.';

    private const TRIGGER = 'phpunit_force_failure';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        Http::assertNothingSent();
        Notification::assertNothingSent();

        parent::tearDown();
    }

    /** A BEFORE INSERT trigger on $table that refuses rows where $column = $value. */
    private function failInsertsInto(string $table, string $column, string $value): void
    {
        $quoted = DB::connection()->getPdo()->quote($value);

        DB::unprepared(DB::connection()->getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER '.self::TRIGGER." BEFORE INSERT ON {$table} WHEN NEW.{$column} = {$quoted} BEGIN SELECT RAISE(ABORT, 'forced failure'); END"
            : 'CREATE TRIGGER '.self::TRIGGER." BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.{$column} = {$quoted} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END");
    }

    private function accounts(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'rollback.admin@example.test', 'password' => Hash::make('rollback-admin-1')]);
        $citizen = User::factory()->create(['role' => 'public', 'email' => 'rollback.citizen@example.test', 'mobile' => '9876500111', 'email_verified_at' => null, 'mobile_verified_at' => null]);

        return [$admin->fresh(), $citizen->fresh()];
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('The action should have failed with its record.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('forced failure', $e->getMessage());
        }
    }

    public function test_a_failed_audit_entry_rolls_an_email_verification_back(): void
    {
        [$admin, $citizen] = $this->accounts();
        $this->failInsertsInto('audit_logs', 'action', ManualVerificationService::EMAIL_VERIFIED);

        $this->assertRefused(fn () => app(ManualVerificationService::class)->verify($admin, $citizen, 'email', self::REASON, 'phone_call'));

        $citizen->refresh();
        $this->assertNull($citizen->email_verified_at);
        $this->assertNull($citizen->email_verification_method);
        $this->assertSame(0, DB::table('verification_events')->count(), 'the history row is rolled back with the change');
    }

    public function test_a_failed_history_row_rolls_a_mobile_verification_back(): void
    {
        [$admin, $citizen] = $this->accounts();
        $this->failInsertsInto('verification_events', 'method', User::METHOD_ADMIN_MANUAL);

        $this->assertRefused(fn () => app(ManualVerificationService::class)->verify($admin, $citizen, 'mobile', self::REASON, 'phone_call'));

        $citizen->refresh();
        $this->assertNull($citizen->mobile_verification_method);
        $this->assertNull($citizen->mobile_manual_verified_at);
        $this->assertFalse($citizen->mobileManualVerified());
        $this->assertSame(0, AuditLog::where('action', ManualVerificationService::MOBILE_VERIFIED)->count());
    }

    public function test_a_failed_audit_entry_rolls_a_temporary_access_grant_back(): void
    {
        [$admin, $citizen] = $this->accounts();
        $this->failInsertsInto('audit_logs', 'action', ManualVerificationService::ACCESS_GRANTED);

        $this->assertRefused(fn () => app(ManualVerificationService::class)->grant($admin, $citizen, 2, self::REASON, 'phone_call'));

        $this->assertSame(0, TemporaryAccessGrant::count());
        $this->assertFalse($citizen->fresh()->usingTemporaryAccess());
    }
}
