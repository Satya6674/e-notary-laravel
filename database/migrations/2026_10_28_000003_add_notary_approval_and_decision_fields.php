<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, nullable columns only (safe to run on the existing database):
 *
 *  users.notary_approved_at         - admin approval of a notary account.
 *                                     Previously email_verified_at was (mis)used
 *                                     for this; it now means "email verified".
 *  notary_requests.rejection_reason - safe reason shown to the user.
 *  notary_requests.decided_at       - when approve/reject happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'notary_approved_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('notary_approved_at')->nullable()->after('email_verified_at');
            });
        }

        Schema::table('notary_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('notary_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('notary_requests', 'decided_at')) {
                $table->timestamp('decided_at')->nullable()->after('scheduled_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notary_requests', function (Blueprint $table) {
            foreach (['rejection_reason', 'decided_at'] as $col) {
                if (Schema::hasColumn('notary_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasColumn('users', 'notary_approved_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('notary_approved_at');
            });
        }
    }
};
