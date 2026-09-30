<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderPayment;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\SavedReport;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Detail pages for the mobile layout e2e spec: an order, a purchase order, a
 * return and a saved report with every header action showing, long product
 * names, a warehouse (so the top-bar switcher renders) and a portal contact.
 *
 * Idempotent. Writes the record ids to e2e/.auth/layout-fixtures.json so the
 * spec can open the pages without guessing ids.
 */
class E2ELayoutSeeder extends Seeder
{
    public const PORTAL_EMAIL = 'e2e-portal@inventoros.test';

    public const PORTAL_PASSWORD = 'E2EPortalPassword123!';

    public function run(): void
    {
        $this->call(ScreenshotSeeder::class);

        $organization = Organization::where('name', E2ETestSeeder::TEST_ORG_NAME)->firstOrFail();
        $organization->forceFill(['portal_enabled' => true])->save();
        $orgId = $organization->id;
        $user = User::where('email', E2ETestSeeder::TEST_EMAIL)->firstOrFail();

        Warehouse::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'code' => 'WH-MAIN'],
            ['name' => 'Main Distribution Centre', 'is_default' => true, 'is_active' => true, 'currency' => 'USD', 'timezone' => 'UTC'],
        );

        $long = Product::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'sku' => 'E2E-LAYOUT-LONG'],
            [
                'name' => 'Industrial Heavy-Duty Stainless Steel Shelving Unit with Adjustable Brackets',
                'price' => 1249.99, 'purchase_price' => 710.00, 'currency' => 'USD',
                'stock' => 40, 'min_stock' => 5, 'is_active' => true,
            ],
        );
        $short = Product::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'sku' => 'E2E-LAYOUT-HUB'],
            [
                'name' => 'USB-C Hub Adapter 7-in-1',
                'price' => 49.99, 'purchase_price' => 22.50, 'currency' => 'USD',
                'stock' => 200, 'min_stock' => 10, 'is_active' => true,
            ],
        );

        $customer = Customer::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'code' => 'CUST-E2E-LAYOUT'],
            [
                'name' => 'Consolidated Northern Hospitality Supplies Ltd',
                'email' => 'orders@northern-hospitality.test',
                'currency' => 'USD', 'is_active' => true,
            ],
        );

        CustomerContact::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'email' => self::PORTAL_EMAIL],
            [
                'customer_id' => $customer->id,
                'name' => 'Portal Contact',
                'password' => Hash::make(self::PORTAL_PASSWORD),
                'invited_at' => now()->subDay(),
                'activated_at' => now()->subDay(),
            ],
        );

        $order = Order::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'order_number' => 'ORD-E2E-LAYOUT-0001'],
            [
                'customer_id' => $customer->id,
                'created_by' => $user->id,
                'source' => 'manual',
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'status' => OrderStatus::DELIVERED,
                'approval_status' => OrderApprovalStatus::PENDING,
                'subtotal' => 1349.97, 'tax' => 0, 'shipping' => 12.50, 'total' => 1362.47,
                'currency' => 'USD',
                'order_date' => now()->startOfDay(),
                'shipped_at' => now()->subHours(2),
                'delivered_at' => now()->subHour(),
            ],
        );

        $longLine = OrderItem::updateOrCreate(
            ['order_id' => $order->id, 'sku' => $long->sku],
            ['product_id' => $long->id, 'product_name' => $long->name, 'quantity' => 1, 'unit_price' => 1249.99, 'subtotal' => 1249.99, 'tax' => 0, 'total' => 1249.99],
        );
        OrderItem::updateOrCreate(
            ['order_id' => $order->id, 'sku' => $short->sku],
            ['product_id' => $short->id, 'product_name' => $short->name, 'quantity' => 2, 'unit_price' => 49.99, 'subtotal' => 99.98, 'tax' => 0, 'total' => 99.98],
        );

        OrderPayment::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'order_id' => $order->id, 'reference' => 'E2E-LAYOUT-PAY'],
            ['user_id' => $user->id, 'type' => 'payment', 'amount' => 100, 'method' => 'card', 'paid_at' => now()->startOfDay()],
        );
        $order->forceFill(['amount_paid' => 100, 'payment_status' => 'partial'])->save();

        $return = ReturnOrder::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'return_number' => 'RMA-E2E-LAYOUT-0001'],
            ['order_id' => $order->id, 'type' => 'return', 'status' => 'pending', 'reason' => 'Arrived with a bent bracket', 'refund_amount' => 1249.99],
        );
        ReturnOrderItem::updateOrCreate(
            ['return_order_id' => $return->id, 'order_item_id' => $longLine->id],
            ['product_id' => $long->id, 'quantity' => 1, 'condition' => 'damaged', 'restock' => false],
        );

        $supplier = Supplier::withoutGlobalScopes()->where('organization_id', $orgId)->firstOrFail();
        $po = PurchaseOrder::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'po_number' => 'PO-E2E-LAYOUT-0001'],
            [
                'supplier_id' => $supplier->id, 'created_by' => $user->id, 'status' => 'sent',
                'order_date' => now()->startOfDay(), 'subtotal' => 7100, 'total' => 7100, 'currency' => 'USD',
            ],
        );
        PurchaseOrderItem::updateOrCreate(
            ['purchase_order_id' => $po->id, 'sku' => $long->sku],
            ['product_id' => $long->id, 'product_name' => $long->name, 'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_cost' => 710, 'subtotal' => 7100, 'tax' => 0, 'total' => 7100],
        );

        $report = SavedReport::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => 'Stock on hand by product and category'],
            [
                'created_by' => $user->id, 'description' => 'Every active product with its stock level and price.',
                'data_source' => 'products', 'columns' => ['name', 'sku', 'stock', 'price'], 'is_shared' => true,
            ],
        );

        $fixtures = [
            'order' => $order->id,
            'order_date' => $order->order_date->toDateString(),
            'purchase_order' => $po->id,
            'return' => $return->id,
            'report' => $report->id,
            'portal_slug' => $organization->slug,
            'portal_email' => self::PORTAL_EMAIL,
            'portal_password' => self::PORTAL_PASSWORD,
        ];

        $dir = base_path('e2e/.auth');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/layout-fixtures.json', json_encode($fixtures, JSON_PRETTY_PRINT));

        $this->command?->info('Layout fixtures seeded: '.json_encode($fixtures));
    }
}
