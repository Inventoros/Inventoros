<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Services\ReturnOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staff can change each line's restock flag and condition on a pending or
 * approved return before it is received, through ReturnOrderService on both
 * web and REST. The change is warehouse-access checked (a line books stock
 * into its product's location) and written to the activity log.
 */
class ReturnLineAdjustmentTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockPermissions[] = 'manage_returns';
        $this->setUpWarehouseAccess();
    }

    private function pendingReturn(Product $product, bool $restock = true, string $condition = 'new'): ReturnOrder
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
        $item = $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'quantity' => 3, 'unit_price' => 10, 'subtotal' => 30, 'tax' => 0, 'total' => 30,
        ]);

        return app(ReturnOrderService::class)->create($this->organization->id, $this->admin, [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Damaged',
            'items' => [[
                'order_item_id' => $item->id,
                'quantity' => 2,
                'condition' => $condition,
                'restock' => $restock,
            ]],
        ]);
    }

    public function test_web_staff_change_restock_and_condition_on_a_pending_return(): void
    {
        $return = $this->pendingReturn($this->productA, restock: false, condition: 'damaged');
        $line = $return->items()->first();

        $this->actingAs($this->admin)
            ->patch(route('returns.items.update', $return), [
                'items' => [['id' => $line->id, 'restock' => true, 'condition' => 'used']],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $line->refresh();
        $this->assertTrue((bool) $line->restock);
        $this->assertSame('used', $line->condition);

        $log = ActivityLog::where('action', 'return.lines_updated')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(ReturnOrder::class, $log->subject_type);
        $this->assertSame($return->id, $log->subject_id);
        // assertEquals: MySQL's JSON type stores object keys in its own
        // order (shortest first), so "new" comes back before "old".
        $this->assertEquals(
            ['restock' => ['old' => false, 'new' => true], 'condition' => ['old' => 'damaged', 'new' => 'used']],
            $log->properties['lines'][$line->id],
        );
    }

    public function test_the_changed_restock_flag_drives_what_receive_restocks(): void
    {
        $return = $this->pendingReturn($this->productA, restock: true);
        $line = $return->items()->first();
        $stockBefore = (int) $this->productA->fresh()->stock;

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/v1/returns/{$return->id}/approve")->assertOk();

        $this->patchJson("/api/v1/returns/{$return->id}/items", [
            'items' => [['id' => $line->id, 'restock' => false]],
        ])
            ->assertOk()
            ->assertJsonPath('data.items.0.restock', false)
            ->assertJsonPath('data.items.0.condition', 'new');

        $this->postJson("/api/v1/returns/{$return->id}/receive")->assertOk();
        $this->assertSame($stockBefore, (int) $this->productA->fresh()->stock);
    }

    public function test_lines_cannot_change_once_received_or_rejected(): void
    {
        $received = $this->pendingReturn($this->productA);
        $service = app(ReturnOrderService::class);
        // Receiving books a stock adjustment attributed to the signed-in user.
        $this->actingAs($this->admin);
        $service->approve($received, $this->admin);
        $service->receive($received, $this->admin);

        $rejected = $this->pendingReturn($this->productA);
        $service->reject($rejected, $this->admin, 'No');

        Sanctum::actingAs($this->admin, ['*']);
        foreach ([$received, $rejected] as $return) {
            $line = $return->items()->first();
            $this->patchJson("/api/v1/returns/{$return->id}/items", [
                'items' => [['id' => $line->id, 'restock' => ! $line->restock]],
            ])->assertStatus(422)->assertJsonPath('error', 'invalid_status');

            $this->assertSame((bool) $line->restock, (bool) $line->fresh()->restock);
        }

        $this->actingAs($this->admin)
            ->patch(route('returns.items.update', $received), [
                'items' => [['id' => $received->items()->first()->id, 'restock' => false]],
            ])
            ->assertSessionHas('error');
        $this->assertSame(0, ActivityLog::where('action', 'return.lines_updated')->count());
    }

    public function test_a_line_from_another_return_is_rejected(): void
    {
        $mine = $this->pendingReturn($this->productA);
        $other = $this->pendingReturn($this->productA);
        $otherLine = $other->items()->first();

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/v1/returns/{$mine->id}/items", [
            'items' => [['id' => $otherLine->id, 'restock' => false]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.id');

        $this->assertTrue((bool) $otherLine->fresh()->restock);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $return = $this->pendingReturn($this->productA);
        $line = $return->items()->first();

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/v1/returns/{$return->id}/items", [
            'items' => [['id' => $line->id, 'condition' => 'shredded']],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.condition');

        $this->patchJson("/api/v1/returns/{$return->id}/items", ['items' => []])
            ->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_restricted_staff_cannot_change_lines_restocking_into_another_warehouse(): void
    {
        $theirs = $this->pendingReturn($this->productB);
        $mine = $this->pendingReturn($this->productA);

        Sanctum::actingAs($this->restricted, ['*']);
        $this->patchJson("/api/v1/returns/{$theirs->id}/items", [
            'items' => [['id' => $theirs->items()->first()->id, 'restock' => false]],
        ])->assertForbidden();
        $this->assertTrue((bool) $theirs->items()->first()->restock);

        $this->patchJson("/api/v1/returns/{$mine->id}/items", [
            'items' => [['id' => $mine->items()->first()->id, 'restock' => false]],
        ])->assertOk();

        $this->actingAs($this->restricted)
            ->patch(route('returns.items.update', $theirs), [
                'items' => [['id' => $theirs->items()->first()->id, 'condition' => 'used']],
            ])
            ->assertForbidden();
        $this->assertSame('new', $theirs->items()->first()->condition);
    }

    public function test_staff_without_manage_returns_cannot_change_lines(): void
    {
        $return = $this->pendingReturn($this->productA);
        $line = $return->items()->first();

        // The fixture's unassigned clerk role, minus manage_returns.
        $this->unassigned->roles()->first()->update([
            'permissions' => array_values(array_diff($this->stockPermissions, ['manage_returns'])),
        ]);
        $this->unassigned->refresh();

        $this->actingAs($this->unassigned)
            ->patch(route('returns.items.update', $return), ['items' => [['id' => $line->id, 'restock' => false]]])
            ->assertForbidden();

        $this->assertTrue((bool) $line->fresh()->restock);
    }
}
