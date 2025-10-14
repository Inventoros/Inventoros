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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // low_stock, order_created, order_updated, order_shipped, etc.
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable(); // Additional data (product_id, order_id, etc.)
            $table->string('action_url')->nullable(); // Link to related resource
            $table->timestamp('read_at')->nullable();
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
