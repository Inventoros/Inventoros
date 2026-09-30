<?php

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\PermissionSet;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class E2ETestSeeder extends Seeder
{
    /**
     * E2E Test User Credentials
     * These are used by Playwright tests for authentication
     */
    public const TEST_EMAIL = 'e2e-test@inventoros.test';
    public const TEST_PASSWORD = 'E2ETestPassword123!';
    public const TEST_NAME = 'E2E Test User';
    public const TEST_ORG_NAME = 'E2E Test Organization';
    public const TEST_PRODUCT_SKU = 'E2E-PRODUCT-1';

    /**
     * A member holding only the Warehouse Staff permission set, for specs
     * that check restricted users are not shown controls that 403.
     */
    public const STAFF_EMAIL = 'e2e-staff@inventoros.test';
    public const STAFF_PASSWORD = 'E2EStaffPassword123!';
    public const STAFF_ORDER_NUMBER = 'E2E-ORDER-1';
    public const STAFF_PO_NUMBER = 'E2E-PO-1';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Run role seeder first to ensure system roles exist
        $this->call(RoleSeeder::class);

        // Create or update test organization
        $organization = Organization::updateOrCreate(
            ['name' => self::TEST_ORG_NAME],
            [
                'address' => '123 Test Street',
                'city' => 'Test City',
                'state' => 'TS',
                'zip' => '12345',
                'country' => 'Test Country',
                'phone' => '555-0100',
                'email' => 'org@inventoros.test',
            ]
        );

        // Create or update test user with admin role
        $user = User::updateOrCreate(
            ['email' => self::TEST_EMAIL],
            [
                'name' => self::TEST_NAME,
                'password' => Hash::make(self::TEST_PASSWORD),
                'organization_id' => $organization->id,
                'role' => 'admin',
                'email_verified_at' => now(),
                'notification_preferences' => [
                    'email_notifications' => true,
                    'low_stock_alerts' => true,
                    'order_updates' => true,
                ],
            ]
        );

        // Assign system administrator role
        $user->assignRole('system-administrator');

        // A known product for specs that open a product detail page.
        Product::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organization->id, 'sku' => self::TEST_PRODUCT_SKU],
            [
                'name' => 'E2E Test Product',
                'price' => 10,
                'currency' => 'USD',
                'stock' => 5,
                'min_stock' => 0,
                'is_active' => true,
            ]
        );

        $this->seedWarehouseStaff($organization, $user);

        $this->command->info('E2E test user created successfully.');
        $this->command->info('Email: ' . self::TEST_EMAIL);
        $this->command->info('Password: ' . self::TEST_PASSWORD);
    }

    /**
     * The Warehouse Staff user, and an order, a supplier-linked product and a
     * purchase order for them to look at.
     */
    private function seedWarehouseStaff(Organization $organization, User $admin): void
    {
        $permissions = collect(PermissionSet::getDefaultTemplates())
            ->firstWhere('slug', 'warehouse-staff')['permissions'];

        $role = Role::updateOrCreate(
            ['slug' => 'e2e-warehouse-staff'],
            [
                'name' => 'E2E Warehouse Staff',
                'organization_id' => $organization->id,
                'permissions' => $permissions,
                'is_system' => false,
            ]
        );

        $staff = User::updateOrCreate(
            ['email' => self::STAFF_EMAIL],
            [
                'name' => 'E2E Warehouse Staff',
                'password' => Hash::make(self::STAFF_PASSWORD),
                'organization_id' => $organization->id,
                'role' => 'member',
                'email_verified_at' => now(),
            ]
        );
        $staff->roles()->sync([$role->id]);

        $product = Product::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('sku', self::TEST_PRODUCT_SKU)
            ->firstOrFail();

        $supplier = Supplier::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organization->id, 'code' => 'E2E-SUP'],
            ['name' => 'E2E Supplier', 'email' => 'supplier@inventoros.test', 'is_active' => true]
        );
        $product->suppliers()->syncWithoutDetaching([$supplier->id => ['is_primary' => true]]);

        Order::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organization->id, 'order_number' => self::STAFF_ORDER_NUMBER],
            [
                'source' => 'manual',
                'customer_name' => 'E2E Customer',
                'status' => 'pending',
                'order_date' => now(),
                'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10,
                'currency' => 'USD',
            ]
        );

        $po = PurchaseOrder::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organization->id, 'po_number' => self::STAFF_PO_NUMBER],
            [
                'supplier_id' => $supplier->id,
                'created_by' => $admin->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => now()->toDateString(),
                'subtotal' => 5, 'tax' => 0, 'total' => 5,
                'currency' => 'USD',
            ]
        );
        if (! $po->items()->exists()) {
            $po->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity_ordered' => 1,
                'quantity_received' => 0,
                'unit_cost' => 5, 'subtotal' => 5, 'tax' => 0, 'total' => 5,
            ]);
        }
    }

    /**
     * Clean up E2E test data.
     * Can be called after tests to remove test data.
     */
    public static function cleanup(): void
    {
        User::where('email', self::TEST_EMAIL)->delete();
        User::where('email', self::STAFF_EMAIL)->delete();
        Organization::where('name', self::TEST_ORG_NAME)->delete();
    }
}
