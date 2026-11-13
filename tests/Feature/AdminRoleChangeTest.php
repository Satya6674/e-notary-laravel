<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AdminRoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Former-Admin identity protection. Every change to or from Admin is saved together with its
 * audit entries, or not at all; the checks run again under a lock; and a former Admin's email and
 * mobile are never released by a deletion, so nobody can register with them afterwards. Every
 * account here is synthetic.
 */
class AdminRoleChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Old-demo-pass-1';

    private const REASON = 'Synthetic deletion for testing.';

    private const NOT_SAVED = 'The change could not be saved and recorded, so nothing was changed. Please try again.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin.one@example.test', '9488800001');
    }

    private function user(string $role, string $email, string $mobile): User
    {
        $user = User::create([
            'name' => 'Demo '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'email_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function setRole(User $actor, User $target, string $role)
    {
        return $this->actingAs($actor)->put(route('admin.users.update', $target), [
            'name' => $target->name, 'email' => $target->email, 'mobile' => $target->mobile, 'role' => $role,
        ]);
    }

    private function deleteAccount(User $target)
    {
        return $this->actingAs($this->admin)->delete(route('admin.users.delete', $target), ['reason' => self::REASON, 'confirm' => '1']);
    }

    private function register(string $email, string $mobile)
    {
        return $this->postJson(route('register.submit'), ['name' => 'Newcomer', 'email' => $email, 'mobile' => $mobile, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => 'public', 'accept_terms' => '1']);
    }

    /** Makes the audit write for one action fail, as a full disk or a lost connection would. */
    private function failAuditFor(string $action): void
    {
        AuditLog::creating(function (AuditLog $entry) use ($action) {
            if ($entry->action === $action) {
                throw new RuntimeException('Simulated audit write failure.');
            }
        });
    }

    private function assertIdentityKept(User $former, string $email, string $mobile): void
    {
        $deletion = AccountDeletion::where('user_id', $former->id)->sole();

        $this->assertNull($deletion->identity_released_at, 'A former Admin\'s identity is never released.');
        $this->assertSame($email, $former->fresh()->email);
        $this->assertSame($mobile, $former->fresh()->mobile);
        $this->assertTrue($former->fresh()->isDeleted());

        // This endpoint returns its errors as a plain list of messages.
        $this->register($email, '9488800999')->assertStatus(422)->assertJsonPath('message', 'The email has already been taken.');
        $this->register('somebody.new@example.test', $mobile)->assertStatus(422)->assertJsonPath('message', 'The mobile has already been taken.');
        $this->assertSame(0, User::where('email', 'somebody.new@example.test')->count());
    }

    // ---- Promotion

    public function test_a_promotion_is_recorded_as_an_admin_role_change(): void
    {
        $citizen = $this->user('public', 'citizen.one@example.test', '9488800010');

        $this->setRole($this->admin, $citizen, 'admin')->assertRedirect(route('admin.users.index'));

        $this->assertSame('admin', $citizen->fresh()->role);
        $entry = AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->where('model_id', $citizen->id)->sole();
        $this->assertSame(['role_from' => 'public', 'role_to' => 'admin', 'change' => 'granted'], $entry->meta);
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame('User', $entry->model_type);
        $this->assertSame(1, AuditLog::where('action', 'admin.user_updated')->where('model_id', $citizen->id)->count());
    }

    public function test_a_promotion_whose_audit_entry_fails_is_undone(): void
    {
        $citizen = $this->user('public', 'citizen.two@example.test', '9488800011');
        $this->failAuditFor(AdminRoleService::ROLE_CHANGED);

        $this->setRole($this->admin, $citizen, 'admin')->assertSessionHasErrors(['role' => self::NOT_SAVED]);

        $this->assertSame('public', $citizen->fresh()->role);
        $this->assertSame(0, AuditLog::where('model_id', $citizen->id)->where('action', 'admin.user_updated')->count());
    }

    public function test_a_role_change_that_does_not_touch_admin_is_still_recorded_with_its_roles(): void
    {
        $citizen = $this->user('public', 'citizen.three@example.test', '9488800012');

        $this->setRole($this->admin, $citizen, 'notary')->assertRedirect(route('admin.users.index'));

        $this->assertSame('notary', $citizen->fresh()->role);
        $this->assertSame(0, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->count());
        $this->assertSame(['role_from' => 'public', 'role_to' => 'notary'], array_intersect_key(
            AuditLog::where('action', 'admin.user_updated')->where('model_id', $citizen->id)->sole()->meta, ['role_from' => 1, 'role_to' => 1]));
    }

    // ---- Demotion

    public function test_a_demotion_is_recorded_and_the_identity_survives_deletion_and_re_registration(): void
    {
        $other = $this->user('admin', 'admin.two@example.test', '9488800002');

        $this->setRole($this->admin, $other, 'public')->assertRedirect(route('admin.users.index'));
        $this->assertSame('public', $other->fresh()->role);

        $entry = AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->where('model_id', $other->id)->sole();
        $this->assertSame(['role_from' => 'admin', 'role_to' => 'public', 'change' => 'revoked'], $entry->meta);

        $this->deleteAccount($other->fresh())->assertSessionHas('status');

        $this->assertIdentityKept($other, 'admin.two@example.test', '9488800002');
        $this->assertTrue(AuditLog::where('action', 'admin.user_deleted')->where('model_id', $other->id)->sole()->meta['former_admin']);
    }

    public function test_a_promoted_then_demoted_citizen_keeps_their_identity_when_deleted(): void
    {
        $citizen = $this->user('public', 'citizen.four@example.test', '9488800013');

        $this->setRole($this->admin, $citizen, 'admin');
        $this->setRole($this->admin, $citizen->fresh(), 'notary');
        $this->assertSame('notary', $citizen->fresh()->role);

        $this->deleteAccount($citizen->fresh())->assertSessionHas('status');

        $this->assertIdentityKept($citizen, 'citizen.four@example.test', '9488800013');
    }

    public function test_a_demotion_whose_audit_entry_fails_is_undone_and_cannot_release_the_identity(): void
    {
        $other = $this->user('admin', 'admin.three@example.test', '9488800003');
        $this->failAuditFor(AdminRoleService::ROLE_CHANGED);

        $this->setRole($this->admin, $other, 'public')->assertSessionHasErrors(['role' => self::NOT_SAVED]);

        $this->assertSame('admin', $other->fresh()->role, 'No demotion without its record.');
        $this->assertSame(0, AuditLog::where('model_id', $other->id)->where('action', 'admin.user_updated')->count());

        // Still an Admin, so a deletion keeps the identity.
        app(AccountDeletionService::class)->delete($this->admin, $other->fresh(), self::REASON);
        $this->assertIdentityKept($other, 'admin.three@example.test', '9488800003');
    }

    public function test_a_failed_update_entry_also_undoes_the_demotion(): void
    {
        $other = $this->user('admin', 'admin.four@example.test', '9488800004');
        $this->failAuditFor('admin.user_updated');

        $this->setRole($this->admin, $other, 'notary')->assertSessionHasErrors(['role' => self::NOT_SAVED]);

        $this->assertSame('admin', $other->fresh()->role);
        $this->assertSame(0, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->count());
    }

    public function test_an_ordinary_edit_is_not_saved_if_its_audit_entry_fails(): void
    {
        $citizen = $this->user('public', 'citizen.five@example.test', '9488800014');
        $this->failAuditFor('admin.user_updated');

        $this->actingAs($this->admin)->put(route('admin.users.update', $citizen), [
            'name' => 'Renamed Citizen', 'email' => $citizen->email, 'mobile' => $citizen->mobile, 'role' => 'public',
        ])->assertSessionHasErrors(['role' => self::NOT_SAVED]);

        // Since the 2026-11-10 hardening every Admin edit is audited in the same transaction.
        $this->assertNotSame('Renamed Citizen', $citizen->fresh()->name);
    }

    public function test_an_admin_cannot_demote_themselves(): void
    {
        $this->user('admin', 'admin.two@example.test', '9488800002');

        $this->setRole($this->admin, $this->admin, 'public')->assertSessionHasErrors('role');

        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame(0, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->count());
    }

    // ---- Checks made again under the lock

    public function test_two_admins_demoting_each_other_cannot_leave_no_admin(): void
    {
        $other = $this->user('admin', 'admin.two@example.test', '9488800002');

        // Both requests passed the admin middleware. The first one to take the lock wins.
        $staleOther = $other->fresh();
        $staleSelf = $this->admin->fresh();

        $target = $other->fresh();
        $target->role = 'public';
        app(AdminRoleService::class)->save($this->admin, $target);

        $staleSelf->role = 'public';

        try {
            app(AdminRoleService::class)->save($staleOther, $staleSelf);
            $this->fail('A demoted administrator must not finish a demotion begun before.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Only an active administrator', $e->getMessage());
        }

        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame(1, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->count());
    }

    public function test_a_deactivated_admin_cannot_change_a_role(): void
    {
        $other = $this->user('admin', 'admin.two@example.test', '9488800002');
        $stale = $other->fresh();
        $other->forceFill(['deactivated_at' => now()])->save();

        $citizen = $this->user('public', 'citizen.six@example.test', '9488800015');
        $citizen->role = 'admin';

        $this->expectException(AuthorizationException::class);

        try {
            app(AdminRoleService::class)->save($stale, $citizen);
        } finally {
            $this->assertSame('public', $citizen->fresh()->role);
        }
    }

    public function test_a_role_changed_by_someone_else_meanwhile_is_not_overwritten(): void
    {
        $other = $this->user('admin', 'admin.two@example.test', '9488800002');
        $third = $this->user('admin', 'admin.three@example.test', '9488800003');

        $page = $third->fresh();
        User::whereKey($third->id)->update(['role' => 'notary']);
        $page->role = 'public';

        try {
            app(AdminRoleService::class)->save($this->admin, $page);
            $this->fail('A stale edit must not be saved.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('changed by someone else', $e->getMessage());
        }

        $this->assertSame('notary', $third->fresh()->role);
        $this->assertSame('admin', $other->fresh()->role);
    }

    // ---- Former-Admin evidence, one signal at a time

    /**
     * A Citizen account carrying exactly one trace of having been an administrator. The role
     * itself is changed with a direct query where needed, so no other trace is left behind.
     */
    private function withSignal(string $signal, string $mobile): User
    {
        $account = $this->user('public', "signal.{$signal}@example.test", $mobile);

        match ($signal) {
            'current_role' => DB::table('users')->where('id', $account->id)->update(['role' => 'admin']),
            'role_change_audit' => [
                $this->setRole($this->admin, $account, 'admin'),
                $this->setRole($this->admin, $account->fresh(), 'public'),
            ],
            'legacy_role_change_audit' => AuditLog::recordOrFail('admin.user_updated', $account, ['changed' => ['role'], 'role_from' => 'admin', 'role_to' => 'public'], $this->admin->id),
            'cli_password_reset' => AuditLog::recordOrFail('admin.password_reset_cli', $account),
            'admin_actions' => AuditLog::recordOrFail('admin.settings_updated', null, ['changed' => ['site_name']], $account->id),
            'permanent_marker' => DB::table('users')->where('id', $account->id)->update(['was_admin_at' => now()]),
            'admin_deletion_record' => (function () use ($account) {
                DB::table('users')->where('id', $account->id)->update(['role' => 'admin']);
                $service = app(AccountDeletionService::class);
                $service->delete($this->admin, $account->fresh(), self::REASON);
                $service->restore($this->admin, $account->fresh(), 'Synthetic restore for testing.');
                DB::table('users')->where('id', $account->id)->update(['role' => 'public']);
            })(),
        };

        return $account->fresh();
    }

    public static function signals(): array
    {
        return [
            // A role that really moved to or from Admin also sets the permanent marker (migration 000023).
            'current Admin role' => ['current_role', ['current_role', 'permanent_marker'], '9488800040'],
            'Admin role-change audit' => ['role_change_audit', ['role_change_audit', 'permanent_marker'], '9488800041'],
            'role-change audit written before admin.role_changed existed' => ['legacy_role_change_audit', ['role_change_audit'], '9488800042'],
            'CLI Admin password reset' => ['cli_password_reset', ['cli_password_reset'], '9488800043'],
            'Admin action performed by the account' => ['admin_actions', ['admin_actions'], '9488800044'],
            'earlier deletion while an Admin' => ['admin_deletion_record', ['admin_deletion_record', 'permanent_marker'], '9488800045'],
            'permanent marker alone (a legacy account backfilled by the migration)' => ['permanent_marker', ['permanent_marker'], '9488800046'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signals')]
    public function test_each_signal_alone_is_detected_and_keeps_the_identity_on_deletion(string $setup, array $expected, string $mobile): void
    {
        $account = $this->withSignal($setup, $mobile);
        $email = $account->email;

        // Exactly these signals, so each one is shown to work on its own.
        $this->assertSame($expected, app(AccountDeletionService::class)->adminEvidence($account));

        $this->deleteAccount($account)->assertSessionHas('status');

        $this->assertNull(AccountDeletion::where('user_id', $account->id)->latest('id')->first()->identity_released_at);
        $this->assertSame($expected, AuditLog::where('action', 'admin.user_deleted')->where('model_id', $account->id)->latest('id')->first()->meta['admin_evidence']);
        $this->assertSame($email, $account->fresh()->email);
        $this->assertSame($mobile, $account->fresh()->mobile);
        $this->register($email, '9488800999')->assertStatus(422)->assertJsonPath('message', 'The email has already been taken.');
        $this->register('somebody.new@example.test', $mobile)->assertStatus(422)->assertJsonPath('message', 'The mobile has already been taken.');
    }

    public function test_every_signal_is_evaluated_even_when_an_earlier_one_matches(): void
    {
        $other = $this->user('admin', 'admin.two@example.test', '9488800002');
        $this->actingAs($other)->delete(route('admin.users.destroy', $this->user('public', 'citizen.nine@example.test', '9488800018')), ['reason' => 'Synthetic.']);
        AuditLog::recordOrFail('admin.password_reset_cli', $other);

        $this->assertSame(['current_role', 'cli_password_reset', 'admin_actions', 'permanent_marker'], app(AccountDeletionService::class)->adminEvidence($other->fresh()));
    }

    public function test_the_stored_role_counts_even_when_the_model_in_hand_is_stale(): void
    {
        $account = $this->user('public', 'stale.model@example.test', '9488800032');
        DB::table('users')->where('id', $account->id)->update(['role' => 'admin']);

        // $account was loaded before the change and still says Citizen.
        $this->assertSame('public', $account->role);
        $this->assertSame(['current_role', 'permanent_marker'], app(AccountDeletionService::class)->adminEvidence($account));
    }

    public function test_a_citizen_who_was_never_admin_still_releases_their_identity(): void
    {
        $citizen = $this->user('public', 'citizen.eight@example.test', '9488800017');
        $this->assertSame([], app(AccountDeletionService::class)->adminEvidence($citizen));

        $this->deleteAccount($citizen)->assertSessionHas('status');

        $this->assertNotNull(AccountDeletion::where('user_id', $citizen->id)->sole()->identity_released_at);
        $this->register('citizen.eight@example.test', '9488800017')->assertSuccessful();
    }

    /**
     * Migration 000023: an Admin who never acted, never used the CLI reset and was never deleted,
     * demoted by a direct query, now leaves the permanent marker behind: the database trigger sets
     * it in the same statement. Before 000023 this account left no trace at all.
     */
    public function test_an_admin_demoted_by_direct_sql_is_caught_by_the_database_marker(): void
    {
        $silent = $this->user('admin', 'admin.silent@example.test', '9488800030');
        DB::table('users')->where('id', $silent->id)->update(['role' => 'public']);

        $this->assertSame(['permanent_marker'], app(AccountDeletionService::class)->adminEvidence($silent->fresh()));

        $this->deleteAccount($silent->fresh())->assertSessionHas('status');
        $this->assertIdentityKept($silent, 'admin.silent@example.test', '9488800030');
    }

    public function test_the_marker_cannot_be_cleared_or_changed_by_direct_sql(): void
    {
        $admin = $this->user('admin', 'admin.marked@example.test', '9488800033');
        $marked = DB::table('users')->where('id', $admin->id)->value('was_admin_at');
        $this->assertNotNull($marked, 'Set when the Admin row was inserted.');

        foreach ([null, '2001-01-01 00:00:00'] as $value) {
            try {
                DB::table('users')->where('id', $admin->id)->update(['was_admin_at' => $value]);
            } catch (\Illuminate\Database\QueryException) {
                // SQLite refuses; MariaDB keeps the old value instead. Either way it stays.
            }
            $this->assertSame($marked, DB::table('users')->where('id', $admin->id)->value('was_admin_at'));
        }
    }

    /**
     * KNOWN LIMIT, kept visible on purpose: the marker is only as strong as the triggers. Anyone
     * allowed to drop them (today the application's own root connection) can then demote an Admin
     * with no trace. Closing this needs the database account split, which is not approved yet.
     * SQLite only here: on MariaDB, DROP TRIGGER commits and would break the per-test rollback, so
     * the same check runs in AdminMarkerMigrationTest on the throwaway database.
     */
    public function test_known_limit_whoever_can_drop_the_triggers_can_still_demote_without_a_trace(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Covered on MariaDB by AdminMarkerMigrationTest.');
        }

        DB::unprepared('DROP TRIGGER users_was_admin_bi');
        DB::unprepared('DROP TRIGGER users_was_admin_au');

        $silent = $this->user('admin', 'admin.unguarded@example.test', '9488800034');
        DB::table('users')->where('id', $silent->id)->update(['role' => 'public']);

        $this->assertSame([], app(AccountDeletionService::class)->adminEvidence($silent->fresh()));
    }

    // ---- Ordinary edits through the same save path

    public function test_an_unrelated_profile_edit_saves_every_field_and_records_only_field_names(): void
    {
        $citizen = $this->user('public', 'citizen.ten@example.test', '9488800019');

        $this->actingAs($this->admin)->put(route('admin.users.update', $citizen), [
            'name' => 'Edited Name', 'email' => 'edited.ten@example.test', 'mobile' => '9488800029', 'role' => 'public',
            'password' => 'New-demo-pass-9', 'password_confirmation' => 'New-demo-pass-9',
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();

        $fresh = $citizen->fresh();
        $this->assertSame('Edited Name', $fresh->name);
        $this->assertSame('edited.ten@example.test', $fresh->email);
        $this->assertSame('9488800029', $fresh->mobile);
        $this->assertTrue(Hash::check('New-demo-pass-9', $fresh->password));
        $this->assertNull($fresh->email_verified_at, 'A new address is unproven.');
        $this->assertNull($fresh->mobile_verified_at, 'A new number is unproven.');

        $entry = AuditLog::where('action', 'admin.user_updated')->where('model_id', $citizen->id)->sole();
        foreach (['name', 'email', 'mobile', 'password', 'email_verified_at', 'mobile_verified_at'] as $field) {
            $this->assertContains($field, $entry->meta['changed']);
        }
        $this->assertStringNotContainsString('New-demo-pass-9', json_encode($entry->meta));
        $this->assertStringNotContainsString('edited.ten@example.test', json_encode($entry->meta));
        $this->assertSame(0, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->count());
    }

    public function test_an_admin_can_edit_their_own_profile_and_another_admins_name(): void
    {
        $other = $this->user('admin', 'admin.two@example.test', '9488800002');

        $this->actingAs($this->admin)->put(route('admin.users.update', $this->admin), [
            'name' => 'Admin One Renamed', 'email' => $this->admin->email, 'mobile' => $this->admin->mobile, 'role' => 'admin',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('admin.users.update', $other), [
            'name' => 'Admin Two Renamed', 'email' => $other->email, 'mobile' => $other->mobile, 'role' => 'admin',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Admin One Renamed', $this->admin->fresh()->name);
        $this->assertSame('Admin Two Renamed', $other->fresh()->name);
        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame(0, AuditLog::where('action', AdminRoleService::ROLE_CHANGED)->count());
    }

    public function test_an_unchanged_form_saves_nothing_and_writes_no_entry(): void
    {
        $citizen = $this->user('public', 'citizen.eleven@example.test', '9488800031');

        $this->setRole($this->admin, $citizen, 'public')->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('action', 'admin.user_updated')->count());
    }

    // ---- Recovery

    public function test_recovery_cannot_archive_a_former_admin_holding_the_identity(): void
    {
        $citizen = $this->user('public', 'shared.identity@example.test', '9488800020');
        $service = app(AccountDeletionService::class);
        $service->delete($this->admin, $citizen, self::REASON);
        $deletion = AccountDeletion::sole();

        // Someone takes the released address, is made an Admin, then demoted.
        $holder = $this->user('public', 'shared.identity@example.test', '9488800021');
        $this->setRole($this->admin, $holder, 'admin');
        $this->setRole($this->admin, $holder->fresh(), 'public');

        $recovery = AccountRecoveryRequest::create(['reference' => 'REC-SYNTH', 'account_deletion_id' => $deletion->id, 'old_user_id' => $citizen->id, 'requester_id' => $holder->id, 'applicant_name' => 'Synthetic', 'verified_email' => $holder->email, 'verified_mobile' => $holder->mobile, 'match_type' => 'email', 'email_proof' => 'emailed_code', 'mobile_proof' => 'none', 'environment' => 'production', 'open_key' => $deletion->id]);

        $this->actingAs($this->admin)->post(route('admin.recovery.approve', $recovery), ['identity_checked' => '1'])
            ->assertSessionHas('error', 'An administrator account, current or former, holds this identity, so it cannot be archived here.');

        $this->assertFalse($holder->fresh()->isDeleted());
        $this->assertTrue($citizen->fresh()->isDeleted());
        $this->assertTrue($recovery->fresh()->isOpen());
    }
}
