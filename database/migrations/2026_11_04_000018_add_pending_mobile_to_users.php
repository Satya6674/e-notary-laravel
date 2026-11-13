<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds a requested mobile number until its owner proves it by SMS code, so
 * users.mobile only ever changes to a number that has been verified.
 *
 * Not unique: nobody owns a number by asking for it. users.mobile stays the only
 * unique record, and is re-checked at the moment of activation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_mobile', 15)->nullable()->after('mobile');
            $table->dateTime('pending_mobile_requested_at')->nullable()->after('pending_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_mobile', 'pending_mobile_requested_at']);
        });
    }
};
