<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.mobile_verified_at (nullable timestamp).
 *
 * Purpose: record that the account holder completed an SMS OTP for the
 * mobile number on file (logged-in "Verify mobile" flow, or a successful
 * OTP-based password reset). It is independent of email_verified_at and of
 * notary_approved_at. Nothing in the application requires it yet; see
 * docs/VERIFICATION_POLICY.md.
 *
 * Safe and additive: no data is changed, existing rows get NULL.
 * NOT executed automatically - run `php artisan migrate` after review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'mobile_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('mobile_verified_at')->nullable()->after('email_verified_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'mobile_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mobile_verified_at');
            });
        }
    }
};
