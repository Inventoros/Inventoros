<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Exceptions\ApprovalException;
use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\OrderService;
use App\Support\ApprovalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Whoever changed an approved order's terms (sending it back for approval)
 * is its requester for that decision: like the order's creator, they cannot
 * approve or reject it themselves, on any decision path.
 */
final class OrderReapprovalRequesterTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $creator;

    private User $editor;

    private User $approver;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $permissions = ['view_orders', 'create_orders', 'edit_orders', 'approve_orders'];
        $this->creator = $this->makeMember($this->org, $permissions);
        $this->editor = $this->makeMember($this->org, $permissions);
        $this->approver = $this->makeMember($this->org, $permissions);
        $this->product = $this->makeProduct($this->org, ['stock' => 50, 'price' => 10]);
        $this->setApprovals(['orders_enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function setApprovals(array $values): void
    {
        $settings = $this->org->fresh()->settings ?? [];
        $settings[ApprovalSettings::KEY] = array_merge($settings[ApprovalSettings::KEY] ?? [], $values);
        $this->org->forceFill(['settings' => $settings])->save();
    }

    /** An order approved by the approver, then re-priced by $editor through the API. */
    private function orderChangedBy(User $editor): Order
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10]],
        ], $this->creator);
        app(OrderService::class)->approve($order, $this->approver);

        Sanctum::actingAs($editor, ['*']);
        $this->putJson("/api/v1/orders/{$order->id}", ['discount_type' => 'percent', 'discount_value' => 90])->assertOk();
        $this->app['auth']->forgetGuards();

        $order = $order->fresh();
        $this->assertSame(OrderApprovalStatus::PENDING, $order->approval_status);

        return $order;
    }

    private function assertStillPending(Order $order): void
    {
        $fresh = $order->fresh();
        $this->assertSame(OrderApprovalStatus::PENDING, $fresh->approval_status);
        $this->assertSame(OrderStatus::PENDING, $fresh->status);
        $this->assertNull($fresh->approved_by);
    }

    public function test_the_reset_records_who_changed_the_order(): void
    {
        $order = $this->orderChangedBy($this->editor);

        $this->assertSame($this->editor->id, $order->approval_requested_by);
    }

    public function test_a_graphql_edit_records_the_editor_too(): void
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10]],
        ], $this->creator);
        app(OrderService::class)->approve($order, $this->approver);

        Sanctum::actingAs($this->editor, ['*']);
        $this->postJson('/graphql', [
            'query' => sprintf('mutation { updateOrder(id: %d, customer_name: "Someone Else") { id } }', $order->id),
        ])->assertJsonMissingPath('errors');

        $this->assertSame($this->editor->id, $order->fresh()->approval_requested_by);
    }

    public function test_the_editor_cannot_approve_or_reject_on_the_order_page(): void
    {
        $order = $this->orderChangedBy($this->editor);

        $this->actingAs($this->editor)->post(route('orders.approve', $order), ['notes' => 'mine'])->assertSessionHas('error');
        $this->actingAs($this->editor)->post(route('orders.reject', $order), ['notes' => 'mine'])->assertSessionHas('error');

        $this->assertStillPending($order);
    }

    public function test_the_editor_cannot_approve_or_reject_through_the_api(): void
    {
        $order = $this->orderChangedBy($this->editor);
        Sanctum::actingAs($this->editor, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/approve", ['notes' => 'mine'])
            ->assertStatus(422)->assertJsonPath('error', 'self_approval');
        $this->postJson("/api/v1/orders/{$order->id}/reject", ['notes' => 'mine'])
            ->assertStatus(422)->assertJsonPath('error', 'self_approval');

        $this->assertStillPending($order);
    }

    public function test_the_editor_cannot_decide_it_from_the_approvals_inbox_rest_or_graphql(): void
    {
        $order = $this->orderChangedBy($this->editor);

        $this->actingAs($this->editor)
            ->post(route('approvals.approve', ['type' => 'sales_order', 'id' => $order->id]))
            ->assertSessionHas('error');

        Sanctum::actingAs($this->editor, ['*']);
        $this->postJson("/api/v1/approvals/sales_order/{$order->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/approvals/sales_order/{$order->id}/reject", ['notes' => 'mine'])->assertForbidden();

        $response = $this->postJson('/graphql', ['query' => sprintf(
            'mutation { decideApproval(type: "sales_order", id: %d, decision: "approve") { id } }', $order->id
        )]);
        $this->assertNotEmpty($response->json('errors'));

        $this->assertStillPending($order);
    }

    public function test_the_service_paths_refuse_the_editor(): void
    {
        $order = $this->orderChangedBy($this->editor);

        try {
            app(OrderService::class)->approve($order, $this->editor);
            $this->fail('The editor approved their own change.');
        } catch (InvalidStateException $e) {
            $this->assertSame('self_approval', $e->errorCode);
        }

        try {
            app(OrderService::class)->reject($order, $this->editor, 'mine');
            $this->fail('The editor rejected their own change.');
        } catch (InvalidStateException $e) {
            $this->assertSame('self_approval', $e->errorCode);
        }

        try {
            app(ApprovalService::class)->approve($this->editor, ApprovalService::SALES_ORDER, $order->id);
            $this->fail('The editor approved their own change through the approvals service.');
        } catch (ApprovalException $e) {
            $this->assertSame(ApprovalException::SELF_APPROVAL, $e->reason);
        }

        $this->assertStillPending($order);
    }

    public function test_someone_else_can_approve_the_changed_order(): void
    {
        $order = $this->orderChangedBy($this->editor);

        $this->actingAs($this->approver)
            ->post(route('orders.approve', $order), ['notes' => 'checked'])
            ->assertSessionHas('success');

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
        $this->assertSame($this->approver->id, $order->fresh()->approved_by);
    }

    public function test_someone_else_can_reject_it_through_the_approvals_inbox(): void
    {
        $order = $this->orderChangedBy($this->editor);

        app(ApprovalService::class)->reject($this->approver, ApprovalService::SALES_ORDER, $order->id, 'too cheap');

        $this->assertSame(OrderApprovalStatus::REJECTED, $order->fresh()->approval_status);
    }

    public function test_an_admin_editor_follows_the_self_approval_setting(): void
    {
        $admin = $this->makeAdmin($this->org);

        $allowed = $this->orderChangedBy($admin);
        app(OrderService::class)->approve($allowed, $admin);
        $this->assertSame(OrderApprovalStatus::APPROVED, $allowed->fresh()->approval_status);

        $this->setApprovals(['admins_can_self_approve' => false]);
        $refused = $this->orderChangedBy($admin);
        $this->expectException(InvalidStateException::class);
        app(OrderService::class)->approve($refused, $admin);
    }
}
