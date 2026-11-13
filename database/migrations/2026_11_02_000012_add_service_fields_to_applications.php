<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records which catalogue service an application used and what the citizen entered.
// All new columns are nullable: earlier applications stay as they are.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_requests', function (Blueprint $table) {
            $table->string('service_slug')->nullable()->after('document_type');
            $table->text('service_details')->nullable()->after('service_slug');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('requirement_key')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('notary_requests', fn (Blueprint $table) => $table->dropColumn(['service_slug', 'service_details']));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('requirement_key'));
    }
};
