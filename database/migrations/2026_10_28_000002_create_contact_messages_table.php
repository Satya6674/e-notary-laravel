<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the contact_messages table.
 *
 * The ContactMessage model, the admin "Contact Messages" screen
 * (MessageController: search by name/email/subject/message, filter by
 * is_read, markRead, destroy) and the admin dashboard's unread-message
 * count all expect this table, but no migration ever created it. The
 * admin code guards with Schema::hasTable, so the page showed empty.
 * This forward migration makes the schema consistent with that code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_messages')) {
            Schema::create('contact_messages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->string('subject')->nullable();
                $table->text('message');
                $table->boolean('is_read')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
