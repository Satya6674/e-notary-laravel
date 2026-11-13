<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a deleted Citizen or Notary register again, and lets them ask to recover the old
 * account. The real email and mobile move to account_deletions, so the unique indexes on
 * users stay as they are and still guarantee one active owner for each.
 *
 * Every timestamp here is nullable on purpose: MariaDB rewrites a required one on update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deletions', function (Blueprint $table) {
            $table->string('mobile', 15)->nullable();
            $table->timestamp('identity_released_at')->nullable();
            $table->boolean('notice_suppressed')->default(false);
            $table->index('email');
            $table->index('mobile');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('previous_account_choice', 20)->nullable();
            $table->timestamp('password_reset_required_at')->nullable();
            $table->text('retired_passwords')->nullable();
        });

        Schema::create('account_recovery_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('account_deletion_id')->constrained('account_deletions');
            $table->foreignId('old_user_id')->constrained('users');
            $table->foreignId('requester_id')->constrained('users');
            $table->string('applicant_name');
            $table->string('verified_email');
            $table->string('verified_mobile', 15)->nullable();
            $table->string('match_type', 10);
            $table->string('email_proof', 30);
            $table->string('mobile_proof', 30);
            $table->string('environment', 30);
            $table->string('status', 20)->default('pending')->index();
            // Holds the deletion id while a request is open, so only one can be open at a time.
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->string('conflict_resolution', 500)->nullable();
            $table->uuid('requested_notification_id')->nullable();
            $table->uuid('alert_notification_id')->nullable();
            $table->uuid('approved_notification_id')->nullable();
            $table->uuid('rejected_notification_id')->nullable();
            $table->uuid('reset_notification_id')->nullable();
            $table->timestamps();
        });
    }

    /** Refuses while an identity is released, as rolling back would strand it. */
    public function down(): void
    {
        if (DB::table('account_deletions')->whereNotNull('identity_released_at')->whereNull('restored_at')->exists()) {
            throw new RuntimeException('Released identities exist. Restore them before rolling this back.');
        }

        Schema::dropIfExists('account_recovery_requests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['previous_account_choice', 'password_reset_required_at', 'retired_passwords']);
        });

        Schema::table('account_deletions', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropIndex(['mobile']);
            $table->dropColumn(['mobile', 'identity_released_at', 'notice_suppressed']);
        });
    }
};
