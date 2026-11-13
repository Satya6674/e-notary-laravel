<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notary_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('notary_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('document_type', [
                'affidavit',
                'power_of_attorney',
                'sale_deed',
                'rent_agreement',
                'will',
                'declaration',
                'undertaking',
                'indemnity_bond',
                'other'
            ]);
            $table->text('purpose');
            $table->enum('status', ['pending', 'assigned', 'approved', 'completed', 'rejected'])
                ->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamp('scheduled_date')->nullable();
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
            $table->string('payment_reference')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['notary_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_requests');
    }
};
