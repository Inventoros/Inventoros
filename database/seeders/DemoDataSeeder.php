<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\SavedReport;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed the database with demo data for warehouses, kits, assemblies, work orders, and reports.
     *
     * Requires the ScreenshotSeeder (or equivalent) to have run first so that
     * the E2E test organization, user, products, and locations already exist.
     */
    public function run(): void
    {
        // Ensure base demo data exists
        $this->call(ScreenshotSeeder::class);

        $organization = Organization::where('name', E2ETestSeeder::TEST_ORG_NAME)->firstOrFail();
        $orgId = $organization->id;
        $user = User::where('email', E2ETestSeeder::TEST_EMAIL)->firstOrFail();

        // ────────────────────────────────────────────────
        // 1. Warehouses
        // ────────────────────────────────────────────────
        $torontoWarehouse = Warehouse::updateOrCreate(
            ['code' => 'WH-TOR', 'organization_id' => $orgId],
            [
                'name' => 'Toronto Distribution Center',
                'description' => 'Primary distribution center for Eastern Canada',
                'address_line_1' => '100 Warehouse Blvd',
                'city' => 'Toronto',
                'province' => 'ON',
                'postal_code' => 'M5V 2T6',
                'country' => 'Canada',
                'phone' => '416-555-0100',
                'email' => 'toronto@inventoros.test',
                'manager_name' => 'Sarah Chen',
                'timezone' => 'America/Toronto',
                'currency' => 'CAD',
                'is_default' => true,
                'is_active' => true,
                'priority' => 10,
            ]
        );

        $vancouverWarehouse = Warehouse::updateOrCreate(
            ['code' => 'WH-VAN', 'organization_id' => $orgId],
            [
                'name' => 'Vancouver Warehouse',
                'description' => 'West coast fulfillment center',
                'address_line_1' => '500 Pacific Gateway',
                'city' => 'Vancouver',
                'province' => 'BC',
                'postal_code' => 'V6B 1A1',
                'country' => 'Canada',
                'phone' => '604-555-0200',
                'email' => 'vancouver@inventoros.test',
                'manager_name' => 'Mike Patel',
                'timezone' => 'America/Vancouver',
                'currency' => 'CAD',
                'is_default' => false,
                'is_active' => true,
                'priority' => 5,
            ]
        );

        $montrealWarehouse = Warehouse::updateOrCreate(
            ['code' => 'WH-MTL', 'organization_id' => $orgId],
            [
                'name' => 'Montreal Fulfillment',
                'description' => 'Quebec regional fulfillment center',
                'address_line_1' => '250 Rue du Commerce',
                'city' => 'Montreal',
                'province' => 'QC',
                'postal_code' => 'H2X 1Y4',
                'country' => 'Canada',
                'phone' => '514-555-0300',
                'email' => 'montreal@inventoros.test',
                'manager_name' => 'Jean-Luc Tremblay',
                'timezone' => 'America/Toronto',
                'currency' => 'CAD',
                'is_default' => false,
                'is_active' => true,
                'priority' => 3,
            ]
        );

        // Assign existing locations to the Toronto warehouse
        ProductLocation::where('organization_id', $orgId)
            ->whereNull('warehouse_id')
            ->update(['warehouse_id' => $torontoWarehouse->id]);

        // ────────────────────────────────────────────────
        // 2. Kit & Assembly Products
        // ────────────────────────────────────────────────
        // Grab some existing products for use as components
        $existingProducts = Product::where('organization_id', $orgId)
            ->whereIn('sku', [
                'SKU-10001', // Wireless Bluetooth Keyboard
                'SKU-10002', // USB-C Hub Adapter
                'SKU-10004', // Ergonomic Mouse Pad
                'SKU-10013', // Webcam HD 1080p
                'SKU-10014', // Laptop Stand Aluminum
                'SKU-10005', // Premium Ballpoint Pen Set
                'SKU-10006', // A4 Copy Paper
            ])
            ->get()
            ->keyBy('sku');

        // --- Starter Kit ---
        $starterKit = Product::updateOrCreate(
            ['sku' => 'KIT-20001', 'organization_id' => $orgId],
            [
                'name' => 'Starter Kit',
                'description' => 'Everything you need to get started — keyboard, hub, and mouse pad bundled together.',
                'type' => 'kit',
                'price' => 139.99,
                'purchase_price' => 73.00,
                'stock' => 50,
                'min_stock' => 10,
                'max_stock' => 200,
                'is_active' => true,
                'category_id' => $existingProducts['SKU-10001']->category_id ?? null,
                'location_id' => $existingProducts['SKU-10001']->location_id ?? null,
            ]
        );

        $this->createComponents($starterKit, [
            ['sku' => 'SKU-10001', 'qty' => 1], // Keyboard
            ['sku' => 'SKU-10002', 'qty' => 1], // USB-C Hub
            ['sku' => 'SKU-10004', 'qty' => 1], // Mouse Pad
        ], $existingProducts);

        // --- Premium Bundle ---
        $premiumBundle = Product::updateOrCreate(
            ['sku' => 'KIT-20002', 'organization_id' => $orgId],
            [
                'name' => 'Premium Bundle',
                'description' => 'Premium home office bundle with webcam, laptop stand, keyboard, and hub adapter.',
                'type' => 'kit',
                'price' => 249.99,
                'purchase_price' => 127.50,
                'stock' => 25,
                'min_stock' => 5,
                'max_stock' => 100,
                'is_active' => true,
                'category_id' => $existingProducts['SKU-10001']->category_id ?? null,
                'location_id' => $existingProducts['SKU-10001']->location_id ?? null,
            ]
        );

        $this->createComponents($premiumBundle, [
            ['sku' => 'SKU-10001', 'qty' => 1], // Keyboard
            ['sku' => 'SKU-10002', 'qty' => 1], // USB-C Hub
            ['sku' => 'SKU-10013', 'qty' => 1], // Webcam
            ['sku' => 'SKU-10014', 'qty' => 1], // Laptop Stand
        ], $existingProducts);

        // --- Custom Assembly ---
        $customAssembly = Product::updateOrCreate(
            ['sku' => 'ASM-30001', 'organization_id' => $orgId],
            [
                'name' => 'Custom Assembly',
                'description' => 'Custom-assembled workstation accessory pack — keyboard, hub, and webcam pre-configured.',
                'type' => 'assembly',
                'price' => 189.99,
                'purchase_price' => 99.50,
                'stock' => 10,
                'min_stock' => 3,
                'max_stock' => 50,
                'is_active' => true,
                'category_id' => $existingProducts['SKU-10001']->category_id ?? null,
                'location_id' => $existingProducts['SKU-10001']->location_id ?? null,
            ]
        );

        $this->createComponents($customAssembly, [
            ['sku' => 'SKU-10001', 'qty' => 1], // Keyboard
            ['sku' => 'SKU-10002', 'qty' => 2], // USB-C Hub x2
            ['sku' => 'SKU-10013', 'qty' => 1], // Webcam
        ], $existingProducts);

        // ────────────────────────────────────────────────
        // 3. Work Orders
        // ────────────────────────────────────────────────
        // Completed work order
        WorkOrder::updateOrCreate(
            ['work_order_number' => 'WO-20260301-0001', 'organization_id' => $orgId],
            [
                'product_id' => $customAssembly->id,
                'created_by' => $user->id,
                'warehouse_id' => $torontoWarehouse->id,
                'quantity' => 10,
                'quantity_produced' => 10,
                'status' => 'completed',
                'started_at' => now()->subDays(5),
                'completed_at' => now()->subDays(2),
                'notes' => 'Initial production run completed on schedule.',
            ]
        );

        // Add work order items for the completed order
        $completedWo = WorkOrder::where('work_order_number', 'WO-20260301-0001')
            ->where('organization_id', $orgId)
            ->first();

        if ($completedWo) {
            $this->createWorkOrderItems($completedWo, $customAssembly, $existingProducts, true);
        }

        // Pending work order
        WorkOrder::updateOrCreate(
            ['work_order_number' => 'WO-20260330-0001', 'organization_id' => $orgId],
            [
                'product_id' => $customAssembly->id,
                'created_by' => $user->id,
                'warehouse_id' => $torontoWarehouse->id,
                'quantity' => 5,
                'quantity_produced' => 0,
                'status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'notes' => 'Awaiting component stock replenishment before starting.',
            ]
        );

        $pendingWo = WorkOrder::where('work_order_number', 'WO-20260330-0001')
            ->where('organization_id', $orgId)
            ->first();

        if ($pendingWo) {
            $this->createWorkOrderItems($pendingWo, $customAssembly, $existingProducts, false);
        }

        // ────────────────────────────────────────────────
        // 4. Saved Reports
        // ────────────────────────────────────────────────
        SavedReport::updateOrCreate(
            ['name' => 'Monthly Product Summary', 'organization_id' => $orgId],
            [
                'created_by' => $user->id,
                'description' => 'Overview of all products with stock levels and pricing.',
                'data_source' => 'products',
                'columns' => ['name', 'sku', 'stock', 'price', 'category', 'is_active'],
                'filters' => null,
                'sort' => ['field' => 'name', 'direction' => 'asc'],
                'chart_type' => 'bar',
                'chart_field' => 'stock',
                'is_shared' => true,
            ]
        );

        SavedReport::updateOrCreate(
            ['name' => 'Order Status Report', 'organization_id' => $orgId],
            [
                'created_by' => $user->id,
                'description' => 'All orders grouped by status for quick review.',
                'data_source' => 'orders',
                'columns' => ['order_number', 'customer_name', 'status', 'total', 'created_at'],
                'filters' => null,
                'sort' => ['field' => 'created_at', 'direction' => 'desc'],
                'chart_type' => 'pie',
                'chart_field' => 'status',
                'is_shared' => true,
            ]
        );

        SavedReport::updateOrCreate(
            ['name' => 'Low Stock Alert', 'organization_id' => $orgId],
            [
                'created_by' => $user->id,
                'description' => 'Products with stock below 10 units that need reordering.',
                'data_source' => 'products',
                'columns' => ['name', 'sku', 'stock', 'min_stock', 'category'],
                'filters' => [
                    ['field' => 'stock', 'operator' => 'lt', 'value' => '10'],
                ],
                'sort' => ['field' => 'stock', 'direction' => 'asc'],
                'chart_type' => null,
                'chart_field' => null,
                'is_shared' => true,
            ]
        );

        $this->command->info('Demo data seeded successfully (warehouses, kits, assemblies, work orders, reports).');
    }

    /**
     * Create product component entries for a kit or assembly product.
     */
    private function createComponents(Product $parent, array $components, $existingProducts): void
    {
        foreach ($components as $i => $comp) {
            $componentProduct = $existingProducts[$comp['sku']] ?? null;
            if (! $componentProduct) {
                continue;
            }

            ProductComponent::updateOrCreate(
                [
                    'parent_product_id' => $parent->id,
                    'component_product_id' => $componentProduct->id,
                ],
                [
                    'quantity' => $comp['qty'],
                    'sort_order' => $i + 1,
                    'notes' => null,
                ]
            );
        }
    }

    /**
     * Create work order items mirroring the assembly's BOM.
     */
    private function createWorkOrderItems(WorkOrder $workOrder, Product $assembly, $existingProducts, bool $consumed): void
    {
        $components = ProductComponent::where('parent_product_id', $assembly->id)->get();

        foreach ($components as $comp) {
            $qtyRequired = $comp->quantity * $workOrder->quantity;

            WorkOrderItem::updateOrCreate(
                [
                    'work_order_id' => $workOrder->id,
                    'product_id' => $comp->component_product_id,
                ],
                [
                    'quantity_required' => $qtyRequired,
                    'quantity_consumed' => $consumed ? $qtyRequired : 0,
                ]
            );
        }
    }
}
