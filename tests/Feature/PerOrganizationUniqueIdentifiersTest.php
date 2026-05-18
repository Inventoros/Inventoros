<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\WorkOrder;
use App\Models\Order\ReturnOrder;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerOrganizationUniqueIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $userA;
    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->orgA = Organization::create(['name' => 'Org A', 'email' => 'a@test.com']);
        $this->orgB = Organization::create(['name' => 'Org B', 'email' => 'b@test.com']);

        $this->userA = User::create([
            'name' => 'UA', 'email' => 'ua@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->orgA->id, 'role' => 'admin',
        ]);
        $this->userB = User::create([
            'name' => 'UB', 'email' => 'ub@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->orgB->id, 'role' => 'admin',
        ]);
    }

    public function test_two_organizations_can_use_the_same_product_sku(): void
    {
        Product::create([
            'organization_id' => $this->orgA->id,
            'sku' => 'WIDGET-01',
            'name' => 'Widget A',
            'price' => 10,
            'stock' => 0,
        ]);

        Product::create([
            'organization_id' => $this->orgB->id,
            'sku' => 'WIDGET-01',
            'name' => 'Widget B',
            'price' => 10,
            'stock' => 0,
        ]);

        $this->assertSame(2, Product::where('sku', 'WIDGET-01')->count());
    }

    public function test_same_org_cannot_use_duplicate_sku(): void
    {
        Product::create([
            'organization_id' => $this->orgA->id,
            'sku' => 'DUP-01',
            'name' => 'First',
            'price' => 10,
            'stock' => 0,
        ]);

        $this->expectException(QueryException::class);

        Product::create([
            'organization_id' => $this->orgA->id,
            'sku' => 'DUP-01',
            'name' => 'Second',
            'price' => 10,
            'stock' => 0,
        ]);
    }

    public function test_two_organizations_can_use_the_same_po_number(): void
    {
        $supplierA = Supplier::create(['organization_id' => $this->orgA->id, 'name' => 'SupA']);
        $supplierB = Supplier::create(['organization_id' => $this->orgB->id, 'name' => 'SupB']);

        PurchaseOrder::create([
            'organization_id' => $this->orgA->id,
            'po_number' => 'PO-2026-0001',
            'supplier_id' => $supplierA->id,
            'created_by' => $this->userA->id,
            'order_date' => now(),
            'subtotal' => 0,
            'tax' => 0,
            'shipping' => 0,
            'total' => 0,
        ]);

        PurchaseOrder::create([
            'organization_id' => $this->orgB->id,
            'po_number' => 'PO-2026-0001',
            'supplier_id' => $supplierB->id,
            'created_by' => $this->userB->id,
            'order_date' => now(),
            'subtotal' => 0,
            'tax' => 0,
            'shipping' => 0,
            'total' => 0,
        ]);

        $this->assertSame(2, PurchaseOrder::where('po_number', 'PO-2026-0001')->count());
    }

    public function test_two_organizations_can_use_the_same_transfer_number(): void
    {
        $locA1 = ProductLocation::create(['organization_id' => $this->orgA->id, 'name' => 'A1', 'code' => 'A1', 'is_active' => true]);
        $locA2 = ProductLocation::create(['organization_id' => $this->orgA->id, 'name' => 'A2', 'code' => 'A2', 'is_active' => true]);
        $locB1 = ProductLocation::create(['organization_id' => $this->orgB->id, 'name' => 'B1', 'code' => 'B1', 'is_active' => true]);
        $locB2 = ProductLocation::create(['organization_id' => $this->orgB->id, 'name' => 'B2', 'code' => 'B2', 'is_active' => true]);

        StockTransfer::create([
            'organization_id' => $this->orgA->id,
            'transfer_number' => 'ST-2026-0001',
            'from_location_id' => $locA1->id,
            'to_location_id' => $locA2->id,
            'transferred_by' => $this->userA->id,
            'status' => 'pending',
        ]);

        StockTransfer::create([
            'organization_id' => $this->orgB->id,
            'transfer_number' => 'ST-2026-0001',
            'from_location_id' => $locB1->id,
            'to_location_id' => $locB2->id,
            'transferred_by' => $this->userB->id,
            'status' => 'pending',
        ]);

        $this->assertSame(2, StockTransfer::where('transfer_number', 'ST-2026-0001')->count());
    }

    public function test_two_organizations_can_use_the_same_work_order_number(): void
    {
        $kitA = Product::create([
            'organization_id' => $this->orgA->id, 'sku' => 'KIT-A', 'name' => 'Kit A',
            'price' => 1, 'stock' => 0, 'type' => 'assembly',
        ]);
        $kitB = Product::create([
            'organization_id' => $this->orgB->id, 'sku' => 'KIT-B', 'name' => 'Kit B',
            'price' => 1, 'stock' => 0, 'type' => 'assembly',
        ]);

        WorkOrder::create([
            'organization_id' => $this->orgA->id,
            'work_order_number' => 'WO-2026-0001',
            'product_id' => $kitA->id,
            'quantity_to_produce' => 1,
            'status' => 'pending',
            'created_by' => $this->userA->id,
        ]);

        WorkOrder::create([
            'organization_id' => $this->orgB->id,
            'work_order_number' => 'WO-2026-0001',
            'product_id' => $kitB->id,
            'quantity_to_produce' => 1,
            'status' => 'pending',
            'created_by' => $this->userB->id,
        ]);

        $this->assertSame(2, WorkOrder::where('work_order_number', 'WO-2026-0001')->count());
    }
}
