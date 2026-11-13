<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Migration 000024 on MariaDB: a user row that audit history refers to, as actor or as subject,
 * cannot be hard-deleted, while the application's archive-and-restore keeps working. Rollback
 * and re-apply are tested too. It runs only through scripts/Test-MariaDB.ps1, on the throwaway
 * enotary_phpunit database, rebuilt before and after each test. Every record is synthetic.
 */
class AuditActorRestrictMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_03_000024_restrict_audit_actor_deletion.php';

    private const MARKER_TRIGGERS = ['audit_logs_append_only_bd', 'audit_logs_append_only_bu', 'users_former_admin_bd', 'users_was_admin_bi', 'users_was_admin_bu'];

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
            'name' => "Restrict {$role} {$n}", 'email' => "restrict-{$role}-{$n}@example.test", 'mobile' => '94800'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function deleteRule(): ?string
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('CONSTRAINT_NAME', 'audit_logs_user_id_foreign')->value('DELETE_RULE');
    }

    private function triggers(): array
    {
        return DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all();
    }

    /** The error code MariaDB gave, or null when the delete went through. */
    private function deleteError(User $user): ?string
    {
        try {
            DB::table('users')->where('id', $user->id)->delete();

            return null;
        } catch (QueryException $e) {
            return (string) ($e->errorInfo[1] ?? $e->getCode());
        }
    }

    public function test_the_actor_key_is_restrict_and_the_subject_trigger_exists(): void
    {
        $this->assertSame('RESTRICT', $this->deleteRule());
        $this->assertContains('users_audit_subject_bd', $this->triggers());
        foreach (self::MARKER_TRIGGERS as $trigger) {
            $this->assertContains($trigger, $this->triggers(), "{$trigger} (000023) survived the key change");
        }
    }

    public function test_a_user_referenced_by_audit_history_cannot_be_hard_deleted_and_attribution_stays(): void
    {
        // As the actor only (the entry is about something else): refused by the foreign key
        // (MariaDB error 1451). An entry naming the user as subject would hit the trigger first.
        $actor = $this->make('public');
        $asActor = AuditLog::recordOrFail('admin.settings_updated', null, ['changed' => ['site_name']], $actor->id);
        $this->assertSame('1451', $this->deleteError($actor));
        $this->assertSame($actor->id, (int) DB::table('audit_logs')->where('id', $asActor->id)->value('user_id'));

        // As the subject only, with no actor: refused by the trigger (SIGNAL, error 1644).
        $subject = $this->make('public');
        AuditLog::recordOrFail('auth.login_failed', $subject, [], null);
        $this->assertSame('1644', $this->deleteError($subject));

        // A Notary that acted, with applications that would be cascade-deleted with the row:
        // the whole delete is refused, so the applications stay too.
        $notary = $this->make('notary');
        $application = NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $notary->id,
            'document_type' => 'affidavit', 'purpose' => 'Synthetic', 'status' => 'pending']);
        AuditLog::recordOrFail('request.submitted', $application, [], $notary->id);
        $this->assertNotNull($this->deleteError($notary));
        $this->assertNotNull(NotaryRequest::find($application->id), 'The cascade was rolled back with the refused delete.');

        foreach ([$actor, $subject, $notary] as $user) {
            $this->assertNotNull($user->fresh());
        }

        // Exact: a row nothing refers to can still be removed.
        $plain = $this->make('public');
        $this->assertNull($this->deleteError($plain));
    }

    public function test_archiving_and_restoring_an_account_still_work(): void
    {
        $citizen = $this->make('public');
        AuditLog::recordOrFail('user.profile_updated', $citizen, ['changed' => ['name']], $citizen->id);
        $service = app(AccountDeletionService::class);
        // Signed in, as in the real request, so the entries carry the administrator as actor.
        $this->actingAs($this->admin);

        $service->delete($this->admin, $citizen, 'Synthetic deletion for testing.');
        $this->assertTrue($citizen->fresh()->isDeleted());
        $this->assertNotNull(AccountDeletion::where('user_id', $citizen->id)->sole()->identity_released_at, 'A Citizen identity is released as before.');

        $service->restore($this->admin, $citizen->fresh(), 'Synthetic restore for testing.');
        $this->assertFalse($citizen->fresh()->isDeleted());
        $this->assertSame($citizen->email, $citizen->fresh()->email, 'The identity came back.');
        $this->assertTrue($citizen->fresh()->mustResetPassword());

        // Both entries are there, with the administrator as actor.
        $entries = AuditLog::where('model_type', 'User')->where('model_id', $citizen->id)
            ->whereIn('action', ['admin.user_deleted', 'admin.user_restored'])->pluck('user_id', 'action');
        $this->assertSame(['admin.user_deleted' => $this->admin->id, 'admin.user_restored' => $this->admin->id], $entries->map(fn ($id) => (int) $id)->sortKeys()->all());
    }

    public function test_rollback_restores_set_null_and_re_apply_restores_restrict(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertExitCode(0);

        $this->assertSame('SET NULL', $this->deleteRule());
        $this->assertNotContains('users_audit_subject_bd', $this->triggers());
        foreach (self::MARKER_TRIGGERS as $trigger) {
            $this->assertContains($trigger, $this->triggers(), "{$trigger} (000023) is untouched by the rollback");
        }

        // The old behaviour is back: the actor is cleared. This is what 000024 prevents.
        $citizen = $this->make('public');
        $entry = AuditLog::recordOrFail('user.profile_updated', null, [], $citizen->id);
        $this->assertNull($this->deleteError($citizen));
        $this->assertNull(DB::table('audit_logs')->where('id', $entry->id)->value('user_id'));

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertExitCode(0);

        $this->assertSame('RESTRICT', $this->deleteRule());
        $this->assertContains('users_audit_subject_bd', $this->triggers());
        $actor = $this->make('public');
        AuditLog::recordOrFail('user.profile_updated', null, [], $actor->id);
        $this->assertSame('1451', $this->deleteError($actor));
    }
}
