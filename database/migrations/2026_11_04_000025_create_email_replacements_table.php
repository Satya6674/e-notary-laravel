<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replacing a lost email address of a restored account (owner decision, 2026-11-04).
 *
 * An administrator, after checking the person's identity outside the system, proposes a new
 * address. Nothing changes until that address opens a single-use, expiring link; only then does
 * the account move to it and get its password link there. The table keeps the request, the
 * administrator's recorded check (a method and a short note, never document numbers or files)
 * and the outcome. The link's token is stored only as a SHA-256 hash.
 *
 * open_key (the account) and open_email (the proposed address, lower case) are set only while a
 * replacement is pending, so the unique indexes allow one pending replacement per account and
 * per address, even under concurrent requests.
 *
 * Dated 2026-11-04, after the support and contact tables it refers to. NOT APPLIED to enotary,
 * enotary_local or enotary_scratch; tested only on enotary_phpunit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_replacements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('support_ticket_id')->nullable()->constrained('support_tickets');
            $table->foreignId('contact_message_id')->nullable()->constrained('contact_messages');
            $table->string('old_email');
            $table->string('new_email');
            $table->string('identity_check', 40);
            $table->string('identity_note', 300);
            $table->string('reason', 500);
            $table->char('token_hash', 64);
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            $table->string('open_email')->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->uuid('verification_notification_id')->nullable();
            $table->uuid('alert_notification_id')->nullable();
            $table->uuid('reset_notification_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_replacements');
    }
};
