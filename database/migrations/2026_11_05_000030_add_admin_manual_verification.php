<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin manual verification and time-limited login access (Admin > User Verification Management).
 *
 * Needs 000029 (verification_events). Additive except for one foreign key, and nothing existing is
 * rewritten:
 *
 * users gains two nullable columns. mobile_verified_at is untouched: it stays reserved for a code
 * delivered by SMS, because it unlocks SMS password reset. A manual mobile check never sets it.
 *   mobile_manual_verified_at   when an Admin confirmed the number by other means
 *   mobile_manual_number        the number that check covered, so a changed number never inherits it
 *
 * verification_events gains who acted, why, how ownership was checked, and the status before and
 * after, all nullable, so the rows that OTP, the signed link, the email fallback and SMS write are
 * unchanged. It becomes immutable in the database as well as in code: a trigger refuses every edit
 * and every delete, and user_id moves from ON DELETE CASCADE to RESTRICT, as MariaDB fires no
 * trigger for a cascade. The application never hard-deletes an account, so nothing it does changes.
 *
 * temporary_access_grants holds each time-limited login exception. Nothing in it marks anything as
 * verified. A row may change once only, to record its revocation; the trigger refuses anything else.
 * Expiry needs no write: the application compares expires_at with the clock on every request.
 *
 * Nullable timestamps, because MariaDB gives a required timestamp ON UPDATE CURRENT_TIMESTAMP.
 *
 * NOT APPLIED to enotary_local or any other MariaDB database. Tested on SQLite (phpunit) only.
 */
return new class extends Migration
{
    private const USER_COLUMNS = ['mobile_manual_verified_at', 'mobile_manual_number'];

    private const EVENT_COLUMNS = ['reason', 'evidence_method', 'previous_status', 'new_status'];

    private const TRIGGERS = [
        'verification_events_append_only_bu',
        'verification_events_append_only_bd',
        'temporary_access_grants_bu',
        'temporary_access_grants_bd',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mobile_manual_verified_at')->nullable();
            $table->string('mobile_manual_number', 15)->nullable();
        });

        Schema::table('verification_events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->string('evidence_method', 40)->nullable();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40)->nullable();
        });

        Schema::create('temporary_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('evidence_method', 40);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'expires_at']);
        });

        $this->driver() === 'sqlite' ? $this->sqliteTriggers() : $this->mariadbTriggers();
    }

    /** Removes the protection, the grants and the new columns. verification_events itself stays (000029). */
    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        Schema::dropIfExists('temporary_access_grants');

        Schema::table('verification_events', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('verification_events', fn (Blueprint $table) => $table->dropColumn(['actor_id', ...self::EVENT_COLUMNS]));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(self::USER_COLUMNS));
    }

    private function mariadbTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER verification_events_append_only_bu BEFORE UPDATE ON verification_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verification history cannot be changed.'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER verification_events_append_only_bd BEFORE DELETE ON verification_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verification history cannot be deleted.'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER temporary_access_grants_bu BEFORE UPDATE ON temporary_access_grants FOR EACH ROW
            BEGIN
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL OR NEW.revoked_by IS NULL
                    OR NOT (NEW.id <=> OLD.id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.granted_by <=> OLD.granted_by)
                    OR NOT (NEW.reason <=> OLD.reason) OR NOT (NEW.evidence_method <=> OLD.evidence_method)
                    OR NOT (NEW.starts_at <=> OLD.starts_at) OR NOT (NEW.expires_at <=> OLD.expires_at)
                    OR NOT (NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A temporary access record can only be revoked, once.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER temporary_access_grants_bd BEFORE DELETE ON temporary_access_grants FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Temporary access records cannot be deleted.'
            SQL);
    }

    private function sqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER verification_events_append_only_bu BEFORE UPDATE ON verification_events
            BEGIN
                SELECT RAISE(ABORT, 'Verification history cannot be changed.');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER verification_events_append_only_bd BEFORE DELETE ON verification_events
            BEGIN
                SELECT RAISE(ABORT, 'Verification history cannot be deleted.');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER temporary_access_grants_bu BEFORE UPDATE ON temporary_access_grants
            WHEN OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL OR NEW.revoked_by IS NULL
                OR NEW.id IS NOT OLD.id OR NEW.user_id IS NOT OLD.user_id OR NEW.granted_by IS NOT OLD.granted_by
                OR NEW.reason IS NOT OLD.reason OR NEW.evidence_method IS NOT OLD.evidence_method
                OR NEW.starts_at IS NOT OLD.starts_at OR NEW.expires_at IS NOT OLD.expires_at
                OR NEW.created_at IS NOT OLD.created_at
            BEGIN
                SELECT RAISE(ABORT, 'A temporary access record can only be revoked, once.');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER temporary_access_grants_bd BEFORE DELETE ON temporary_access_grants
            BEGIN
                SELECT RAISE(ABORT, 'Temporary access records cannot be deleted.');
            END
            SQL);
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'sqlite' : 'mariadb';
    }
};
