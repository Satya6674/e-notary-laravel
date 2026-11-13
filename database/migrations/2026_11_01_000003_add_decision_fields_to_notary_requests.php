<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'assigned', 'correction_requested', 'approved', 'completed', 'rejected'])
                ->default('pending')->change();
            $table->text('decision_remarks')->nullable()->after('rejection_reason');
            $table->foreignId('decided_by')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notary_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn('decision_remarks');
        });
    }
};
