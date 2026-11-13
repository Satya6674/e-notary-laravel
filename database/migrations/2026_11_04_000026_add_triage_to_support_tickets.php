<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support ticket triage: a priority, the administrator responsible, and when it was closed.
 *
 * PREPARED ONLY. Not applied to any protected database (owner restriction until 2026-11-06).
 * Adds columns to support_tickets only, so its order against 000021/23/24/25 does not matter.
 * Until it runs, the application hides priority and assignment and keeps working.
 *
 * closed_at is nullable on purpose: on MariaDB 10.4 a NOT NULL timestamp silently gains
 * ON UPDATE CURRENT_TIMESTAMP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('priority', 10)->default('normal')->after('category');
            $table->foreignId('assigned_to')->nullable()->after('priority')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('status');

            // assigned_to is indexed by its foreign key on MariaDB. A second index here would
            // also break the rollback on SQLite, which cannot drop a column that is indexed.
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'priority']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['priority', 'closed_at']);
        });
    }
};
