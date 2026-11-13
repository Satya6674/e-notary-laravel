<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how each verification happened, so a local simulation can never read as genuine
 * SMS verification. mobile_verified_at stays reserved for a real code delivered by SMS.
 */
return new class extends Migration
{
    private const COLUMNS = ['email_verification_method', 'mobile_verification_method', 'mobile_verification_status', 'mobile_simulated_at'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_verification_method', 40)->nullable();
            $table->string('mobile_verification_method', 40)->nullable();
            $table->string('mobile_verification_status', 20)->nullable();
            $table->timestamp('mobile_simulated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
