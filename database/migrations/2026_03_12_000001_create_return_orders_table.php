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
        Schema::create('return_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('return_number')->unique();
            $table->enum('type', ['return', 'exchange'])->default('return');
            $table->enum('status', ['pending', 'approved', 'received', 'completed', 'rejected'])->default('pending');
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'type']);
            $table->index('return_number');
            $table->index('order_id');
        });

        Schema::create('return_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_order_id')->constrained('return_orders')->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->enum('condition', ['new', 'used', 'damaged'])->default('new');
            $table->boolean('restock')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_order_items');
        Schema::dropIfExists('return_orders');
    }
};
