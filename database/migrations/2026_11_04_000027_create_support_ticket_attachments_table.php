<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to support ticket messages (PDF, JPG, PNG only; see SupportAttachments).
 *
 * PREPARED ONLY. Not applied to any protected database (owner restriction until 2026-11-06).
 * Depends only on support_tickets, support_ticket_messages and users, which all exist, so it
 * may run after 000026 in the planned order. Until it runs, the upload field is hidden and
 * uploads are refused; tickets work as before.
 *
 * The file itself lives on the private disk; the row keeps its SHA-256 so a download can be
 * checked against what was uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name', 200);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
