<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * The person who raised an order cannot approve it themselves (admins only
 * when the approvals setting allows it), as for purchase orders and stock
 * requests.
 */
class OrderApprovalGuardTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $approver;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->approver = $this->makeMember($this->org, ['view_orders', 'create_orders', 'edit_orders', 'approve_orders']);
        $this->product = $this->makeProduct($this->org, ['stock' => 10]);
    }

    private function pendingOrder(User $creator): Order
    {
        $this->actingAs($creator);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer',
            'status' => 'pending',
            'approval_status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10]],
        ], $creator);
        auth()->forgetGuards();

        return $order;
    }

    private function setAdminsCanSelfApprove(bool $allowed): void
    {
        $settings = $this->org->fresh()->settings ?? [];
        $settings['approvals']['admins_can_self_approve'] = $allowed;
        $this->org->update(['settings' => $settings]);
    }

    public function test_an_approver_cannot_approve_their_own_order(): void
    {
        $order = $this->pendingOrder($this->approver);
        Sanctum::actingAs($this->approver);

        $this->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'self_approval');
        $this->assertTrue($order->fresh()->isPendingApproval());

        $this->actingAs($this->approver)
            ->post(route('orders.approve', $order))
            ->assertSessionHas('error');
        $this->assertTrue($order->fresh()->isPendingApproval());
    }

    public function test_someone_else_can_approve_it(): void
    {
        $order = $this->pendingOrder($this->approver);
        $other = $this->makeMember($this->org, ['view_orders', 'approve_orders']);
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/orders/{$order->id}/approve")->assertOk();
    }

    public function test_admins_self_approve_only_when_the_setting_allows_it(): void
    {
        $order = $this->pendingOrder($this->admin);
        Sanctum::actingAs($this->admin);

        $this->setAdminsCanSelfApprove(false);
        $this->postJson("/api/v1/orders/{$order->id}/approve")->assertStatus(422)->assertJsonPath('error', 'self_approval');

        $this->setAdminsCanSelfApprove(true);
        $this->postJson("/api/v1/orders/{$order->id}/approve")->assertOk();
    }
}
