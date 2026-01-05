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
        Schema::create('permission_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('permissions'); // Array of permission values
            $table->string('category')->nullable(); // e.g., "inventory", "orders", "admin"
            $table->string('icon')->nullable(); // Icon name for UI
            $table->boolean('is_template')->default(false); // System-wide templates
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0); // Display order
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
            $table->index(['is_template', 'category']);
        });

        // Pivot table for roles to permission sets (many-to-many)
        Schema::create('role_permission_set', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_set_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_set_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permission_set');
        Schema::dropIfExists('permission_sets');
    }
};
