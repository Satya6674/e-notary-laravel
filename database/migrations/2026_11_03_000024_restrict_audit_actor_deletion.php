<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user row that audit history refers to can no longer be hard-deleted.
 *
 * - As the actor: audit_logs.user_id changes from ON DELETE SET NULL to ON DELETE RESTRICT.
 *   Before, deleting a user silently cleared the actor on their entries; on MariaDB no trigger
 *   sees a foreign-key action, so the append-only triggers of 000023 could not stop it.
 * - As the subject (model_type = 'User', model_id = the user): no foreign key can express that,
 *   so a BEFORE DELETE trigger on users refuses it.
 *
 * The application never hard-deletes users: deleting an account archives it (users.deleted_at)
 * and restoring brings the same row back, so neither is affected. Only a direct DELETE (SQL,
 * phpMyAdmin, a script) is refused. Someone with ALTER, DROP or TRIGGER (today root) can still
 * undo this; see docs/audit/DATABASE_ACCOUNT_SPLIT_PLAN.md.
 *
 * Dated 2026-11-03 like 000023, so it sorts right after it and before 000017 to 000021. It needs
 * only users and audit_logs. NOT APPLIED to enotary, enotary_local or enotary_scratch; tested
 * only on enotary_phpunit.
 */
return new class extends Migration
{
    private const SUBJECT_TRIGGER = 'users_audit_subject_bd';

    public function up(): void
    {
        $this->changeActorKey(restrict: true);

        DB::unprepared(match ($this->driver()) {
            'sqlite' => <<<'SQL'
                CREATE TRIGGER users_audit_subject_bd BEFORE DELETE ON users
                WHEN EXISTS (SELECT 1 FROM audit_logs WHERE model_type = 'User' AND model_id = OLD.id)
                BEGIN
                    SELECT RAISE(ABORT, 'This account is referenced by audit history and cannot be deleted from the database.');
                END
                SQL,
            default => <<<'SQL'
                CREATE TRIGGER users_audit_subject_bd BEFORE DELETE ON users FOR EACH ROW
                BEGIN
                    IF EXISTS (SELECT 1 FROM audit_logs WHERE model_type = 'User' AND model_id = OLD.id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account is referenced by audit history and cannot be deleted from the database.';
                    END IF;
                END
                SQL,
        });
    }

    /** Back to ON DELETE SET NULL, without the subject trigger. Removes the protection. */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::SUBJECT_TRIGGER);

        $this->changeActorKey(restrict: false);
    }

    private function changeActorKey(bool $restrict): void
    {
        // SQLite changes a foreign key by rebuilding the table, which drops its triggers (the
        // append-only ones from 000023). Their definitions are kept and recreated unchanged.
        $triggers = $this->driver() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'audit_logs')->pluck('sql')->all()
            : [];

        Schema::table('audit_logs', function (Blueprint $table) use ($restrict) {
            $table->dropForeign(['user_id']);
            $key = $table->foreign('user_id')->references('id')->on('users');
            $restrict ? $key->restrictOnDelete() : $key->nullOnDelete();
        });

        foreach ($triggers as $sql) {
            DB::unprepared($sql);
        }
    }

    private function driver(): string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'mariadb' : DB::connection()->getDriverName();
    }
};
