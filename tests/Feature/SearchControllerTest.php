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
}
