<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\DecideApprovalTool;
use App\Mcp\Tools\ListPendingApprovalsTool;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * With "Sales orders need approval" on, a pending sales order is one more
 * kind of request in the approvals queue (page, nav badge, REST, GraphQL,
 * MCP). Deciding it goes through OrderService::approve()/reject(), so the
 * order-specific effects (reject cancels and restocks) and the
 * self-approval rule are the same as on the order page.
 */
final class SalesOrderApprovalQueueTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    private User $orderApprover;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpApprovalWorld();
        $this->enableApprovals(['orders_enabled' => true]);

        $role = Role::create([
            'slug' => 'order-approver', 'name' => 'Order approver', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => ['view_orders', 'approve_orders'],
        ]);
        $this->orderApprover = $this->makeUser('Ola Orders', 'ola@acme.test', 'member', $role);

        $salesRole = Role::create([
            'slug' => 'sales', 'name' => 'Sales', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => ['view_orders', 'create_orders', 'view_products'],
        ]);
        $seller = $this->makeUser('Sam Seller', 'sam@acme.test', 'member', $salesRole);

        $this->actingAs($seller);
        $this->order = app(OrderService::class)->create([
            'customer_name' => 'Walk-in', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 4]],
        ], $seller);
        $this->app['auth']->forgetGuards();

        $this->assertSame(OrderApprovalStatus::PENDING, $this->order->approval_status);
    }

    private function token(User $user, array $abilities): string
    {
        return $user->createToken('scoped', $abilities)->plainTextToken;
    }

    public function test_the_page_and_badge_list_a_pending_sales_order(): void
    {
        $this->actingAs($this->orderApprover)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pending', 1)
                ->where('pending.0.type', 'sales_order')
                ->where('pending.0.id', $this->order->id)
                ->where('pending.0.reference', $this->order->order_number)
                ->where('pendingApprovalsCount', 1));

        // Someone without approve_orders does not see it.
        $this->actingAs($this->approver)
            ->get(route('approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 0));
    }

    public function test_orders_are_not_queued_when_the_setting_is_off(): void
    {
        $this->enableApprovals(['orders_enabled' => false]);
        $this->actingAs($this->requester);
        app(OrderService::class)->create([
            'customer_name' => 'No approval', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 4]],
        ], $this->requester);

        // Only the order raised while approval was required is waiting.
        $this->assertCount(1, app(ApprovalService::class)->pendingFor($this->orderApprover));
    }

    public function test_approving_from_the_queue_uses_the_order_approval(): void
    {
        $this->actingAs($this->orderApprover)
            ->post(route('approvals.approve', ['type' => 'sales_order', 'id' => $this->order->id]), ['notes' => 'ok'])
            ->assertRedirect();

        $order = $this->order->fresh();
        $this->assertSame(OrderApprovalStatus::APPROVED, $order->approval_status);
        $this->assertSame($this->orderApprover->id, $order->approved_by);
        $this->assertSame('ok', $order->approval_notes);
    }

    public function test_rejecting_from_the_queue_cancels_and_restocks(): void
    {
        $this->assertSame(95, $this->product->fresh()->stock);

        $this->actingAs($this->orderApprover)
            ->post(route('approvals.reject', ['type' => 'sales_order', 'id' => $this->order->id]), ['notes' => 'No credit'])
            ->assertRedirect();

        $order = $this->order->fresh();
        $this->assertSame(OrderApprovalStatus::REJECTED, $order->approval_status);
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(100, $this->product->fresh()->stock);
    }

    public function test_the_creator_cannot_approve_their_own_order_unless_an_admin_may_self_approve(): void
    {
        $this->actingAs($this->admin);
        $own = app(OrderService::class)->create([
            'customer_name' => 'Mine', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 4]],
        ], $this->admin);

        $this->enableApprovals(['admins_can_self_approve' => false]);
        $service = app(ApprovalService::class);
        $this->assertFalse($service->pendingFor($this->admin->fresh())->contains(fn ($i) => $i['id'] === $own->id && $i['type'] === 'sales_order'));

        $this->actingAs($this->admin)
            ->post(route('approvals.approve', ['type' => 'sales_order', 'id' => $own->id]))
            ->assertSessionHas('error');
        $this->assertSame(OrderApprovalStatus::PENDING, $own->fresh()->approval_status);

        $this->enableApprovals(['admins_can_self_approve' => true]);
        $service->approve($this->admin->fresh(), 'sales_order', $own->id);
        $this->assertSame(OrderApprovalStatus::APPROVED, $own->fresh()->approval_status);
    }

    public function test_rest_hides_sales_orders_from_a_token_without_approve_orders(): void
    {
        $this->withToken($this->token($this->orderApprover, ['view_orders']))
            ->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($this->orderApprover, ['view_orders']))
            ->postJson("/api/v1/approvals/sales_order/{$this->order->id}/approve")
            ->assertForbidden();
        $this->assertSame(OrderApprovalStatus::PENDING, $this->order->fresh()->approval_status);
    }

    public function test_rest_lists_and_decides_with_the_matching_token_ability(): void
    {
        $token = $this->token($this->orderApprover, ['approve_orders']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'sales_order');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->postJson("/api/v1/approvals/sales_order/{$this->order->id}/approve")
            ->assertOk();

        $this->assertSame(OrderApprovalStatus::APPROVED, $this->order->fresh()->approval_status);
    }

    public function test_graphql_lists_and_decides(): void
    {
        $token = $this->token($this->orderApprover, ['approve_orders']);

        $this->withToken($token)->postJson('/graphql', ['query' => '{ pendingApprovals { type id } }'])
            ->assertJsonPath('data.pendingApprovals.0.type', 'sales_order');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/graphql', [
            'query' => "mutation { decideApproval(type: \"sales_order\", id: {$this->order->id}, decision: \"reject\", notes: \"No\") { status } }",
        ])->assertJsonPath("data.decideApproval.status", "rejected");

        $this->assertSame(OrderStatus::CANCELLED, $this->order->fresh()->status);
    }

    public function test_mcp_lists_and_decides(): void
    {
        $plain = $this->token($this->orderApprover, ['approve_orders']);
        $this->orderApprover->withAccessToken(PersonalAccessToken::findToken($plain));
        $this->app['auth']->forgetGuards();

        InventorosServer::actingAs($this->orderApprover, 'sanctum')
            ->tool(ListPendingApprovalsTool::class, [])
            ->assertOk()
            ->assertSee('sales_order');

        InventorosServer::actingAs($this->orderApprover, 'sanctum')
            ->tool(DecideApprovalTool::class, ['type' => 'sales_order', 'id' => $this->order->id, 'decision' => 'approve'])
            ->assertOk();

        $this->assertSame(OrderApprovalStatus::APPROVED, $this->order->fresh()->approval_status);
    }

    public function test_the_order_creator_sees_it_under_my_requests(): void
    {
        $mine = app(ApprovalService::class)->requestedBy(User::where('email', 'sam@acme.test')->firstOrFail());

        $this->assertTrue($mine->contains(fn ($i) => $i['type'] === 'sales_order' && $i['id'] === $this->order->id && $i['status'] === 'pending'));
    }
}
