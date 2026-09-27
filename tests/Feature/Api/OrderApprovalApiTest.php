<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrderApprovalStatus;
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
 * REST parity for the order approval workflow, through the same
 * OrderService::approve()/reject() as the web controller.
 */
class OrderApprovalApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org, ['stock' => 10]);
    }

    private function pendingOrder(User $creator, Product $product): Order
    {
        $this->actingAs($creator);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer',
            'status' => 'pending',
            'approval_status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10]],
        ], $creator);
        auth()->forgetGuards();

        return $order;
    }

    public function test_approve(): void
    {
        $order = $this->pendingOrder($this->admin, $this->product);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/orders/{$order->id}/approve", ['notes' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.approval_notes', 'ok');

        $this->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_processed');
    }

    public function test_reject_requires_notes_and_restocks(): void
    {
        $order = $this->pendingOrder($this->admin, $this->product);
        $this->assertSame(6, $this->product->fresh()->stock);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/orders/{$order->id}/reject")->assertStatus(422)->assertJsonValidationErrors(['notes']);

        $this->postJson("/api/v1/orders/{$order->id}/reject", ['notes' => 'Fraud check failed'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected')
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_requires_approve_orders_and_scopes_to_tenant(): void
    {
        $order = $this->pendingOrder($this->admin, $this->product);

        Sanctum::actingAs($this->makeMember($this->org, ['view_orders', 'edit_orders']));
        $this->postJson("/api/v1/orders/{$order->id}/approve")->assertForbidden();

        $other = $this->makeOrganization('Other');
        Sanctum::actingAs($this->makeAdmin($other));
        $this->postJson("/api/v1/orders/{$order->id}/approve")->assertNotFound();
        $this->assertSame(OrderApprovalStatus::PENDING, Order::withoutGlobalScopes()->find($order->id)->approval_status);
    }
}
