<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [
                    'view_products', 'view_orders', 'view_customers',
                    'view_suppliers', 'view_purchase_orders',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->getJson('/search?q=test');

        $response->assertStatus(401);
    }

    public function test_search_returns_categorized_results(): void
    {
        // Create test data
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Widget Alpha',
            'sku' => 'WDG-001',
            'price' => 10.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-20260312-0001',
            'customer_name' => 'Alpha Customer',
            'status' => 'pending',
            'approval_status' => 'pending',
            'subtotal' => 100,
            'tax' => 0,
            'shipping' => 0,
            'total' => 100,
        ]);

        Customer::create([
            'organization_id' => $this->organization->id,
            'name' => 'Alpha Corp',
            'email' => 'alpha@example.com',
            'is_active' => true,
        ]);

        Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Alpha Supplies',
            'email' => 'supply@alpha.com',
            'is_active' => true,
        ]);

        PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => Supplier::first()->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-20260312-0001',
            'status' => 'draft',
            'subtotal' => 500,
            'tax' => 0,
            'shipping' => 0,
            'total' => 500,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=alpha');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'products' => [['id', 'title', 'subtitle', 'url', 'type', 'icon']],
                'orders' => [['id', 'title', 'subtitle', 'url', 'type', 'icon']],
                'customers' => [['id', 'title', 'subtitle', 'url', 'type', 'icon']],
                'suppliers' => [['id', 'title', 'subtitle', 'url', 'type', 'icon']],
                'purchase_orders',
            ]);

        $response->assertJsonCount(1, 'products');
        $response->assertJsonCount(1, 'orders');
        $response->assertJsonCount(1, 'customers');
        $response->assertJsonCount(1, 'suppliers');
    }

    public function test_search_respects_organization_scope(): void
    {
        // Product in our organization
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Our Widget',
            'sku' => 'OUR-001',
            'price' => 10.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        // Product in other organization (should NOT appear)
        Product::create([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Their Widget',
            'sku' => 'THEIR-001',
            'price' => 20.00,
            'stock' => 50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=widget');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'products');

        $this->assertEquals('Our Widget', $response->json('products.0.title'));
    }

    public function test_search_handles_empty_query(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=');

        $response->assertStatus(200)
            ->assertJson([
                'products' => [],
                'orders' => [],
                'customers' => [],
                'suppliers' => [],
                'purchase_orders' => [],
            ]);
    }

    public function test_search_handles_missing_query_parameter(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/search');

        $response->assertStatus(200)
            ->assertJson([
                'products' => [],
                'orders' => [],
                'customers' => [],
                'suppliers' => [],
                'purchase_orders' => [],
            ]);
    }

    public function test_search_limits_results_to_five_per_category(): void
    {
        // Create 7 products matching the query
        for ($i = 1; $i <= 7; $i++) {
            Product::create([
                'organization_id' => $this->organization->id,
                'name' => "Searchable Product {$i}",
                'sku' => "SRCH-{$i}",
                'price' => 10.00,
                'stock' => 100,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=searchable');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'products');
    }

    public function test_search_matches_product_by_sku(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Some Product',
            'sku' => 'UNIQUE-SKU-999',
            'price' => 10.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=UNIQUE-SKU');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'products');
    }

    public function test_search_matches_product_by_barcode(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Barcode Product',
            'sku' => 'BC-001',
            'barcode' => '1234567890123',
            'price' => 10.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=1234567890');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'products');
    }

    public function test_search_matches_customer_by_email(): void
    {
        Customer::create([
            'organization_id' => $this->organization->id,
            'name' => 'John Doe',
            'email' => 'unique-john@example.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=unique-john');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'customers');
    }

    public function test_search_matches_order_by_order_number(): void
    {
        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-SPECIAL-0001',
            'customer_name' => 'Test Customer',
            'status' => 'pending',
            'approval_status' => 'pending',
            'subtotal' => 100,
            'tax' => 0,
            'shipping' => 0,
            'total' => 100,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=ORD-SPECIAL');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'orders');
    }

    public function test_search_matches_supplier_by_email(): void
    {
        Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Some Supplier',
            'email' => 'unique-supplier@example.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=unique-supplier');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'suppliers');
    }

    public function test_search_matches_purchase_order_by_po_number(): void
    {
        $supplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'PO Supplier',
            'is_active' => true,
        ]);

        PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-SPECIAL-0001',
            'status' => 'draft',
            'subtotal' => 500,
            'tax' => 0,
            'shipping' => 0,
            'total' => 500,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=PO-SPECIAL');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'purchase_orders');
    }

    public function test_search_is_case_insensitive(): void
    {
        // The driver-aware Search helper must match regardless of case: LIKE on
        // sqlite/mysql, ILIKE on pgsql. A lower-case query must find a
        // mixed-case product name.
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'CaseSensitive Widget',
            'sku' => 'CASE-001',
            'price' => 10.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/search?q=casesensitive');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'products');
    }
    /**
     * One "Alpha" record of every kind global search returns.
     */
    protected function seedAlphaRecordsOfEveryKind(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Alpha Widget',
            'sku' => 'ALP-001',
            'price' => 10.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ALPHA-ORD-0001',
            'customer_name' => 'Alpha Customer',
            'status' => 'pending',
            'approval_status' => 'pending',
            'subtotal' => 100,
            'tax' => 0,
            'shipping' => 0,
            'total' => 100,
        ]);

        Customer::create([
            'organization_id' => $this->organization->id,
            'name' => 'Alpha Corp',
            'email' => 'alpha@example.com',
            'is_active' => true,
        ]);

        $supplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Alpha Supplies',
            'email' => 'supply@alpha.com',
            'is_active' => true,
        ]);

        PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'created_by' => $this->admin->id,
            'po_number' => 'ALPHA-PO-0001',
            'status' => 'draft',
            'subtotal' => 500,
            'tax' => 0,
            'shipping' => 0,
            'total' => 500,
            'order_date' => now(),
        ]);
    }

    protected function createMember(string $email): User
    {
        Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => ['view_products', 'view_orders'],
            ]
        );

        return User::create([
            'name' => 'Member User',
            'email' => $email,
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
    }

    public function test_search_hides_groups_the_user_may_not_view(): void
    {
        $this->seedAlphaRecordsOfEveryKind();
        $member = $this->createMember('member@test.com');

        $response = $this->actingAs($member)->getJson('/search?q=alpha');

        // The default Member role may view products and orders only.
        $response->assertStatus(200)
            ->assertJsonCount(1, 'products')
            ->assertJsonCount(1, 'orders')
            ->assertJsonCount(0, 'customers')
            ->assertJsonCount(0, 'suppliers')
            ->assertJsonCount(0, 'purchase_orders');
    }

    public function test_search_hides_products_and_orders_without_their_view_permissions(): void
    {
        $this->seedAlphaRecordsOfEveryKind();

        $role = Role::create([
            'name' => 'Customer Desk',
            'slug' => 'customer-desk',
            'organization_id' => $this->organization->id,
            'permissions' => ['view_customers'],
        ]);
        $user = User::create([
            'name' => 'Customer Desk User',
            'email' => 'desk@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            // No system-member row exists in this test, so the base role grants nothing.
            'role' => 'member',
        ]);
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user)->getJson('/search?q=alpha');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'products')
            ->assertJsonCount(0, 'orders')
            ->assertJsonCount(1, 'customers')
            ->assertJsonCount(0, 'suppliers')
            ->assertJsonCount(0, 'purchase_orders');
    }

    public function test_search_returns_groups_granted_through_a_custom_role(): void
    {
        $this->seedAlphaRecordsOfEveryKind();
        $member = $this->createMember('buyer@test.com');

        $role = Role::create([
            'name' => 'Buyer',
            'slug' => 'buyer',
            'organization_id' => $this->organization->id,
            'permissions' => ['view_customers', 'view_suppliers', 'view_purchase_orders'],
        ]);
        $member->roles()->attach($role->id);

        $response = $this->actingAs($member)->getJson('/search?q=alpha');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'products')
            ->assertJsonCount(1, 'orders')
            ->assertJsonCount(1, 'customers')
            ->assertJsonCount(1, 'suppliers')
            ->assertJsonCount(1, 'purchase_orders');
        $this->assertSame('Alpha Corp', $response->json('customers.0.title'));
    }

    public function test_search_with_permission_still_excludes_other_organizations(): void
    {
        Customer::create([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Alpha Foreign',
            'email' => 'foreign@example.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/search?q=alpha');

        $response->assertStatus(200)->assertJsonCount(0, 'customers');
    }
}
