<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per outgoing email or SMS. Holds a masked recipient only, never message text or codes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);
            $table->string('purpose', 60);
            $table->string('recipient');
            $table->string('status', 20);
            $table->string('provider_reference')->nullable();
            $table->string('error')->nullable();
            $table->uuid('notification_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
    }
};
