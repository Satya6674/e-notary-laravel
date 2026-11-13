<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->char('file_hash', 64)->nullable()->after('file_size');
            $table->timestamp('certified_at')->nullable()->after('verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['file_hash', 'certified_at']);
        });
    }
};
