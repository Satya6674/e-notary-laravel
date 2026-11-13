<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An application being prepared: one per citizen, kept until it is submitted or discarded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('service_slug');
            $table->text('details')->nullable();
            $table->text('files')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_drafts');
    }
};
