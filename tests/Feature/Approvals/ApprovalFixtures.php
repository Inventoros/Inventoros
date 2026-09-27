<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Shared world for the approval workflow tests: one organization with an
 * admin, a requester who can do the work but not approve it, an approver who
 * can approve all three kinds of request, and a bystander with neither.
 */
trait ApprovalFixtures
{
    protected Organization $org;

    protected User $admin;

    protected User $requester;

    protected User $approver;

    protected User $bystander;

    protected Supplier $supplier;

    protected Product $product;

    protected ProductLocation $locationA;

    protected ProductLocation $locationB;

    protected function setUpApprovalWorld(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        Mail::fake();

        $this->org = Organization::create([
            'name' => 'Acme Hardware', 'email' => 'ops@acme.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $workRole = Role::create([
            'slug' => 'worker', 'name' => 'Worker', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => [
                'view_products', 'manage_stock', 'transfer_stock',
                'view_purchase_orders', 'create_purchase_orders', 'edit_purchase_orders', 'receive_purchase_orders',
            ],
        ]);
        $approverRole = Role::create([
            'slug' => 'approver', 'name' => 'Approver', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => [
                'view_products', 'manage_stock', 'transfer_stock',
                'view_purchase_orders', 'create_purchase_orders', 'edit_purchase_orders', 'receive_purchase_orders',
                'approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers',
            ],
        ]);
        $viewRole = Role::create([
            'slug' => 'viewer', 'name' => 'Viewer', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => ['view_products', 'view_purchase_orders'],
        ]);

        $this->admin = $this->makeUser('Ada Admin', 'ada@acme.test', 'admin');
        $this->requester = $this->makeUser('Rae Requester', 'rae@acme.test', 'member', $workRole);
        $this->approver = $this->makeUser('Abe Approver', 'abe@acme.test', 'member', $approverRole);
        $this->bystander = $this->makeUser('Bo Bystander', 'bo@acme.test', 'member', $viewRole);

        $this->supplier = Supplier::create([
            'organization_id' => $this->org->id, 'name' => 'Bolt Supply', 'email' => 'orders@bolt.test', 'is_active' => true,
        ]);

        $this->locationA = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Aisle A', 'code' => 'A', 'is_active' => true,
        ]);
        $this->locationB = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Aisle B', 'code' => 'B', 'is_active' => true,
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'B-1', 'name' => 'Bolt',
            'price' => 4, 'purchase_price' => 2, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
            'is_active' => true, 'location_id' => $this->locationA->id,
        ]);
    }

    protected function makeUser(string $name, string $email, string $role, ?Role $customRole = null): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => $role,
        ]);

        if ($customRole) {
            $user->roles()->syncWithoutDetaching([$customRole->id]);
        }

        return $user;
    }

    /**
     * Call an MCP tool as $user. Guards are reset first: the sanctum guard
     * caches the first user it resolves, so switching actors inside one test
     * would otherwise keep acting as the previous one.
     */
    protected function mcpAs(User $user): \Laravel\Mcp\Server\Testing\PendingTestResponse
    {
        $this->app['auth']->forgetGuards();

        return \App\Mcp\Servers\InventorosServer::actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function enableApprovals(array $settings): void
    {
        $current = $this->org->fresh()->settings ?? [];
        $current['approvals'] = array_merge($current['approvals'] ?? [], $settings);
        $this->org->update(['settings' => $current]);
    }

    protected function draftPo(float $total = 500, ?User $creator = null): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => ($creator ?? $this->requester)->id,
            'po_number' => 'PO-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now(),
            'subtotal' => $total, 'tax' => 0, 'shipping' => 0, 'total' => $total,
            'currency' => 'USD',
        ]);

        $po->items()->create([
            'product_id' => $this->product->id, 'product_name' => 'Bolt', 'sku' => 'B-1',
            'quantity_ordered' => 10, 'quantity_received' => 0,
            'unit_cost' => $total / 10, 'subtotal' => $total, 'tax' => 0, 'total' => $total,
        ]);

        return $po;
    }
}
