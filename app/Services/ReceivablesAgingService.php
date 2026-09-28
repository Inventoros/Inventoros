<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order\Order;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Accounts receivable aging: what customers still owe on live (non-cancelled)
 * orders, bucketed by how old the order is.
 *
 * Orders carry no due date, so age is counted in calendar days from the order
 * date to today: "current" is an order placed today (or dated ahead), then
 * 1-30, 31-60, 61-90 and over 90 days. Overpaid orders owe nothing and are
 * left out rather than netted against other orders, and so are untracked
 * orders (placed before payment tracking, so nothing is known to be owed).
 *
 * Money is summed with Money (exact decimals), not SQL SUM, so the buckets
 * reconcile to the cent with the total.
 */
final class ReceivablesAgingService
{
    /**
     * Bucket keys in display order, with their upper bound in days (inclusive).
     */
    public const BUCKETS = [
        'current' => 0,
        '1_30' => 30,
        '31_60' => 60,
        '61_90' => 90,
        'over_90' => PHP_INT_MAX,
    ];

    /**
     * The report cuts the order list at this many rows (oldest first); the
     * buckets, customer totals and summary always cover every order.
     */
    public const ORDER_LIST_LIMIT = 500;

    /**
     * @return array{summary: array<string, mixed>, buckets: array<int, array<string, mixed>>, customers: array<int, array<string, mixed>>, orders: array<int, array<string, mixed>>}
     */
    public function build(int $organizationId, ?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->copy()->startOfDay();

        $buckets = array_fill_keys(array_keys(self::BUCKETS), ['count' => 0, 'amount' => '0.00']);
        $customers = [];
        $orders = [];
        $total = '0.00';
        $count = 0;

        $query = Order::query()
            ->where('organization_id', $organizationId)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            // Orders from before payment tracking are not known to be owed.
            ->where('payment_status', '!=', PaymentStatus::UNTRACKED->value)
            ->whereColumn('total', '>', 'amount_paid')
            ->orderBy('order_date')
            ->orderBy('id')
            ->select(['id', 'order_number', 'customer_id', 'customer_name', 'customer_email', 'order_date', 'status', 'total', 'amount_paid', 'payment_status', 'currency']);

        foreach ($query->cursor() as $order) {
            $balance = Money::subtract($order->total, $order->amount_paid);
            $age = $order->order_date
                ? max(0, (int) $order->order_date->copy()->startOfDay()->diffInDays($today, false))
                : 0;
            $bucket = $this->bucketFor($age);

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] = Money::add($buckets[$bucket]['amount'], $balance);
            $total = Money::add($total, $balance);
            $count++;

            $customerKey = $order->customer_id !== null
                ? 'id:'.$order->customer_id
                : 'name:'.mb_strtolower(trim((string) $order->customer_name));
            $customers[$customerKey] ??= array_merge(
                ['customer' => $order->customer_name ?: 'Unknown customer', 'customer_id' => $order->customer_id, 'orders' => 0, 'total' => '0.00'],
                array_fill_keys(array_keys(self::BUCKETS), '0.00'),
            );
            $customers[$customerKey]['orders']++;
            $customers[$customerKey]['total'] = Money::add($customers[$customerKey]['total'], $balance);
            $customers[$customerKey][$bucket] = Money::add($customers[$customerKey][$bucket], $balance);

            if (count($orders) < self::ORDER_LIST_LIMIT) {
                $orders[] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->customer_name,
                    'order_date' => $order->order_date?->toDateString(),
                    'status' => $order->status?->value,
                    'payment_status' => $order->payment_status?->value,
                    'currency' => $order->currency,
                    'total' => Money::of($order->total),
                    'amount_paid' => Money::of($order->amount_paid),
                    'balance_due' => $balance,
                    'age_days' => $age,
                    'bucket' => $bucket,
                ];
            }
        }

        $customerRows = array_values($customers);
        usort($customerRows, fn (array $a, array $b) => Money::compare($b['total'], $a['total']) ?: strcmp((string) $a['customer'], (string) $b['customer']));

        return [
            'summary' => [
                'total_outstanding' => $total,
                'order_count' => $count,
                'customer_count' => count($customerRows),
                'as_of' => $today->toDateString(),
                'orders_truncated' => $count > count($orders),
            ],
            'buckets' => array_map(
                fn (string $key) => ['key' => $key] + $buckets[$key],
                array_keys(self::BUCKETS),
            ),
            'customers' => $customerRows,
            'orders' => $orders,
        ];
    }

    private function bucketFor(int $ageDays): string
    {
        foreach (self::BUCKETS as $key => $upTo) {
            if ($ageDays <= $upTo) {
                return $key;
            }
        }

        return 'over_90';
    }
}
