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
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Seeder;

class ScreenshotSeeder extends Seeder
{
    /**
     * Seed the database with realistic demo data for screenshots.
     * Uses the E2E test user's organization so the authenticated user sees the data.
     */
    public function run(): void
    {
        // Create the E2E test user and organization first
        $this->call(E2ETestSeeder::class);

        // Get the E2E test organization
        $organization = Organization::where('name', E2ETestSeeder::TEST_ORG_NAME)->firstOrFail();
        $orgId = $organization->id;

        // Create categories
        $categories = collect([
            'Electronics', 'Office Supplies', 'Furniture', 'Raw Materials', 'Packaging',
        ])->map(fn ($name) => ProductCategory::updateOrCreate(
            ['name' => $name, 'organization_id' => $orgId],
            ['slug' => str($name)->slug()->toString(), 'description' => "All {$name} products", 'is_active' => true]
        ));

        // Create locations
        $locations = collect([
            ['name' => 'Warehouse A', 'code' => 'WH-A'],
            ['name' => 'Warehouse B', 'code' => 'WH-B'],
            ['name' => 'Cold Storage', 'code' => 'CS-1'],
            ['name' => 'Receiving Dock', 'code' => 'RD-1'],
        ])->map(fn ($loc) => ProductLocation::updateOrCreate(
            ['code' => $loc['code'], 'organization_id' => $orgId],
            ['name' => $loc['name'], 'description' => $loc['name'] . ' storage area', 'is_active' => true]
        ));

        // Create suppliers
        $suppliers = collect([
            ['name' => 'TechParts Global', 'code' => 'SUP-1001'],
            ['name' => 'Office Depot Direct', 'code' => 'SUP-1002'],
            ['name' => 'Steelcase Furniture', 'code' => 'SUP-1003'],
            ['name' => 'PackRight Solutions', 'code' => 'SUP-1004'],
        ])->map(fn ($s) => Supplier::updateOrCreate(
            ['code' => $s['code'], 'organization_id' => $orgId],
            [
                'name' => $s['name'],
                'contact_name' => fake()->name(),
                'email' => fake()->companyEmail(),
                'phone' => fake()->phoneNumber(),
                'address' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->stateAbbr(),
                'zip_code' => fake()->postcode(),
                'country' => 'United States',
                'is_active' => true,
            ]
        ));

        // Create products with realistic names
        $products = [
            ['name' => 'Wireless Bluetooth Keyboard', 'sku' => 'SKU-10001', 'price' => 79.99, 'purchase_price' => 42.00, 'stock' => 145, 'min_stock' => 20, 'cat' => 0],
            ['name' => 'USB-C Hub Adapter 7-in-1', 'sku' => 'SKU-10002', 'price' => 49.99, 'purchase_price' => 22.50, 'stock' => 230, 'min_stock' => 50, 'cat' => 0],
            ['name' => '27" 4K Monitor', 'sku' => 'SKU-10003', 'price' => 449.99, 'purchase_price' => 280.00, 'stock' => 38, 'min_stock' => 10, 'cat' => 0],
            ['name' => 'Ergonomic Mouse Pad', 'sku' => 'SKU-10004', 'price' => 24.99, 'purchase_price' => 8.50, 'stock' => 420, 'min_stock' => 100, 'cat' => 1],
            ['name' => 'Premium Ballpoint Pen Set', 'sku' => 'SKU-10005', 'price' => 18.99, 'purchase_price' => 6.00, 'stock' => 350, 'min_stock' => 80, 'cat' => 1],
            ['name' => 'A4 Copy Paper (500 sheets)', 'sku' => 'SKU-10006', 'price' => 12.99, 'purchase_price' => 5.50, 'stock' => 800, 'min_stock' => 200, 'cat' => 1],
            ['name' => 'Standing Desk Converter', 'sku' => 'SKU-10007', 'price' => 299.99, 'purchase_price' => 165.00, 'stock' => 22, 'min_stock' => 5, 'cat' => 2],
            ['name' => 'Mesh Office Chair', 'sku' => 'SKU-10008', 'price' => 389.99, 'purchase_price' => 210.00, 'stock' => 15, 'min_stock' => 5, 'cat' => 2],
            ['name' => 'Industrial Shelf Unit', 'sku' => 'SKU-10009', 'price' => 189.99, 'purchase_price' => 95.00, 'stock' => 42, 'min_stock' => 10, 'cat' => 2],
            ['name' => 'Corrugated Shipping Box (L)', 'sku' => 'SKU-10010', 'price' => 3.99, 'purchase_price' => 1.20, 'stock' => 2500, 'min_stock' => 500, 'cat' => 4],
            ['name' => 'Bubble Wrap Roll 30m', 'sku' => 'SKU-10011', 'price' => 14.99, 'purchase_price' => 6.00, 'stock' => 180, 'min_stock' => 40, 'cat' => 4],
            ['name' => 'Packing Tape (6-pack)', 'sku' => 'SKU-10012', 'price' => 9.99, 'purchase_price' => 3.80, 'stock' => 600, 'min_stock' => 150, 'cat' => 4],
            ['name' => 'Webcam HD 1080p', 'sku' => 'SKU-10013', 'price' => 69.99, 'purchase_price' => 35.00, 'stock' => 88, 'min_stock' => 15, 'cat' => 0],
            ['name' => 'Laptop Stand Aluminum', 'sku' => 'SKU-10014', 'price' => 59.99, 'purchase_price' => 28.00, 'stock' => 3, 'min_stock' => 10, 'cat' => 0],
            ['name' => 'Whiteboard 120x90cm', 'sku' => 'SKU-10015', 'price' => 89.99, 'purchase_price' => 45.00, 'stock' => 25, 'min_stock' => 8, 'cat' => 1],
        ];

        $productModels = [];
        foreach ($products as $p) {
            $productModels[] = Product::updateOrCreate(
                ['sku' => $p['sku'], 'organization_id' => $orgId],
                [
                    'name' => $p['name'],
                    'description' => fake()->sentence(10),
                    'price' => $p['price'],
                    'purchase_price' => $p['purchase_price'],
                    'currency' => 'USD',
                    'stock' => $p['stock'],
                    'min_stock' => $p['min_stock'],
                    'max_stock' => $p['min_stock'] * 20,
                    'is_active' => true,
                    'category_id' => $categories[$p['cat']]->id,
                    'location_id' => $locations->random()->id,
                ]
            );
        }

        // Create customers
        $customers = [];
        $customerNames = ['Globex Corporation', 'Initech LLC', 'Stark Industries', 'Wayne Enterprises', 'Umbrella Corp'];
        foreach ($customerNames as $i => $name) {
            $customers[] = Customer::updateOrCreate(
                ['code' => 'CUST-' . (2001 + $i), 'organization_id' => $orgId],
                [
                    'name' => $name,
                    'company_name' => $name,
                    'contact_name' => fake()->name(),
                    'email' => fake()->companyEmail(),
                    'phone' => fake()->phoneNumber(),
                    'billing_address' => fake()->streetAddress(),
                    'billing_city' => fake()->city(),
                    'billing_state' => fake()->stateAbbr(),
                    'billing_zip_code' => fake()->postcode(),
                    'billing_country' => 'United States',
                    'is_active' => true,
                ]
            );
        }

        // Get the E2E test user for created_by fields
        $user = User::where('email', E2ETestSeeder::TEST_EMAIL)->firstOrFail();

        // Create orders with varied statuses
        $orderStatuses = ['pending', 'processing', 'shipped', 'delivered', 'pending', 'processing', 'shipped', 'delivered'];
        foreach ($orderStatuses as $i => $status) {
            $customer = $customers[array_rand($customers)];
            $total = fake()->randomFloat(2, 100, 5000);
            Order::updateOrCreate(
                ['order_number' => 'ORD-' . (50001 + $i), 'organization_id' => $orgId],
                [
                    'created_by' => $user->id,
                    'customer_name' => $customer->name,
                    'customer_email' => $customer->email,
                    'status' => $status,
                    'subtotal' => $total,
                    'total' => $total,
                    'currency' => 'USD',
                ]
            );
        }

        // Create purchase orders
        $poStatuses = ['draft', 'sent', 'received', 'draft', 'sent', 'received'];
        foreach ($poStatuses as $i => $status) {
            $total = fake()->randomFloat(2, 200, 8000);
            PurchaseOrder::updateOrCreate(
                ['po_number' => 'PO-' . (60001 + $i), 'organization_id' => $orgId],
                [
                    'supplier_id' => $suppliers->random()->id,
                    'created_by' => $user->id,
                    'status' => $status,
                    'order_date' => now()->subDays(rand(1, 30)),
                    'subtotal' => $total,
                    'total' => $total,
                    'currency' => 'USD',
                ]
            );
        }

        $this->command->info('Screenshot demo data seeded successfully.');
    }
}
