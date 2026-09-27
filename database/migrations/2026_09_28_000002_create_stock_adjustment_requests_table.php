<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual stock adjustment that is waiting for approval.
 *
 * Kept apart from stock_adjustments on purpose: that table is the stock
 * ledger (every row moved stock, with a before and after), and reports,
 * exports and webhooks all read it as such. A request only reaches the
 * ledger when it is approved, through StockAdjustment::adjust(), and then
 * points at the ledger row it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('product_locations')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->integer('quantity');
            $table->decimal('value', 15, 2)->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            // pending, approved, rejected
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('stock_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'requested_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_requests');
    }
};
