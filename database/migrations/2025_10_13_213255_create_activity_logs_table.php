<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Entity being logged (product, order, user, etc.)
            $table->string('subject_type'); // Model class name
            $table->unsignedBigInteger('subject_id'); // Model ID

            // Action performed
            $table->string('action'); // created, updated, deleted, exported, imported, etc.
            $table->string('description')->nullable(); // Human-readable description

            // Changes made (JSON format)
            $table->json('properties')->nullable(); // old_values, new_values, attributes

            // Additional context
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // Indexes for better query performance
            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
