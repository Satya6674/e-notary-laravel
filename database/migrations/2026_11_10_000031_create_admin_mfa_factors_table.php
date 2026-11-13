<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin two-factor sign-in (TOTP). One row per account. A new table rather than new columns on
 * `users`, so the users table and its four triggers are not altered.
 *
 * - secret and recovery_codes are stored encrypted with APP_KEY (Eloquent `encrypted` casts);
 *   recovery codes are additionally bcrypt-hashed inside that payload, so a database reader sees
 *   neither the secret nor usable codes.
 * - confirmed_at is set only after the Admin enters a valid code from the app (enrollment proof).
 * - last_used_step blocks replaying a code inside its 30-second window.
 *
 * Additive only: no existing table, row, trigger, index or foreign key is changed. Rollback drops
 * just this table (losing enrollments, which the Admin can redo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_mfa_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->text('recovery_codes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_mfa_factors');
    }
};
