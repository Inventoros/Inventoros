<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Factories\SupplierFactory;

/**
 * Two warehouses in one organization. The "restricted" user is assigned to
 * warehouse A only; everything under warehouse B must be out of their reach
 * on every surface.
 */
trait WarehouseAccessFixture
{
    protected Organization $organization;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected ProductLocation $locationA;

    protected ProductLocation $locationB;

    protected Product $productA;

    protected Product $productB;

    protected User $admin;

    protected User $restricted;

    protected User $unassigned;

    /** @var array<int, string> */
    protected array $stockPermissions = [
        'view_products', 'edit_products', 'manage_stock', 'manage_locations', 'view_locations',
        'transfer_stock', 'view_stock_audits', 'create_stock_audits', 'manage_stock_audits',
        'view_purchase_orders', 'receive_purchase_orders', 'view_warehouses',
        'view_stock_adjustments',
    ];

    protected function setUpWarehouseAccess(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Access Org',
            'email' => 'access@org.test',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->warehouseA = Warehouse::factory()->create([
            'organization_id' => $this->organization->id, 'name' => 'Alpha Warehouse', 'code' => 'WH-ALPHA', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::factory()->create([
            'organization_id' => $this->organization->id, 'name' => 'Bravo Warehouse', 'code' => 'WH-BRAVO',
        ]);

        $this->locationA = ProductLocation::create([
            'organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouseA->id,
            'name' => 'Alpha Shelf', 'code' => 'A-1', 'is_active' => true,
        ]);
        $this->locationB = ProductLocation::create([
            'organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouseB->id,
            'name' => 'Bravo Shelf', 'code' => 'B-1', 'is_active' => true,
        ]);

        $this->productA = $this->makeProduct('Alpha Widget', 'ALPHA-1', $this->locationA, 100);
        $this->productB = $this->makeProduct('Bravo Widget', 'BRAVO-1', $this->locationB, 50);

        // productA holds stock in both warehouses.
        ProductLocationStock::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id, 'location_id' => $this->locationA->id, 'quantity' => 60]);
        ProductLocationStock::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id, 'location_id' => $this->locationB->id, 'quantity' => 40]);
        ProductLocationStock::create(['organization_id' => $this->organization->id, 'product_id' => $this->productB->id, 'location_id' => $this->locationB->id, 'quantity' => 50]);

        $role = Role::create([
            'name' => 'Stock Clerk',
            'slug' => 'stock-clerk-'.uniqid(),
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => $this->stockPermissions,
        ]);

        $this->admin = User::factory()->admin()->forOrganization($this->organization->id)->create(['name' => 'Org Admin']);

        $this->restricted = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Alpha Clerk']);
        $this->restricted->roles()->attach($role->id);
        $this->warehouseA->users()->attach($this->restricted->id);

        $this->unassigned = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Floating Clerk']);
        $this->unassigned->roles()->attach($role->id);
    }

    protected function makeProduct(string $name, string $sku, ?ProductLocation $location, int $stock): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => $sku,
            'name' => $name,
            'price' => 10,
            'currency' => 'USD',
            'stock' => $stock,
            'min_stock' => 5,
            'is_active' => true,
            'location_id' => $location?->id,
        ]);
    }

    protected function makeAdjustment(Product $product, ?ProductLocation $location, string $reason): StockAdjustment
    {
        return StockAdjustment::create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'location_id' => $location?->id,
            'user_id' => $this->admin->id,
            'type' => 'manual',
            'quantity_before' => 10,
            'quantity_after' => 11,
            'adjustment_quantity' => 1,
            'reason' => $reason,
        ]);
    }

    protected function makeTransfer(ProductLocation $from, ProductLocation $to, Product $product): StockTransfer
    {
        $transfer = StockTransfer::create([
            'organization_id' => $this->organization->id,
            'transfer_number' => StockTransfer::generateTransferNumber($this->organization->id),
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'transferred_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        return $transfer;
    }

    protected function makeAudit(?ProductLocation $location, string $name): StockAudit
    {
        return StockAudit::create([
            'organization_id' => $this->organization->id,
            'audit_number' => StockAudit::generateAuditNumber($this->organization->id),
            'name' => $name,
            'status' => 'draft',
            'audit_type' => 'cycle',
            'warehouse_location_id' => $location?->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * A sent PO with one line for the given product.
     *
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem}
     */
    protected function makeSentPurchaseOrder(Product $product): array
    {
        $supplier = SupplierFactory::new()->create(['organization_id' => $this->organization->id]);

        $po = PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'po_number' => PurchaseOrder::generatePONumber($this->organization->id),
            'status' => 'sent',
            'subtotal' => 50,
            'tax' => 0,
            'shipping' => 0,
            'total' => 50,
            'order_date' => now(),
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 5,
            'subtotal' => 50,
            'tax' => 0,
            'total' => 50,
        ]);

        return [$po, $item];
    }
}
