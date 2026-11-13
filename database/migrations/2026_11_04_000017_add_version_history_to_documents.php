<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version history for uploaded documents.
 *
 * A correction no longer sits beside the file it replaces: the new row points at the
 * old one, and the old one is stamped superseded. The unique index means a document
 * can be replaced only once, so one version can never have two current successors.
 *
 * superseded_at is DATETIME, not TIMESTAMP: on this MariaDB a TIMESTAMP column can
 * pick up ON UPDATE current_timestamp(), the fault behind migration 000001.
 *
 * Existing rows stay version 1 and current. Older "Corrected document" rows are not
 * linked to what they replaced, because nothing records which file that was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('file_hash');
            $table->foreignId('replaces_document_id')->nullable()->after('version')->unique()
                ->constrained('documents')->nullOnDelete();
            $table->dateTime('superseded_at')->nullable()->after('replaces_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['replaces_document_id']);
            $table->dropUnique(['replaces_document_id']);
            $table->dropColumn(['version', 'replaces_document_id', 'superseded_at']);
        });
    }
};
