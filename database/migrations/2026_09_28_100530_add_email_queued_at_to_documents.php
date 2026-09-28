<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "queued" from "sent" for the three customer/supplier emails.
 *
 * The send stamp used to be written when the email was pushed onto the queue,
 * so an install with no queue worker showed documents as sent that never left.
 * The queued_at columns now record the push; the existing sent columns are
 * written when the mail is actually delivered (MessageSent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('sent_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('invoice_queued_at')->nullable()->after('invoice_sent_at');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('customer_notification_queued_at')->nullable()->after('customer_notified_at');
        });

        // customer_notified_at doubled as the "already claimed" marker; carry
        // it over so shipments notified before this migration are not emailed
        // a second time.
        DB::table('shipments')
            ->whereNotNull('customer_notified_at')
            ->update(['customer_notification_queued_at' => DB::raw('customer_notified_at')]);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('customer_notification_queued_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('invoice_queued_at');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('queued_at');
        });
    }
};
