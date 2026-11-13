<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile verification by email fallback, while SMS is unavailable, kept apart from SMS proof.
 *
 * users gains three nullable columns. mobile_verified_at is untouched and stays reserved for a
 * code delivered by SMS, because it unlocks SMS password reset and counts as phone proof.
 *   mobile_sms_status            not_sent for an email fallback; set by a future SMS delivery
 *   mobile_fallback_verified_at  when the email fallback code was accepted
 *   mobile_fallback_number       the number it covered, so a changed number never inherits it
 *
 * verification_events is an append-only history of every verification, so a later SMS
 * verification adds a row instead of overwriting the fallback record.
 *
 * Additive only. Nullable timestamps, because MariaDB gives a required timestamp an
 * ON UPDATE CURRENT_TIMESTAMP that would rewrite it on every save.
 */
return new class extends Migration
{
    private const COLUMNS = ['mobile_sms_status', 'mobile_fallback_verified_at', 'mobile_fallback_number'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile_sms_status', 20)->nullable();
            $table->timestamp('mobile_fallback_verified_at')->nullable();
            $table->string('mobile_fallback_number', 15)->nullable();
        });

        Schema::create('verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 20);            // email | mobile
            $table->string('method', 40);             // email_otp, signed_link, email_fallback, sms, mock_sms
            $table->string('target_masked', 100);     // never the full address or number
            $table->char('target_hash', 64);          // sha256 of the normalised address or number
            $table->string('delivered_via', 20);      // email | sms
            $table->string('sms_status', 20)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
