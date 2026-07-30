<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCodeAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'O', 'email' => 'o@o.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'A', 'email' => 'a@o.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Admin', 'is_system' => true,
            'permissions' => ['create_suppliers', 'delete_suppliers'],
        ])->users()->syncWithoutDetaching([$this->admin->id]);
    }

    public function test_duplicate_supplier_code_returns_a_validation_error_not_a_500(): void
    {
        Supplier::create(['organization_id' => $this->org->id, 'name' => 'First', 'code' => 'ACME', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => 'Second', 'code' => 'ACME'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Supplier::where('code', 'ACME')->count());
    }

    public function test_cannot_delete_a_supplier_with_open_purchase_orders(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'S', 'is_active' => true]);
        PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-1',
            'status' => PurchaseOrder::STATUS_SENT, 'order_date' => now()->toDateString(),
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD', 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);
    }

    public function test_can_delete_a_supplier_with_only_terminal_purchase_orders(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'S', 'is_active' => true]);
        PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-2',
            'status' => PurchaseOrder::STATUS_RECEIVED, 'order_date' => now()->toDateString(),
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD', 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }
}
