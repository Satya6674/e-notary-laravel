<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('notary_requests')->onDelete('cascade');
            $table->string('original_file');
            $table->string('signed_file')->nullable();
            $table->string('document_number')->nullable()->unique();
            $table->string('qr_code')->nullable();
            $table->string('verification_code', 16)->nullable()->unique();
            $table->string('mime_type', 50)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
