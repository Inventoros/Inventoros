<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Auth\Organization;
use App\Models\Customer;
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
 * reconcile to the cent with the total. Each currency is reported on its
 * own; balances in different currencies are never added together.
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
     * The aging report for one currency, plus the outstanding total of every
     * currency that has a balance.
     *
     * Amounts in different currencies are never added together: the
     * summary, buckets, customers and orders cover only $currency (default:
     * the organization's currency), and `currencies` lists each currency's
     * own total, the organization's currency first.
     *
     * @return array{currency: string, currencies: array<int, array{currency: string, total_outstanding: string, order_count: int}>, summary: array<string, mixed>, buckets: array<int, array<string, mixed>>, customers: array<int, array<string, mixed>>, orders: array<int, array<string, mixed>>}
     */
    public function build(int $organizationId, ?Carbon $asOf = null, ?string $currency = null): array
    {
        $today = ($asOf ?? now())->copy()->startOfDay();
        $base = Organization::currencyFor($organizationId);
        $currency = filled($currency) ? strtoupper(trim($currency)) : $base;
        $totals = [];

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

        // An order typed with a free-text customer name that exactly matches
        // one customer record (ignoring case and spacing) is that customer's
        // debt: group it with their linked orders instead of listing the same
        // customer twice. A name shared by several customers is left alone.
        $customerNames = [];
        $ambiguous = [];
        Customer::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->each(function (Customer $customer) use (&$customerNames, &$ambiguous) {
                $key = self::normaliseName($customer->name);
                if ($key === '') {
                    return;
                }
                if (isset($customerNames[$key])) {
                    $ambiguous[$key] = true;
                }
                $customerNames[$key] ??= ['id' => $customer->id, 'name' => $customer->name];
            });
        $byId = [];
        foreach ($customerNames as $entry) {
            $byId[$entry['id']] = $entry['name'];
        }

        foreach ($query->cursor() as $order) {
            $balance = Money::subtract($order->total, $order->amount_paid);
            $orderCurrency = filled($order->currency) ? strtoupper(trim((string) $order->currency)) : $base;

            $totals[$orderCurrency] ??= ['currency' => $orderCurrency, 'total_outstanding' => '0.00', 'order_count' => 0];
            $totals[$orderCurrency]['total_outstanding'] = Money::add($totals[$orderCurrency]['total_outstanding'], $balance);
            $totals[$orderCurrency]['order_count']++;

            if ($orderCurrency !== $currency) {
                continue;
            }

            $age = $order->order_date
                ? max(0, (int) $order->order_date->copy()->startOfDay()->diffInDays($today, false))
                : 0;
            $bucket = $this->bucketFor($age);

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] = Money::add($buckets[$bucket]['amount'], $balance);
            $total = Money::add($total, $balance);
            $count++;

            $nameKey = self::normaliseName($order->customer_name);
            $customerId = $order->customer_id;
            if ($customerId === null && isset($customerNames[$nameKey]) && ! isset($ambiguous[$nameKey])) {
                $customerId = $customerNames[$nameKey]['id'];
            }
            $customerKey = $customerId !== null ? 'id:'.$customerId : 'name:'.$nameKey;
            $customers[$customerKey] ??= array_merge(
                [
                    'customer' => ($customerId !== null ? ($byId[$customerId] ?? null) : null) ?: ($order->customer_name ?: 'Unknown customer'),
                    'customer_id' => $customerId,
                    'orders' => 0,
                    'total' => '0.00',
                ],
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
                    'currency' => $orderCurrency,
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

        // The organization's currency first, then the rest alphabetically.
        uksort($totals, fn (string $a, string $b) => [$a !== $base, $a] <=> [$b !== $base, $b]);

        return [
            'currency' => $currency,
            'currencies' => array_values($totals),
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

    /**
     * Case- and whitespace-insensitive key for a customer name.
     */
    private static function normaliseName(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
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
