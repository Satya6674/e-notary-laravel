<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The DOCX MIME type is 71 characters; the column held 50. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('mime_type', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not narrowed again: stored DOCX types would no longer fit.
    }
};
