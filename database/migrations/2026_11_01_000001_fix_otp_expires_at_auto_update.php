<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB gave expires_at an implicit ON UPDATE current_timestamp(), so every
 * wrong OTP attempt rewrote the expiry. Declaring the default explicitly drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->timestamp('expires_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // The previous behaviour was a defect; there is nothing to restore.
    }
};
