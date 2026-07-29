<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A purchase order edit must not be able to point the PO at another tenant's
 * supplier (which the report builder would then resolve into a supplier-name
 * disclosure).
 */
class PurchaseOrderSupplierScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_po_with_another_orgs_supplier_is_rejected(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create(['name' => 'A', 'email' => 'a@o.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@a.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Admin', 'is_system' => true, 'permissions' => ['edit_purchase_orders'],
        ])->users()->syncWithoutDetaching([$admin->id]);

        $ownSupplier = Supplier::create(['organization_id' => $org->id, 'name' => 'Own', 'is_active' => true]);
        $product = Product::create([
            'organization_id' => $org->id, 'sku' => 'P-1', 'name' => 'P', 'price' => 10,
            'currency' => 'USD', 'stock' => 5, 'is_active' => true,
        ]);
        $po = PurchaseOrder::create([
            'organization_id' => $org->id, 'supplier_id' => $ownSupplier->id, 'po_number' => 'PO-1',
            'status' => 'draft', 'order_date' => now()->toDateString(),
            'subtotal' => 10, 'tax' => 0, 'total' => 10, 'currency' => 'USD', 'created_by' => $admin->id,
        ]);
        $item = $po->items()->create([
            'product_id' => $product->id, 'product_name' => 'P', 'quantity_ordered' => 1,
            'quantity_received' => 0, 'unit_cost' => 10, 'subtotal' => 10, 'tax' => 0, 'total' => 10,
        ]);

        // Another org's supplier.
        $foreignOrg = Organization::create(['name' => 'B', 'email' => 'b@o.com']);
        $foreignSupplier = Supplier::create(['organization_id' => $foreignOrg->id, 'name' => 'Foreign', 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('purchase-orders.update', $po), [
                'supplier_id' => $foreignSupplier->id, // cross-tenant
                'order_date' => now()->toDateString(), 'currency' => 'USD',
                'items' => [[
                    'id' => $item->id, 'product_id' => $product->id,
                    'quantity' => 1, 'unit_cost' => 10,
                ]],
            ])
            ->assertSessionHasErrors('supplier_id');

        // The PO still points at its own supplier.
        $this->assertSame($ownSupplier->id, $po->fresh()->supplier_id);
    }
}
