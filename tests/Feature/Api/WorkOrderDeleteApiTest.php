<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\DeleteWorkOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\WorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Deleting a work order over REST and MCP uses the same guard as the web
 * action (WorkOrderService::delete): only draft or cancelled work orders,
 * which have never moved stock, can be deleted.
 */
class WorkOrderDeleteApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $assembly;

    private Product $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->assembly = $this->makeProduct($this->org, ['type' => 'assembly']);
        $this->component = $this->makeProduct($this->org);
    }

    private function workOrder(string $status, ?Organization $org = null): WorkOrder
    {
        $org ??= $this->org;
        $workOrder = WorkOrder::create([
            'organization_id' => $org->id,
            'product_id' => $org->is($this->org) ? $this->assembly->id : $this->makeProduct($org)->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-'.uniqid(),
            'quantity' => 2,
            'status' => $status,
        ]);
        $workOrder->items()->create(['product_id' => $this->component->id, 'quantity_required' => 4, 'quantity_consumed' => 0]);

        return $workOrder;
    }

    public function test_rest_deletes_draft_and_cancelled_work_orders(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['draft', 'cancelled'] as $status) {
            $workOrder = $this->workOrder($status);

            $this->deleteJson("/api/v1/work-orders/{$workOrder->id}")->assertOk();

            $this->assertDatabaseMissing('work_orders', ['id' => $workOrder->id]);
            $this->assertDatabaseMissing('work_order_items', ['work_order_id' => $workOrder->id]);
        }
    }

    public function test_rest_refuses_work_orders_that_moved_stock(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['in_progress', 'completed'] as $status) {
            $workOrder = $this->workOrder($status);

            $this->deleteJson("/api/v1/work-orders/{$workOrder->id}")
                ->assertStatus(422)
                ->assertJsonPath('error', 'invalid_status');

            $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);
        }
    }

    public function test_rest_requires_manage_stock_and_scopes_to_tenant(): void
    {
        $workOrder = $this->workOrder('draft');

        Sanctum::actingAs($this->makeMember($this->org, ['view_products']));
        $this->deleteJson("/api/v1/work-orders/{$workOrder->id}")->assertForbidden();

        Sanctum::actingAs($this->makeAdmin($this->makeOrganization('Other')));
        $this->deleteJson("/api/v1/work-orders/{$workOrder->id}")->assertNotFound();

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);
    }

    public function test_mcp_tool_uses_the_same_guard(): void
    {
        $draft = $this->workOrder('draft');
        $running = $this->workOrder('in_progress');

        InventorosServer::actingAs($this->admin)
            ->tool(DeleteWorkOrderTool::class, ['id' => $draft->id])
            ->assertOk();
        $this->assertDatabaseMissing('work_orders', ['id' => $draft->id]);

        InventorosServer::actingAs($this->admin)
            ->tool(DeleteWorkOrderTool::class, ['id' => $running->id])
            ->assertHasErrors(['draft or cancelled']);
        $this->assertDatabaseHas('work_orders', ['id' => $running->id]);

        InventorosServer::actingAs($this->makeMember($this->org, ['view_products']))
            ->tool(DeleteWorkOrderTool::class, ['id' => $running->id])
            ->assertHasErrors();
    }
}
