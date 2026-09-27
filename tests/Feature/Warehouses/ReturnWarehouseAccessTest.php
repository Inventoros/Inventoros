<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Services\ReturnOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Returns are scoped to warehouses by where their goods go back to: each
 * line restocks into its product's primary location. A restricted user sees
 * and acts on a return when any of its lines comes back to their
 * warehouses, and can only raise or receive returns whose restocked lines
 * all land in their warehouses. The same rule applies on web, REST and
 * GraphQL because ReturnOrderService enforces it.
 */
class ReturnWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    private Order $orderA;

    private Order $orderB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockPermissions[] = 'manage_returns';
        $this->setUpWarehouseAccess();

        $this->orderA = $this->deliveredOrder($this->productA);
        $this->orderB = $this->deliveredOrder($this->productB);
    }

    private function deliveredOrder(Product $product): Order
    {
        $order = Order::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-'.uniqid(),
            'customer_name' => 'Buyer',
            'status' => 'delivered',
            'subtotal' => 30, 'tax' => 0, 'shipping' => 0, 'total' => 30,
            'currency' => 'USD',
            'order_date' => now(),
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'quantity' => 3, 'unit_price' => 10, 'subtotal' => 30, 'tax' => 0, 'total' => 30,
        ]);

        return $order->load('items');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Damaged',
            'items' => [[
                'order_item_id' => $order->items->first()->id,
                'quantity' => 1,
                'condition' => 'new',
                'restock' => true,
            ]],
        ];
    }

    private function returnAsAdmin(Order $order): ReturnOrder
    {
        return app(ReturnOrderService::class)->create($this->organization->id, $this->admin, $this->payload($order));
    }

    public function test_rest_lists_and_shows_only_returns_coming_back_to_own_warehouses(): void
    {
        $mine = $this->returnAsAdmin($this->orderA);
        $theirs = $this->returnAsAdmin($this->orderB);
        Sanctum::actingAs($this->restricted, ['*']);

        $this->getJson('/api/v1/returns')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson("/api/v1/returns/{$theirs->id}")->assertForbidden();
    }

    public function test_rest_cannot_raise_or_act_on_a_return_into_another_warehouse(): void
    {
        $theirs = $this->returnAsAdmin($this->orderB);
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson('/api/v1/returns', $this->payload($this->orderB))->assertForbidden();
        $this->assertSame(1, ReturnOrder::count());

        $this->postJson("/api/v1/returns/{$theirs->id}/approve")->assertForbidden();
        $this->assertSame('pending', $theirs->fresh()->status);

        // Their own warehouse is fine.
        $this->postJson('/api/v1/returns', $this->payload($this->orderA))->assertCreated();
    }

    public function test_receiving_is_refused_when_a_restocked_line_lands_elsewhere(): void
    {
        $theirs = $this->returnAsAdmin($this->orderB);
        app(ReturnOrderService::class)->approve($theirs, $this->admin);
        $stockBefore = $this->productB->fresh()->stock;

        Sanctum::actingAs($this->restricted, ['*']);
        $this->postJson("/api/v1/returns/{$theirs->id}/receive")->assertForbidden();

        $this->assertSame('approved', $theirs->fresh()->status);
        $this->assertSame($stockBefore, $this->productB->fresh()->stock);
    }

    public function test_web_index_and_show_are_scoped(): void
    {
        $mine = $this->returnAsAdmin($this->orderA);
        $theirs = $this->returnAsAdmin($this->orderB);

        $this->actingAs($this->restricted)
            ->get(route('returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('returns.data', 1)
                ->where('returns.data.0.id', $mine->id));

        $this->actingAs($this->restricted)->get(route('returns.show', $theirs))->assertForbidden();
        $this->actingAs($this->restricted)->post(route('returns.approve', $theirs))->assertForbidden();
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_graphql_is_scoped(): void
    {
        $mine = $this->returnAsAdmin($this->orderA);
        $theirs = $this->returnAsAdmin($this->orderB);
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson('/graphql', ['query' => '{ returnOrders { id } }'])
            ->assertJsonCount(1, 'data.returnOrders')
            ->assertJsonPath('data.returnOrders.0.id', $mine->id);

        $this->assertNotEmpty($this->postJson('/graphql', ['query' => "{ returnOrder(id: {$theirs->id}) { id } }"])->json('errors'));
        $this->assertNotEmpty($this->postJson('/graphql', ['query' => "mutation { approveReturnOrder(id: {$theirs->id}) { id } }"])->json('errors'));
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_unrestricted_users_see_everything(): void
    {
        $this->returnAsAdmin($this->orderA);
        $this->returnAsAdmin($this->orderB);

        Sanctum::actingAs($this->unassigned, ['*']);
        $this->getJson('/api/v1/returns')->assertOk()->assertJsonCount(2, 'data');
    }
}
