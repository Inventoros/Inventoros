<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Run after ScreenshotBrandingSeeder, only for the README screenshots.
 *
 * Gives the demo company a recent past: a month of stock received and sold
 * (the dashboard's stock movement chart) and last month's orders (the revenue
 * comparison). ScreenshotSeeder creates everything "now", so without this
 * those two dashboard panels would be empty. The E2E suite never runs it.
 */
class ScreenshotHistorySeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('name', ScreenshotBrandingSeeder::DEMO_COMPANY)->firstOrFail();
        $orgId = $organization->id;
        $user = User::where('email', ScreenshotBrandingSeeder::SCREENSHOT_EMAIL)->firstOrFail();
        $products = Product::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->orderBy('id')->get();
        $customers = Customer::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->get();

        if ($products->isEmpty()) {
            return;
        }

        // Re-running it (to retake screenshots) replaces the history rather
        // than doubling it; orders below are keyed by number already.
        StockAdjustment::where('organization_id', $orgId)->where('reason', 'Screenshot history')->delete();

        // A deliveries-and-sales rhythm: a supplier delivery about once a
        // week, and a few sales on most days, quieter at weekends.
        for ($daysAgo = 29; $daysAgo >= 1; $daysAgo--) {
            $day = now()->subDays($daysAgo);
            $weekend = $day->isWeekend();

            if ($daysAgo % 7 === 3) {
                foreach ($products->slice(($daysAgo % 4) * 3, 3) as $product) {
                    $this->ledger($orgId, $user->id, $product, 12 + ($daysAgo + $product->id) % 14, 'purchase_order_receipt', $day->copy()->setTime(9, 30));
                }
            }

            $sales = $weekend ? 1 : 3 + ($daysAgo % 3);
            for ($sale = 0; $sale < $sales; $sale++) {
                $product = $products[($daysAgo * 3 + $sale * 5) % $products->count()];
                $this->ledger($orgId, $user->id, $product, -(2 + ($daysAgo * 7 + $sale * 3) % 7), 'order_fulfillment', $day->copy()->setTime(11 + $sale, 15));
            }
        }

        // Last month's orders, spread over the days this month has had so far.
        // Sized to a little under this month's sales so the comparison reads
        // as an ordinary month, and recorded as paid and entered on the day,
        // so they neither crowd "Recent orders" nor inflate receivables.
        $lastMonth = now()->startOfMonth()->subMonthNoOverflow();
        $span = min(now()->day, $lastMonth->daysInMonth);
        $thisMonth = (float) Order::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('status', '!=', 'cancelled')->where('order_date', '>=', now()->startOfMonth())->sum('total');
        $shares = [0.3, 0.1, 0.15, 0.25, 0.05, 0.15];
        foreach ($shares as $i => $share) {
            $product = $products[($i * 4) % $products->count()];
            $customer = $customers->isEmpty() ? null : $customers[$i % $customers->count()];
            $price = max(0.01, (float) $product->price);
            $quantity = max(1, (int) round($thisMonth * 0.85 * $share / $price));
            $total = bcmul((string) $product->price, (string) $quantity, 2);
            $orderDate = $lastMonth->copy()->addDays((int) floor($i * ($span - 1) / 5))->startOfDay();
            $order = Order::updateOrCreate(
                ['order_number' => 'ORD-'.(49001 + $i), 'organization_id' => $orgId],
                [
                    'created_by' => $user->id,
                    'customer_id' => $customer?->id,
                    'customer_name' => $customer?->name ?? 'Walk-in customer',
                    'customer_email' => $customer?->email,
                    'status' => 'delivered',
                    'payment_status' => 'paid',
                    'amount_paid' => $total,
                    'subtotal' => $total,
                    'tax' => 0,
                    'shipping' => 0,
                    'total' => $total,
                    'currency' => 'USD',
                    'order_date' => $orderDate,
                ]
            );
            $order->forceFill(['created_at' => $orderDate->copy()->setTime(10, 0), 'updated_at' => $orderDate->copy()->setTime(10, 0)])->saveQuietly();
            OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'product_id' => $product->id],
                ['product_name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price' => $product->price, 'unit_cost' => $product->purchase_price, 'subtotal' => $total, 'tax' => 0, 'total' => $total],
            );
        }

        $this->command?->info('Screenshot history added: 30 days of stock movement and last month\'s orders.');
    }

    private function ledger(int $orgId, int $userId, Product $product, int $quantity, string $type, \DateTimeInterface $at): void
    {
        $row = StockAdjustment::create([
            'organization_id' => $orgId,
            'product_id' => $product->id,
            'user_id' => $userId,
            'type' => $type,
            'quantity_before' => (int) $product->stock,
            'quantity_after' => (int) $product->stock + $quantity,
            'adjustment_quantity' => $quantity,
            'reason' => 'Screenshot history',
        ]);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }
}
