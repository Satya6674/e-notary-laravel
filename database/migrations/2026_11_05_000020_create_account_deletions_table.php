<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletion of accounts. The user row stays, so applications, documents and audit
 * entries keep their owner. Each deletion gets its own record, kept after a restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->index();
        });

        Schema::create('account_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('deleted_by')->constrained('users');
            $table->string('name');
            $table->string('email');
            $table->string('role', 20);
            $table->string('reason', 500);
            $table->boolean('was_deactivated')->default(false);
            // Nullable on purpose: MariaDB would otherwise rewrite a required timestamp on every update.
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users');
            $table->timestamp('restored_at')->nullable();
            $table->uuid('deletion_notification_id')->nullable();
            $table->uuid('restore_notification_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
