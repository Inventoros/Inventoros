<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckReorderPointsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected Organization $otherOrganization;
    protected User $admin;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@organization.com',
            'phone' => '987-654-3210',
            'address' => '456 Other St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => ['view_products', 'create_products', 'edit_products', 'delete_products'],
            ]
        );

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->supplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Supplier',
            'code' => 'SUP-0001',
            'email' => 'supplier@test.com',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_creates_purchase_orders_when_stock_is_below_reorder_point(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Low Stock Product',
            'sku' => 'LSP-001',
            'price' => 10.00,
            'stock' => 5,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        // Attach supplier as primary
        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Should have created a PO
        $po = PurchaseOrder::where('organization_id', $this->organization->id)
            ->where('supplier_id', $this->supplier->id)
            ->where('status', 'draft')
            ->first();

        $this->assertNotNull($po);
        $this->assertStringContainsString('Auto-generated reorder', $po->notes);

        // Should have the product as an item
        $item = $po->items()->where('product_id', $product->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals(50, $item->quantity_ordered);
    }

    /** @test */
    public function it_skips_products_with_existing_pending_purchase_orders(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Already Ordered Product',
            'sku' => 'AOP-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        // Create an existing draft PO with this product
        $existingPO = PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => PurchaseOrder::generatePONumber($this->organization->id),
            'status' => 'draft',
            'subtotal' => 250.00,
            'tax' => 0,
            'shipping' => 0,
            'total' => 250.00,
            'order_date' => now(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $existingPO->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 5.00,
            'subtotal' => 250.00,
            'tax' => 0,
            'total' => 250.00,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Should NOT have created a new PO
        $poCount = PurchaseOrder::where('organization_id', $this->organization->id)->count();
        $this->assertEquals(1, $poCount);
    }

    /** @test */
    public function it_also_skips_products_with_sent_purchase_orders(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Sent Order Product',
            'sku' => 'SOP-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        // Create an existing sent PO with this product
        $existingPO = PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => PurchaseOrder::generatePONumber($this->organization->id),
            'status' => 'sent',
            'subtotal' => 250.00,
            'tax' => 0,
            'shipping' => 0,
            'total' => 250.00,
            'order_date' => now(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $existingPO->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 5.00,
            'subtotal' => 250.00,
            'tax' => 0,
            'total' => 250.00,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Should NOT have created a new PO
        $poCount = PurchaseOrder::where('organization_id', $this->organization->id)->count();
        $this->assertEquals(1, $poCount);
    }

    /** @test */
    public function it_respects_organization_scoping(): void
    {
        // Product in org 1 - needs reorder
        $product1 = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Org1 Product',
            'sku' => 'O1P-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product1->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        // Product in org 2 - needs reorder
        $otherSupplier = Supplier::create([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Other Supplier',
            'code' => 'SUP-0002',
            'email' => 'other-supplier@test.com',
            'is_active' => true,
        ]);

        $product2 = Product::create([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Org2 Product',
            'sku' => 'O2P-001',
            'price' => 20.00,
            'stock' => 2,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 100,
            'is_active' => true,
        ]);

        $product2->suppliers()->attach($otherSupplier->id, [
            'cost_price' => 10.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Org 1 PO should be scoped to org 1
        $org1POs = PurchaseOrder::where('organization_id', $this->organization->id)->get();
        $this->assertCount(1, $org1POs);
        $this->assertEquals($this->supplier->id, $org1POs->first()->supplier_id);

        // Org 2 PO should be scoped to org 2
        $org2POs = PurchaseOrder::where('organization_id', $this->otherOrganization->id)->get();
        $this->assertCount(1, $org2POs);
        $this->assertEquals($otherSupplier->id, $org2POs->first()->supplier_id);

        // Items should be correct per org
        $org1Item = $org1POs->first()->items()->first();
        $this->assertEquals($product1->id, $org1Item->product_id);
        $this->assertEquals(50, $org1Item->quantity_ordered);

        $org2Item = $org2POs->first()->items()->first();
        $this->assertEquals($product2->id, $org2Item->product_id);
        $this->assertEquals(100, $org2Item->quantity_ordered);
    }

    /** @test */
    public function it_skips_products_without_supplier(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'No Supplier Product',
            'sku' => 'NSP-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // No PO should be created
        $this->assertEquals(0, PurchaseOrder::count());
    }

    /** @test */
    public function it_skips_products_with_null_reorder_point(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'No Reorder Product',
            'sku' => 'NRP-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => null,
            'reorder_quantity' => null,
            'is_active' => true,
        ]);

        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        $this->assertEquals(0, PurchaseOrder::count());
    }

    /** @test */
    public function it_skips_products_with_zero_reorder_quantity(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Zero Qty Product',
            'sku' => 'ZQP-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 0,
            'is_active' => true,
        ]);

        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        $this->assertEquals(0, PurchaseOrder::count());
    }

    /** @test */
    public function it_groups_products_by_supplier_into_single_po(): void
    {
        $product1 = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Product A',
            'sku' => 'PA-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product2 = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Product B',
            'sku' => 'PB-001',
            'price' => 20.00,
            'stock' => 1,
            'min_stock' => 5,
            'reorder_point' => 5,
            'reorder_quantity' => 30,
            'is_active' => true,
        ]);

        // Both products have the same primary supplier
        $product1->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);
        $product2->suppliers()->attach($this->supplier->id, [
            'cost_price' => 10.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Should create only ONE PO for the supplier
        $pos = PurchaseOrder::where('organization_id', $this->organization->id)->get();
        $this->assertCount(1, $pos);

        // PO should have 2 items
        $items = $pos->first()->items;
        $this->assertCount(2, $items);
    }

    /** @test */
    public function it_creates_separate_pos_for_different_suppliers(): void
    {
        $supplier2 = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Second Supplier',
            'code' => 'SUP-0003',
            'email' => 'supplier2@test.com',
            'is_active' => true,
        ]);

        $product1 = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Product A',
            'sku' => 'PA-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product2 = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Product B',
            'sku' => 'PB-001',
            'price' => 20.00,
            'stock' => 1,
            'min_stock' => 5,
            'reorder_point' => 5,
            'reorder_quantity' => 30,
            'is_active' => true,
        ]);

        $product1->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);
        $product2->suppliers()->attach($supplier2->id, [
            'cost_price' => 10.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Should create 2 POs (one per supplier)
        $pos = PurchaseOrder::where('organization_id', $this->organization->id)->get();
        $this->assertCount(2, $pos);
    }

    /** @test */
    public function it_does_not_create_po_when_stock_is_above_reorder_point(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Well Stocked Product',
            'sku' => 'WSP-001',
            'price' => 10.00,
            'stock' => 100,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        $this->assertEquals(0, PurchaseOrder::count());
    }

    /** @test */
    public function it_logs_activity_for_auto_created_purchase_orders(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Log Test Product',
            'sku' => 'LTP-001',
            'price' => 10.00,
            'stock' => 3,
            'min_stock' => 10,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        $product->suppliers()->attach($this->supplier->id, [
            'cost_price' => 5.00,
            'is_primary' => true,
        ]);

        $this->artisan('inventory:check-reorder-points')
            ->assertExitCode(0);

        // Should have logged activity
        $log = ActivityLog::where('organization_id', $this->organization->id)
            ->where('action', 'auto_reorder')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Auto-generated', $log->description);
    }
}
