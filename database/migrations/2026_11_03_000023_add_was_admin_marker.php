<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent former-Admin marker: users.was_admin_at, the first time an account is known to
 * have been an administrator. Database triggers keep it, so it is set even when a role is changed
 * by direct SQL, and it can never be cleared or changed afterwards. A former Admin's row cannot
 * be hard-deleted, and audit entries cannot be changed or deleted.
 *
 * What it does NOT stop: someone with the TRIGGER, DROP or ALTER privilege (today the application
 * connects as root) can drop the triggers, alter the table or TRUNCATE audit_logs, which fires
 * no trigger. See docs/audit/FORMER_ADMIN_MARKER_PROPOSAL.md.
 *
 * Dated 2026-11-03, so it sorts before 000017 to 000021, which are dated later. It depends only
 * on users and audit_logs, which exist from the first migrations; account_deletions is used for
 * the backfill only when it already exists. Those later migrations only add columns to users.
 *
 * NOT APPLIED to enotary, enotary_local or enotary_scratch. Tested only on enotary_phpunit.
 */
return new class extends Migration
{
    private const TRIGGERS = [
        'users_was_admin_bi', 'users_was_admin_bu', 'users_was_admin_au', 'users_was_admin_keep',
        'users_former_admin_bd', 'audit_logs_append_only_bu', 'audit_logs_append_only_bd',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('was_admin_at')->nullable()->after('role')->index();
        });

        $this->backfill();

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $this->mariadbTriggers(),
            'sqlite' => $this->sqliteTriggers(),
            default => throw new RuntimeException('The former-Admin triggers are written for MariaDB and SQLite only.'),
        };
    }

    /** Removes the protection. A privileged, owner-approved action. */
    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['was_admin_at']);
            $table->dropColumn('was_admin_at');
        });
    }

    /**
     * Every account the application's five existing signals identify, marked at migration time:
     * today's Admins, role changes to or from Admin, CLI Admin password resets, Admin actions
     * performed by the account, and deletions made while it was an Admin.
     */
    private function backfill(): void
    {
        $now = now();
        $mark = fn ($ids) => DB::table('users')->whereIn('id', $ids)->whereNull('was_admin_at')->update(['was_admin_at' => $now]);

        $mark(DB::table('users')->where('role', 'admin')->select('id'));

        $mark(DB::table('audit_logs')->where('model_type', 'User')->whereNotNull('model_id')
            ->where(fn ($q) => $q->whereIn('action', ['admin.role_changed', 'admin.password_reset_cli'])
                ->orWhere(fn ($u) => $u->where('action', 'admin.user_updated')
                    ->where(fn ($r) => $r->where('meta->role_from', 'admin')->orWhere('meta->role_to', 'admin'))))
            ->select('model_id'));

        $mark(DB::table('audit_logs')->whereNotNull('user_id')->where('action', 'like', 'admin.%')->select('user_id'));

        if (Schema::hasTable('account_deletions')) {
            $mark(DB::table('account_deletions')->where('role', 'admin')->select('user_id'));
        }
    }

    private function mariadbTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_was_admin_bi BEFORE INSERT ON users FOR EACH ROW
            BEGIN
                IF NEW.role = 'admin' AND NEW.was_admin_at IS NULL THEN
                    SET NEW.was_admin_at = UTC_TIMESTAMP();
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_was_admin_bu BEFORE UPDATE ON users FOR EACH ROW
            BEGIN
                IF OLD.was_admin_at IS NOT NULL THEN
                    SET NEW.was_admin_at = OLD.was_admin_at;
                ELSEIF OLD.role = 'admin' OR NEW.role = 'admin' THEN
                    SET NEW.was_admin_at = COALESCE(NEW.was_admin_at, UTC_TIMESTAMP());
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_former_admin_bd BEFORE DELETE ON users FOR EACH ROW
            BEGIN
                IF OLD.was_admin_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A current or former administrator account cannot be deleted from the database.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_append_only_bu BEFORE UPDATE ON audit_logs FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit entries cannot be changed.'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_append_only_bd BEFORE DELETE ON audit_logs FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit entries cannot be deleted.'
            SQL);
    }

    /** SQLite cannot assign to NEW, so the marker is set by AFTER triggers and guarded by BEFORE ones. */
    private function sqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_was_admin_bi AFTER INSERT ON users
            WHEN NEW.role = 'admin' AND NEW.was_admin_at IS NULL
            BEGIN
                UPDATE users SET was_admin_at = strftime('%Y-%m-%d %H:%M:%S', 'now') WHERE id = NEW.id;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_was_admin_keep BEFORE UPDATE OF was_admin_at ON users
            WHEN OLD.was_admin_at IS NOT NULL AND (NEW.was_admin_at IS NULL OR NEW.was_admin_at <> OLD.was_admin_at)
            BEGIN
                SELECT RAISE(ABORT, 'was_admin_at cannot be changed or cleared.');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_was_admin_au AFTER UPDATE OF role ON users
            WHEN (OLD.role = 'admin' OR NEW.role = 'admin') AND NEW.was_admin_at IS NULL
            BEGIN
                UPDATE users SET was_admin_at = strftime('%Y-%m-%d %H:%M:%S', 'now') WHERE id = NEW.id;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_former_admin_bd BEFORE DELETE ON users
            WHEN OLD.was_admin_at IS NOT NULL
            BEGIN
                SELECT RAISE(ABORT, 'A current or former administrator account cannot be deleted from the database.');
            END
            SQL);

        // MariaDB fires no trigger for a foreign-key action, so deleting a user row there sets
        // audit_logs.user_id to NULL (ON DELETE SET NULL) without the trigger seeing it. SQLite
        // does fire triggers for it. To behave like MariaDB, SQLite lets exactly that change
        // through: user_id to NULL with every other column unchanged.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_append_only_bu BEFORE UPDATE ON audit_logs
            WHEN NOT (NEW.user_id IS NULL AND OLD.user_id IS NOT NULL AND NEW.id IS OLD.id
                AND NEW.action IS OLD.action AND NEW.model_type IS OLD.model_type AND NEW.model_id IS OLD.model_id
                AND NEW.meta IS OLD.meta AND NEW.ip_address IS OLD.ip_address AND NEW.device IS OLD.device
                AND NEW.created_at IS OLD.created_at)
            BEGIN
                SELECT RAISE(ABORT, 'Audit entries cannot be changed.');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_append_only_bd BEFORE DELETE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'Audit entries cannot be deleted.');
            END
            SQL);
    }
};
