<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring cycle counts. The scheduler turns each due schedule into a draft
 * "cycle" stock audit covering the N products in scope that were counted
 * least recently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_count_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // daily, weekly, monthly
            $table->string('frequency', 20);
            // all, location, warehouse, category
            $table->string('scope_type', 20)->default('all');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedInteger('products_per_run');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_audit_id')->nullable()->constrained('stock_audits')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('stock_audits', function (Blueprint $table) {
            $table->foreignId('cycle_count_schedule_id')->nullable()->after('audit_type')
                ->constrained('cycle_count_schedules')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            $table->dropForeign(['cycle_count_schedule_id']);
            $table->dropForeign(['assigned_to']);
            $table->dropColumn(['cycle_count_schedule_id', 'assigned_to']);
        });

        Schema::dropIfExists('cycle_count_schedules');
    }
};
