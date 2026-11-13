<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AdminRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration 000023 (the permanent former-Admin marker) on MariaDB: rollback and re-apply, the
 * backfill of legacy accounts, every trigger, and the limits that remain while the application
 * connects as root. It runs only through scripts/Test-MariaDB.ps1, on the throwaway
 * enotary_phpunit database, which is rebuilt before and after each test. Every record is
 * synthetic. Never run against any other database.
 */
class AdminMarkerMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_03_000023_add_was_admin_marker.php';

    private const TRIGGERS = ['audit_logs_append_only_bd', 'audit_logs_append_only_bu', 'users_former_admin_bd', 'users_was_admin_bi', 'users_was_admin_bu'];

    private int $n = 10;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB: run scripts\Test-MariaDB.ps1.');
        }

        // TestCase has already refused anything but enotary_phpunit on this machine.
        $this->assertSame('enotary_phpunit', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $this->admin = $this->make('admin');
        $this->make('admin');
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->artisan('migrate:fresh');
        }

        parent::tearDown();
    }

    private function make(string $role): User
    {
        $n = ++$this->n;

        $user = User::create([
            'name' => "Marker {$role} {$n}", 'email' => "marker-{$role}-{$n}@example.test", 'mobile' => '94700'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    /** This migration's triggers that exist now (000024 adds users_audit_subject_bd of its own). */
    private function triggers(): array
    {
        return array_values(array_intersect(self::TRIGGERS, DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all()));
    }

    private function marker(User $user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('was_admin_at');
    }

    private function refused(callable $statement): bool
    {
        try {
            $statement();

            return false;
        } catch (QueryException $e) {
            return str_contains($e->getMessage(), '45000') || str_contains($e->getMessage(), '1644');
        }
    }

    // ---- Migration status, rollback and re-apply

    public function test_the_migration_is_in_place_and_rolls_back_and_re_applies_cleanly(): void
    {
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_11_03_000023_add_was_admin_marker')->exists());
        $this->assertTrue(Schema::hasColumn('users', 'was_admin_at'));
        $this->assertSame(self::TRIGGERS, $this->triggers());

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertExitCode(0);

        $this->assertFalse(Schema::hasColumn('users', 'was_admin_at'));
        $this->assertSame([], $this->triggers());
        $this->assertFalse(DB::table('migrations')->where('migration', '2026_11_03_000023_add_was_admin_marker')->exists());
        // Only this migration was rolled back.
        $this->assertTrue(Schema::hasTable('account_recovery_requests'));
        // Without the column the application still works and simply has no marker signal.
        $this->assertFalse(User::adminMarkerSupported());
        $this->assertSame(['current_role'], app(AccountDeletionService::class)->adminEvidence($this->admin));

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('users', 'was_admin_at'));
        $this->assertSame(self::TRIGGERS, $this->triggers());
        $this->assertNotNull($this->marker($this->admin), 'Backfilled on re-apply.');
    }

    public function test_applying_it_backfills_legacy_admin_accounts_and_nobody_else(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertExitCode(0);
        $service = app(AccountDeletionService::class);

        // Accounts as they exist before the migration, each with one kind of history.
        $current = $this->make('admin');
        $legacyDemoted = $this->make('public');
        AuditLog::recordOrFail('admin.user_updated', $legacyDemoted, ['changed' => ['role'], 'role_from' => 'admin', 'role_to' => 'public'], $this->admin->id);
        $actor = $this->make('public');
        AuditLog::recordOrFail('admin.settings_updated', null, ['changed' => ['site_name']], $actor->id);
        $cli = $this->make('public');
        AuditLog::recordOrFail('admin.password_reset_cli', $cli);
        $deletedAsAdmin = $this->make('admin');
        $service->delete($this->admin, $deletedAsAdmin, 'Synthetic deletion for testing.');
        $service->restore($this->admin, $deletedAsAdmin->fresh(), 'Synthetic restore for testing.');
        DB::table('users')->where('id', $deletedAsAdmin->id)->update(['role' => 'public']);
        $citizen = $this->make('public');
        AuditLog::recordOrFail('user.profile_updated', $citizen, ['changed' => ['name']], $citizen->id);
        $notary = $this->make('notary');

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertExitCode(0);

        foreach ([$current, $legacyDemoted, $actor, $cli, $deletedAsAdmin] as $account) {
            $this->assertNotNull($this->marker($account), "{$account->email} should be marked");
            $this->assertContains('permanent_marker', $service->adminEvidence($account->fresh()));
        }

        foreach ([$citizen, $notary] as $account) {
            $this->assertNull($this->marker($account), "{$account->email} was never an Admin");
        }
    }

    // ---- Promotion, demotion, deletion and restoration

    public function test_promotion_demotion_deletion_and_restoration_keep_the_marker(): void
    {
        $citizen = $this->make('public');
        $this->assertNull($this->marker($citizen));

        $roles = app(AdminRoleService::class);
        $citizen->role = 'admin';
        $roles->save($this->admin, $citizen);
        $marked = $this->marker($citizen);
        $this->assertNotNull($marked, 'Set on promotion.');

        $this->travel(5)->minutes();
        $citizen = $citizen->fresh();
        $citizen->role = 'public';
        $roles->save($this->admin, $citizen);
        $this->assertSame($marked, $this->marker($citizen), 'Kept, unchanged, on demotion.');

        $service = app(AccountDeletionService::class);
        $service->delete($this->admin, $citizen->fresh(), 'Synthetic deletion for testing.');
        $this->assertNull(AccountDeletion::where('user_id', $citizen->id)->sole()->identity_released_at);
        $this->assertSame($marked, $this->marker($citizen), 'Kept on deletion.');

        $service->restore($this->admin, $citizen->fresh(), 'Synthetic restore for testing.');
        $this->assertSame($marked, $this->marker($citizen), 'Kept on restore.');
    }

    public function test_a_direct_sql_demotion_is_marked_and_the_identity_survives_deletion(): void
    {
        // Created by direct SQL as an Admin, demoted by direct SQL, never touched by the application.
        DB::table('users')->insert([
            'name' => 'Silent Admin', 'email' => 'silent.admin@example.test', 'mobile' => '9470099999',
            'password' => Hash::make('demo-pass-123'), 'role' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $silent = User::where('email', 'silent.admin@example.test')->sole();
        $this->assertNotNull($this->marker($silent), 'The INSERT trigger set it.');

        DB::table('users')->where('id', $silent->id)->update(['role' => 'public']);

        $this->assertSame(['permanent_marker'], app(AccountDeletionService::class)->adminEvidence($silent->fresh()));

        app(AccountDeletionService::class)->delete($this->admin, $silent->fresh(), 'Synthetic deletion for testing.');
        $this->assertSame('silent.admin@example.test', $silent->fresh()->email);
        $this->assertSame('9470099999', $silent->fresh()->mobile);
    }

    // ---- The triggers

    public function test_the_marker_cannot_be_cleared_or_changed_and_a_former_admin_row_cannot_be_deleted(): void
    {
        $former = $this->make('admin');
        DB::table('users')->where('id', $former->id)->update(['role' => 'public']);
        $marked = $this->marker($former);

        DB::table('users')->where('id', $former->id)->update(['was_admin_at' => null]);
        $this->assertSame($marked, $this->marker($former), 'Clearing is ignored.');

        DB::table('users')->where('id', $former->id)->update(['was_admin_at' => '2001-01-01 00:00:00']);
        $this->assertSame($marked, $this->marker($former), 'Changing is ignored.');

        $this->assertTrue($this->refused(fn () => DB::table('users')->where('id', $former->id)->delete()));
        $this->assertTrue(DB::table('users')->where('id', $former->id)->exists());

        // A Citizen who was never an Admin, with no history, can still be removed.
        $plain = $this->make('public');
        DB::table('users')->where('id', $plain->id)->delete();
        $this->assertFalse(DB::table('users')->where('id', $plain->id)->exists());
    }

    public function test_audit_entries_cannot_be_changed_or_deleted_by_sql(): void
    {
        $entry = AuditLog::recordOrFail('test.entry', $this->admin, ['a' => 1], $this->admin->id);

        $this->assertTrue($this->refused(fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'test.changed'])));
        $this->assertTrue($this->refused(fn () => DB::table('audit_logs')->where('id', $entry->id)->delete()));
        $this->assertSame('test.entry', DB::table('audit_logs')->where('id', $entry->id)->value('action'));
    }

    // ---- What root can still do (known limits, kept visible on purpose)

    public function test_the_foreign_key_cascade_can_no_longer_clear_the_actor(): void
    {
        // Before 000024 this delete succeeded and, as MariaDB fires no trigger for foreign-key
        // actions, silently set user_id to NULL. With RESTRICT it is refused.
        $citizen = $this->make('public');
        $entry = AuditLog::recordOrFail('user.profile_updated', $citizen, ['changed' => ['name']], $citizen->id);

        $this->assertTrue($this->refusedDelete($citizen));
        $this->assertSame($citizen->id, (int) DB::table('audit_logs')->where('id', $entry->id)->value('user_id'));
    }

    private function refusedDelete(User $user): bool
    {
        try {
            DB::table('users')->where('id', $user->id)->delete();

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    public function test_known_limit_root_can_truncate_the_audit_log_and_drop_the_triggers(): void
    {
        AuditLog::recordOrFail('test.entry', $this->admin);

        // TRUNCATE fires no DELETE trigger. It needs the DROP privilege, which root has.
        DB::statement('TRUNCATE TABLE audit_logs');
        $this->assertSame(0, DB::table('audit_logs')->count());

        // Dropping the triggers needs the TRIGGER privilege, which root has.
        DB::unprepared('DROP TRIGGER users_was_admin_bi');
        DB::unprepared('DROP TRIGGER users_was_admin_bu');

        $unguarded = $this->make('admin');
        DB::table('users')->where('id', $unguarded->id)->update(['role' => 'public']);

        $this->assertNull($this->marker($unguarded));
        $this->assertSame([], app(AccountDeletionService::class)->adminEvidence($unguarded->fresh()), 'No trace is left: the protection depends on who holds these privileges.');
    }
}
