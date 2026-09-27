<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * REST parity for returns (RMA): create/approve/receive/complete/reject run
 * through the same ReturnOrderService as the web controller.
 */
class ReturnOrderApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();

        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org, ['stock' => 20]);
        $this->order = $this->makeOrder($this->admin, $this->product, 5);
    }

    private function makeOrder(User $creator, Product $product, int $quantity): Order
    {
        $this->actingAs($creator);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer',
            'status' => 'delivered',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10]],
        ], $creator);
        auth()->forgetGuards();

        return $order->load('items');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $quantity = 2, bool $restock = true): array
    {
        return [
            'order_id' => $this->order->id,
            'type' => 'return',
            'reason' => 'Damaged in transit',
            'items' => [[
                'order_item_id' => $this->order->items->first()->id,
                'quantity' => $quantity,
                'condition' => 'new',
                'restock' => $restock,
            ]],
        ];
    }

    public function test_full_lifecycle_restocks_on_receive(): void
    {
        Sanctum::actingAs($this->admin);
        $stockBefore = $this->product->fresh()->stock; // 15 after the sale

        $id = $this->postJson('/api/v1/returns', $this->payload(2))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.product_id', $this->product->id)
            ->json('data.id');

        $this->postJson("/api/v1/returns/{$id}/receive")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_status');

        $this->postJson("/api/v1/returns/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/returns/{$id}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame($stockBefore + 2, $this->product->fresh()->stock);

        // A second receive must not double-restock.
        $this->postJson("/api/v1/returns/{$id}/receive")->assertStatus(422);
        $this->assertSame($stockBefore + 2, $this->product->fresh()->stock);

        $this->postJson("/api/v1/returns/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_reject_appends_reason(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/returns', $this->payload())->json('data.id');

        $this->postJson("/api/v1/returns/{$id}/reject", ['notes' => 'Outside window'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertStringContainsString('Outside window', ReturnOrder::find($id)->notes);
    }

    public function test_cannot_return_more_than_ordered(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/returns', $this->payload(6))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_lists_and_shows_own_returns(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/returns', $this->payload())->json('data.id');

        $this->getJson('/api/v1/returns?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->getJson("/api/v1/returns/{$id}")
            ->assertOk()
            ->assertJsonPath('data.order.id', $this->order->id);
    }

    public function test_cross_tenant_ids_are_rejected(): void
    {
        $other = $this->makeOrganization('Other');
        $otherAdmin = $this->makeAdmin($other);
        $foreignOrder = $this->makeOrder($otherAdmin, $this->makeProduct($other), 2);

        Sanctum::actingAs($otherAdmin);
        $foreignReturnId = $this->postJson('/api/v1/returns', [
            'order_id' => $foreignOrder->id,
            'type' => 'return',
            'reason' => 'x',
            'items' => [['order_item_id' => $foreignOrder->items->first()->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true]],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin);

        // A foreign order id in the payload is a validation error, not a 500/404.
        $this->postJson('/api/v1/returns', array_merge($this->payload(), ['order_id' => $foreignOrder->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_id']);

        // A foreign return is invisible.
        $this->getJson("/api/v1/returns/{$foreignReturnId}")->assertNotFound();
        $this->postJson("/api/v1/returns/{$foreignReturnId}/approve")->assertNotFound();
        $this->assertSame('pending', ReturnOrder::find($foreignReturnId)->status);
    }

    public function test_requires_manage_returns(): void
    {
        Sanctum::actingAs($this->makeMember($this->org, ['view_orders']));

        $this->getJson('/api/v1/returns')->assertForbidden();
        $this->postJson('/api/v1/returns', $this->payload())->assertForbidden();
    }
}
