<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders placed before payment tracking existed were given amount_paid 0 and
 * payment_status 'unpaid' by the payments migration, which made every
 * historical sale look like money owed (aging, dashboard, portal, invoices).
 * Nothing is actually known about how they were paid, so mark them
 * 'untracked' instead.
 *
 * Every order that exists when this runs and has no payment rows at all is
 * pre-tracking by definition. Orders with payment rows keep their derived
 * status. Safe to run whether or not the payments migration already ran on
 * this install, and a second run changes nothing. payment_status is a plain
 * string column, so no enum or check constraint needs widening.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('order_payments')
                    ->whereColumn('order_payments.order_id', 'orders.id');
            })
            ->where('payment_status', '!=', 'untracked')
            ->update(['payment_status' => 'untracked', 'amount_paid' => 0]);
    }

    public function down(): void
    {
        DB::table('orders')
            ->where('payment_status', 'untracked')
            ->update(['payment_status' => 'unpaid']);
    }
};
