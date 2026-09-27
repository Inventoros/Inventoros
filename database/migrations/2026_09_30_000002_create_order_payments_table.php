<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (and refunds) received against sales orders.
 *
 * A row is never edited or deleted once recorded: a mistake is corrected by
 * voiding it (voided_at/voided_by/void_reason), which keeps the audit trail.
 * Refunds are rows of type 'refund' with a positive amount that counts
 * against what was paid.
 *
 * The order keeps a denormalised `amount_paid` and `payment_status`, always
 * recomputed from these rows under the order's row lock, so lists can filter
 * and reports can age receivables without re-summing every payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->default('payment');
            $table->decimal('amount', 12, 2);
            $table->string('method', 32);
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'order_id']);
            $table->index(['organization_id', 'paid_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total');
            $table->string('payment_status', 16)->default('unpaid')->after('amount_paid');

            $table->index(['organization_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'payment_status']);
            $table->dropColumn(['amount_paid', 'payment_status']);
        });

        Schema::dropIfExists('order_payments');
    }
};
