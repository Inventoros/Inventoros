<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PurchaseOrderReceivingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected Supplier $supplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Stock changes fan out to notifications that build a real mailer
        // otherwise; fake both so the receive flow never hits a transport.
        Mail::fake();
        Notification::fake();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->supplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
            'is_active' => true,
        ]);

        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 10,
            'category_id' => $category->id,
            'location_id' => $location->id,
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
                    'view_purchase_orders',
                    'manage_purchase_orders',
                    'receive_purchase_orders',
                    'edit_purchase_orders',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_double_submit_does_not_over_receive(): void
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-RECV-001',
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'subtotal' => 50.00,
            'tax' => 0,
            'total' => 50.00,
            'currency' => 'USD',
        ]);

        $item = $po->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 5.00,
            'subtotal' => 50.00,
            'tax' => 0,
            'total' => 50.00,
        ]);

        $payload = ['items' => [['id' => $item->id, 'quantity_to_receive' => 10]]];

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $po), $payload)
            ->assertRedirect();

        $this->assertSame(10, $this->product->fresh()->stock);

        // Replay the identical submit — must not increment again (PO is now
        // RECEIVED, item fully received).
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $po), $payload);

        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertSame(10, $item->fresh()->quantity_received);
    }
}
