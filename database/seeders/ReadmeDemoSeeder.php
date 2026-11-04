<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\SchedulerHealth;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A small, fictional company for the README demo GIFs (scripts/generate-demo.js).
 *
 * Every company, person and product here is made up. The flows the GIFs record
 * depend on a few fixed records: the low-stock laptop stand, the sent purchase
 * order that restocks it, and the customer the new order is written for.
 */
class ReadmeDemoSeeder extends Seeder
{
    public const EMAIL = 'maya@alderfinch.example';

    public const PASSWORD = 'ReadmeDemoPassword123!';

    public const ORG_NAME = 'Alder & Finch Supply';

    /** The sent purchase order the receiving demo opens. */
    public const RESTOCK_PO = 'PO-1048';

    public function run(): void
    {
        SystemSetting::set('installed', true, 'boolean', 'Installation completed');
        SystemSetting::set('installed_at', now()->toDateTimeString(), 'string', 'Installation date');
        SystemSetting::set('app_version', config('app.version', '0.1.0'), 'string', 'Application version');

        $this->call(RoleSeeder::class);

        $org = Organization::updateOrCreate(
            ['name' => self::ORG_NAME],
            [
                'email' => 'hello@alderfinch.example',
                'phone' => '555-0142',
                'address' => '18 Quarry Lane',
                'city' => 'Millbrook',
                'state' => 'OR',
                'zip' => '97000',
                'country' => 'United States',
                'currency' => 'USD',
            ]
        );
        $orgId = $org->id;

        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Maya Okafor',
                'password' => Hash::make(self::PASSWORD),
                'organization_id' => $orgId,
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole('system-administrator');

        $categories = collect(['Electronics', 'Office Supplies', 'Furniture', 'Packaging'])
            ->mapWithKeys(fn ($name) => [$name => ProductCategory::updateOrCreate(
                ['name' => $name, 'organization_id' => $orgId],
                ['slug' => str($name)->slug()->toString(), 'description' => "{$name} we stock", 'is_active' => true]
            )]);

        $locations = collect([
            'A1' => 'Aisle 1',
            'A2' => 'Aisle 2',
            'BULK' => 'Bulk Storage',
            'DOCK' => 'Receiving Dock',
        ])->mapWithKeys(fn ($name, $code) => [$code => ProductLocation::updateOrCreate(
            ['code' => $code, 'organization_id' => $orgId],
            ['name' => $name, 'description' => "{$name}, main warehouse", 'is_active' => true]
        )]);

        // [name, contact, code]
        $suppliers = collect([
            ['Brightline Components', 'Theo Marsh', 'SUP-BRT'],
            ['Kestrel Paper Mill', 'Ines Calloway', 'SUP-KPM'],
            ['Oakridge Workspace', 'Ruth Abernathy', 'SUP-OAK'],
            ['Tidewater Packaging', 'Sol Varga', 'SUP-TPK'],
        ])->mapWithKeys(fn ($s, $i) => [$s[2] => Supplier::updateOrCreate(
            ['code' => $s[2], 'organization_id' => $orgId],
            [
                'name' => $s[0],
                'contact_name' => $s[1],
                'email' => 'orders@' . str($s[0])->slug() . '.example',
                'phone' => '555-01' . (20 + $i),
                'city' => 'Millbrook',
                'state' => 'OR',
                'country' => 'United States',
                'is_active' => true,
            ]
        )]);

        // [name, sku, price, cost, stock, min, category, location]
        $catalogue = [
            ['Aluminium Laptop Stand', 'AF-1001', 59.00, 27.50, 4, 12, 'Electronics', 'A1'],
            ['USB-C Docking Station', 'AF-1002', 129.00, 68.00, 36, 10, 'Electronics', 'A1'],
            ['Wireless Keyboard', 'AF-1003', 74.00, 39.00, 58, 15, 'Electronics', 'A1'],
            ['27" Monitor Arm', 'AF-1004', 95.00, 44.00, 21, 8, 'Electronics', 'A2'],
            ['HD Webcam', 'AF-1005', 64.00, 31.00, 47, 12, 'Electronics', 'A2'],
            ['Ergonomic Mesh Chair', 'AF-2001', 349.00, 182.00, 18, 6, 'Furniture', 'BULK'],
            ['Sit-Stand Desk Frame', 'AF-2002', 419.00, 236.00, 11, 4, 'Furniture', 'BULK'],
            ['Under-Desk Drawer Unit', 'AF-2003', 139.00, 71.00, 26, 6, 'Furniture', 'BULK'],
            ['A4 Copy Paper, 5 reams', 'AF-3001', 32.00, 14.50, 240, 60, 'Office Supplies', 'A2'],
            ['Gel Pen Set, 12 pack', 'AF-3002', 11.50, 4.20, 310, 80, 'Office Supplies', 'A2'],
            ['Shipping Box, Large', 'AF-4001', 2.90, 0.95, 1800, 400, 'Packaging', 'BULK'],
            ['Packing Tape, 6 pack', 'AF-4002', 12.00, 4.60, 420, 100, 'Packaging', 'BULK'],
        ];

        $products = [];
        foreach ($catalogue as [$name, $sku, $price, $cost, $stock, $min, $cat, $loc]) {
            $products[$sku] = Product::updateOrCreate(
                ['sku' => $sku, 'organization_id' => $orgId],
                [
                    'name' => $name,
                    'description' => "{$name}, stocked at our Millbrook warehouse.",
                    'price' => $price,
                    'purchase_price' => $cost,
                    'currency' => 'USD',
                    'stock' => $stock,
                    'min_stock' => $min,
                    'max_stock' => $min * 10,
                    'is_active' => true,
                    'category_id' => $categories[$cat]->id,
                    'location_id' => $locations[$loc]->id,
                ]
            );
        }

        $customers = [];
        $i = 0;
        foreach ([
            'CUS-101' => ['Larkspur Dental Group', 'Priya Sandoval'],
            'CUS-102' => ['Bluepine Design Studio', 'Owen Achterberg'],
            'CUS-103' => ['Hollow Creek Library', 'Marta Lindqvist'],
            'CUS-104' => ['Saltmarsh Coworking', 'Desmond Ure'],
            'CUS-105' => ['Fernway Veterinary Clinic', 'Grace Mbeki'],
        ] as $code => [$company, $contact]) {
            $customers[] = Customer::updateOrCreate(
                ['code' => $code, 'organization_id' => $orgId],
                [
                    'name' => $company,
                    'company_name' => $company,
                    'contact_name' => $contact,
                    'email' => 'accounts@' . str($company)->slug() . '.example',
                    'phone' => '555-01' . (40 + $i),
                    'billing_address' => (120 + 37 * $i++) . ' Harbour Road',
                    'billing_city' => 'Millbrook',
                    'billing_state' => 'OR',
                    'billing_zip_code' => '97000',
                    'billing_country' => 'United States',
                    'is_active' => true,
                ]
            );
        }

        // Earlier orders, so the dashboard and order list have some history.
        $history = [
            ['SO-2031', 'delivered', 9, 'AF-2001', 4],
            ['SO-2032', 'delivered', 7, 'AF-1002', 6],
            ['SO-2033', 'shipped', 5, 'AF-3001', 20],
            ['SO-2034', 'shipped', 4, 'AF-1003', 8],
            ['SO-2035', 'processing', 2, 'AF-2002', 2],
            ['SO-2036', 'processing', 1, 'AF-1005', 5],
            ['SO-2037', 'pending', 0, 'AF-4001', 300],
        ];
        foreach ($history as $i => [$number, $status, $daysAgo, $sku, $qty]) {
            $customer = $customers[$i % count($customers)];
            $product = $products[$sku];
            $total = bcmul((string) $product->price, (string) $qty, 2);
            $order = Order::updateOrCreate(
                ['order_number' => $number, 'organization_id' => $orgId],
                [
                    'created_by' => $user->id,
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_email' => $customer->email,
                    'status' => $status,
                    'subtotal' => $total,
                    'tax' => 0,
                    'shipping' => 0,
                    'total' => $total,
                    'currency' => 'USD',
                    'order_date' => now()->subDays($daysAgo)->startOfDay(),
                ]
            );
            OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'product_id' => $product->id],
                ['product_name' => $product->name, 'sku' => $product->sku, 'quantity' => $qty, 'unit_price' => $product->price, 'unit_cost' => $product->purchase_price, 'subtotal' => $total, 'tax' => 0, 'total' => $total],
            );
        }

        // [number, supplier, status, daysAgo, [sku => qty]]
        $purchaseOrders = [
            ['PO-1045', 'SUP-OAK', 'received', 16, ['AF-2001' => 10, 'AF-2003' => 12]],
            ['PO-1046', 'SUP-KPM', 'received', 11, ['AF-3001' => 120]],
            ['PO-1047', 'SUP-TPK', 'draft', 3, ['AF-4002' => 200]],
            [self::RESTOCK_PO, 'SUP-BRT', 'sent', 6, ['AF-1001' => 40, 'AF-1005' => 20]],
        ];
        foreach ($purchaseOrders as [$number, $supplierCode, $status, $daysAgo, $lines]) {
            $subtotal = '0';
            foreach ($lines as $sku => $qty) {
                $subtotal = bcadd($subtotal, bcmul((string) $products[$sku]->purchase_price, (string) $qty, 2), 2);
            }
            $po = PurchaseOrder::updateOrCreate(
                ['po_number' => $number, 'organization_id' => $orgId],
                [
                    'supplier_id' => $suppliers[$supplierCode]->id,
                    'created_by' => $user->id,
                    'status' => $status,
                    'order_date' => now()->subDays($daysAgo)->toDateString(),
                    'expected_date' => now()->subDays($daysAgo)->addDays(7)->toDateString(),
                    'received_date' => $status === 'received' ? now()->subDays($daysAgo - 5)->toDateString() : null,
                    'sent_at' => $status === 'draft' ? null : now()->subDays($daysAgo),
                    'sent_to' => $status === 'draft' ? null : $suppliers[$supplierCode]->email,
                    'subtotal' => $subtotal,
                    'tax' => 0,
                    'shipping' => 0,
                    'total' => $subtotal,
                    'currency' => 'USD',
                ]
            );
            foreach ($lines as $sku => $qty) {
                $product = $products[$sku];
                $lineTotal = bcmul((string) $product->purchase_price, (string) $qty, 2);
                PurchaseOrderItem::updateOrCreate(
                    ['purchase_order_id' => $po->id, 'product_id' => $product->id],
                    ['product_name' => $product->name, 'sku' => $product->sku, 'quantity_ordered' => $qty, 'quantity_received' => $status === 'received' ? $qty : 0, 'unit_cost' => $product->purchase_price, 'subtotal' => $lineTotal, 'tax' => 0, 'total' => $lineTotal],
                );
            }
        }

        // The demo runs without a cron job; record a scheduler heartbeat so the
        // dashboard does not lead with the "background tasks" warning.
        app(SchedulerHealth::class)->beat();

        $this->command?->info('README demo data seeded. Log in as ' . self::EMAIL . ' / ' . self::PASSWORD);
    }
}
