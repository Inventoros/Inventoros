<?php

namespace Tests\Feature;

use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use Database\Seeders\ScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenshotFixtureConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_totals_have_real_lines_and_linked_customers(): void
    {
        $this->seed(ScreenshotSeeder::class);
        $orders = Order::withoutGlobalScopes()->whereBetween('order_number', ['ORD-50001', 'ORD-50008'])->with('items')->get();
        $this->assertCount(8, $orders);
        $revenue = '0.00';
        foreach ($orders as $order) {
            $this->assertNotNull($order->customer_id);
            $this->assertCount(1, $order->items);
            $this->assertSame((string) $order->total, (string) $order->items->first()->total);
            $revenue = bcadd($revenue, (string) $order->total, 2);
        }
        $this->assertSame('2711.85', $revenue);
        $baseOrder = Order::withoutGlobalScopes()->where('order_number', \Database\Seeders\E2ETestSeeder::STAFF_ORDER_NUMBER)->firstOrFail();
        $this->assertCount(1, $baseOrder->items);
        $this->assertSame((string) $baseOrder->total, (string) $baseOrder->items->first()->total);
        $purchases = PurchaseOrder::withoutGlobalScopes()->whereBetween('po_number', ['PO-60001', 'PO-60006'])->with('items')->get();
        $this->assertCount(6, $purchases);
        $total = '0.00';
        foreach ($purchases as $purchase) {
            $this->assertCount(1, $purchase->items);
            $this->assertSame((string) $purchase->total, (string) $purchase->items->first()->total);
            $total = bcadd($total, (string) $purchase->total, 2);
        }
        $this->assertSame('10240.00', $total);
    }
}
