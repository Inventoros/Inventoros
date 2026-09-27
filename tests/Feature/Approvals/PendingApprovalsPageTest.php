<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Enums\Permission;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Support\ApprovalSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PendingApprovalsPageTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
        $this->withoutVite();
        Mail::fake();
    }

    private function seedPending(): void
    {
        $this->enableApprovals([
            'purchase_orders_enabled' => true,
            'stock_adjustments_enabled' => true,
            'stock_transfers_enabled' => true,
        ]);

        // One of each from the requester, plus one PO the approver raised.
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $own = $this->draftPo(500, $this->approver);
        $this->actingAs($this->approver)->post(route('purchase-orders.submit-approval', $own));

        $this->actingAs($this->requester)->post(route('stock-adjustments.store'), [
            'product_id' => $this->product->id, 'type' => 'damage', 'adjustment_quantity' => -2, 'reason' => 'x',
        ]);
        $this->actingAs($this->requester)->post(route('stock-transfers.store'), [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
    }

    public function test_the_page_lists_what_the_user_can_approve_but_not_their_own_requests(): void
    {
        $this->seedPending();

        $this->actingAs($this->approver)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Approvals/Index')
                ->has('pending', 3)
                ->where('pending.0.can_decide', true)
                ->has('mine', 1)
                ->where('pendingApprovalsCount', 3)
            );
    }

    public function test_an_admin_sees_everything_including_what_they_may_self_approve(): void
    {
        $this->seedPending();

        $this->actingAs($this->admin)
            ->get(route('approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 4)->where('pendingApprovalsCount', 4));
    }

    public function test_a_user_without_approval_permissions_sees_only_their_own_requests(): void
    {
        $this->seedPending();

        $this->actingAs($this->requester)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pending', 0)
                ->has('mine', 3)
                ->where('pendingApprovalsCount', 0)
            );
    }

    public function test_decided_requests_leave_the_queue(): void
    {
        $this->seedPending();
        $request = StockAdjustmentRequest::sole();
        $this->actingAs($this->approver)->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $request->id]));

        $this->actingAs($this->approver)
            ->get(route('approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 2)->where('pendingApprovalsCount', 2));
    }

    // ==================== SETTINGS ====================

    public function test_settings_default_to_off(): void
    {
        $settings = ApprovalSettings::forOrganization($this->org->id);

        $this->assertFalse($settings->purchaseOrdersEnabled);
        $this->assertFalse($settings->stockAdjustmentsEnabled);
        $this->assertFalse($settings->stockTransfersEnabled);
        $this->assertTrue($settings->adminsCanSelfApprove);
        $this->assertNull($settings->purchaseOrdersThreshold);
    }

    public function test_an_organization_manager_can_save_the_approval_settings(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.approvals'), [
                'purchase_orders_enabled' => true,
                'purchase_orders_threshold' => 250,
                'stock_adjustments_enabled' => true,
                'stock_adjustments_quantity_threshold' => 20,
                'stock_adjustments_value_threshold' => null,
                'stock_transfers_enabled' => false,
                'admins_can_self_approve' => false,
            ])
            ->assertSessionHas('success');

        $settings = ApprovalSettings::forOrganization($this->org->id);
        $this->assertTrue($settings->purchaseOrdersEnabled);
        $this->assertSame(250.0, $settings->purchaseOrdersThreshold);
        $this->assertTrue($settings->stockAdjustmentsEnabled);
        $this->assertSame(20, $settings->stockAdjustmentsQuantityThreshold);
        $this->assertNull($settings->stockAdjustmentsValueThreshold);
        $this->assertFalse($settings->stockTransfersEnabled);
        $this->assertFalse($settings->adminsCanSelfApprove);
    }

    public function test_saving_approval_settings_needs_manage_organization(): void
    {
        $this->actingAs($this->approver)
            ->patch(route('settings.organization.update.approvals'), ['purchase_orders_enabled' => true])
            ->assertForbidden();

        $this->assertFalse(ApprovalSettings::forOrganization($this->org->id)->purchaseOrdersEnabled);
    }

    // ==================== PERMISSIONS ====================

    public function test_the_approval_permissions_exist_and_reach_the_role_templates(): void
    {
        foreach (['approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers'] as $name) {
            $permission = Permission::from($name);
            $this->assertNotSame('', $permission->label());
            $this->assertNotSame('', $permission->description());
        }

        $this->seed(RoleSeeder::class);

        $admin = Role::where('slug', 'system-administrator')->firstOrFail();
        $this->assertContains('approve_stock_adjustments', $admin->permissions);

        $manager = Role::where('slug', 'system-manager')->firstOrFail();
        $this->assertContains('approve_purchase_orders', $manager->permissions);
        $this->assertContains('approve_stock_transfers', $manager->permissions);

        $approver = collect(PermissionSet::getDefaultTemplates())->firstWhere('slug', 'approver');
        $this->assertNotNull($approver);
        $this->assertContains('approve_stock_adjustments', $approver['permissions']);
    }
}
