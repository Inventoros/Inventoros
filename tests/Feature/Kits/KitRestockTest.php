<?php

declare(strict_types=1);

namespace Tests\Feature\Kits;

use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\OrderItemKitComponent;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Every path that gives an order's stock back (cancel, reject, delete, an
 * edit, a received return) gives a kit line's components back, from the
 * snapshot recorded at sale, and never touches the kit's own stock.
 */
final class KitRestockTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $shelf;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization();
        $this->admin = $this->makeAdmin($this->org);
        $this->actingAs($this->admin);
        $this->shelf = $this->makeLocation($this->org, 'Shelf');
    }

    /**
     * A component binned on the shelf from the start, so every scenario can
     * check that stock still equals the sum of its bins.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function part(string $name, int $stock, array $attributes = []): Product
    {
        $product = $this->makeProduct($this->org, array_merge(
            ['name' => $name, 'stock' => $stock, 'location_id' => $this->shelf->id],
            $attributes,
        ));
        ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'location_id' => $product->location_id, 'quantity' => $stock,
        ]);

        return $product;
    }

    /**
     * @param  array<int, array{0: Product, 1: string|int}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function makeKit(array $parts, array $attributes = []): Product
    {
        $kit = $this->makeProduct($this->org, array_merge(
            ['type' => 'kit', 'name' => 'Starter Kit', 'stock' => 0, 'price' => 30.00],
            $attributes,
        ));
        foreach ($parts as $i => [$component, $qty]) {
            ProductComponent::create([
                'parent_product_id' => $kit->id,
                'component_product_id' => $component->id,
                'quantity' => $qty,
                'sort_order' => $i,
            ]);
        }

        return $kit;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function createOrder(array $items, array $extra = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => $items,
        ], $extra), $this->admin)->load('items');
    }

    private function sellKits(Product $kit, int $kits, array $extra = []): Order
    {
        return $this->createOrder([['product_id' => $kit->id, 'quantity' => $kits, 'unit_price' => 30.00]], $extra);
    }

    private function receiveReturn(Order $order, int $kits): ReturnOrder
    {
        $returns = app(ReturnOrderService::class);
        $return = $returns->create($this->org->id, $this->admin, [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Unwanted',
            'items' => [[
                'order_item_id' => $order->items->first()->id, 'quantity' => $kits, 'condition' => 'new', 'restock' => true,
            ]],
        ]);
        $returns->approve($return, $this->admin);

        return $returns->receive($return->fresh(), $this->admin);
    }

    private function assertBinsMatchStock(Product ...$products): void
    {
        foreach ($products as $product) {
            $this->assertSame(
                (int) Product::withTrashed()->find($product->id)->stock,
                (int) ProductLocationStock::withoutGlobalScopes()->where('product_id', $product->id)->sum('quantity'),
                "Bins of {$product->name} no longer add up to its stock.",
            );
        }
    }

    private function assertKitUntouched(Product $kit): void
    {
        $this->assertSame(0, (int) Product::withTrashed()->find($kit->id)->stock);
        $this->assertFalse(DB::table('stock_adjustments')->where('product_id', $kit->id)->exists(), 'The kit must have no ledger rows.');
    }

    /**
     * @return array<int, int> product id => sum of the ledger rows of $type
     */
    private function ledger(Order|ReturnOrder $reference, string $type): array
    {
        return DB::table('stock_adjustments')
            ->where('type', $type)
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->id)
            ->groupBy('product_id')
            ->orderBy('product_id')
            ->selectRaw('product_id, SUM(adjustment_quantity) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    public function test_cancelling_a_kit_order_returns_components(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 2);
        $this->assertSame(6, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);

        app(OrderService::class)->cancel($order);

        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame([$a->id => 4, $b->id => 2], $this->ledger($order, 'order_cancellation'));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);

        // Idempotent: a second cancel gives nothing back again.
        app(OrderService::class)->cancel($order->fresh());
        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
    }

    public function test_restock_follows_the_snapshot_not_the_current_bom(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $c = $this->part('Part C', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 1);
        $this->assertSame(8, (int) $a->fresh()->stock);

        // The BOM is edited after the sale.
        ProductComponent::where('parent_product_id', $kit->id)->where('component_product_id', $a->id)->update(['quantity' => 5]);
        ProductComponent::create(['parent_product_id' => $kit->id, 'component_product_id' => $c->id, 'quantity' => 1, 'sort_order' => 9]);

        app(OrderService::class)->cancel($order);

        $this->assertSame(10, (int) $a->fresh()->stock, 'A gets back exactly the 2 it gave.');
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame(10, (int) $c->fresh()->stock, 'C was never taken, so it gets nothing.');
        $this->assertSame([$a->id => 2, $b->id => 1], $this->ledger($order, 'order_cancellation'));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b, $c);
    }

    public function test_rejecting_a_kit_order_returns_components(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 2, ['approval_status' => 'pending']);
        $this->assertTrue($order->isPendingApproval());

        app(OrderService::class)->reject($order, $this->admin, 'No');

        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_deleting_a_kit_order_returns_components(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 2);

        DB::transaction(function () use ($order): void {
            app(OrderService::class)->restockForDeletion($order, $this->admin);
            $order->delete();
        });

        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_a_deleted_component_still_gets_its_units_back(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 2);
        $a->delete();
        $this->assertSoftDeleted($a);

        app(OrderService::class)->cancel($order);

        $this->assertSame(10, (int) Product::withTrashed()->find($a->id)->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_returning_one_of_three_kits_returns_one_kits_worth(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 3, ['status' => 'delivered']);
        $this->assertSame(4, (int) $a->fresh()->stock);
        $this->assertSame(7, (int) $b->fresh()->stock);

        $return = $this->receiveReturn($order, 1);

        $this->assertSame('received', $return->fresh()->status);
        $this->assertSame(6, (int) $a->fresh()->stock, 'A +2');
        $this->assertSame(8, (int) $b->fresh()->stock, 'B +1');
        $this->assertSame([$a->id => 2, $b->id => 1], $this->ledger($return, 'return'));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_cancelling_after_returning_one_kit_gives_back_only_the_rest(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 3, ['status' => 'delivered']);
        $this->receiveReturn($order, 1);

        // Moved back by hand like a legacy row (no shipments), then cancelled:
        // only the 2 kits still out may come back.
        DB::table('orders')->where('id', $order->id)->update(['status' => 'pending']);
        app(OrderService::class)->cancel($order->fresh());

        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame([$a->id => 4, $b->id => 2], $this->ledger($order, 'order_cancellation'));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_an_open_return_on_a_kit_line_blocks_cancelling(): void
    {
        $a = $this->part('Part A', 10);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->sellKits($kit, 2, ['status' => 'delivered']);
        app(ReturnOrderService::class)->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Unwanted',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true]],
        ]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'pending']);

        try {
            app(OrderService::class)->cancel($order->fresh());
            $this->fail('An open return should block cancelling.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('open return', $e->getMessage());
        }

        $this->assertSame(6, (int) $a->fresh()->stock);
        $this->assertKitUntouched($kit);
    }

    public function test_editing_a_kit_line_from_three_to_one_leaves_one_kits_worth_taken(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 3);
        $oldItemId = $order->items->first()->id;
        $this->assertSame(4, (int) $a->fresh()->stock);
        $this->assertSame(7, (int) $b->fresh()->stock);

        DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), [
            ['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00],
        ]));

        $this->assertSame(8, (int) $a->fresh()->stock, 'Net A -2 from the start.');
        $this->assertSame(9, (int) $b->fresh()->stock, 'Net B -1 from the start.');
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);

        $items = $order->fresh()->items;
        $this->assertCount(1, $items);
        $this->assertSame(1, (int) $items->first()->quantity);

        $this->assertSame(0, OrderItemKitComponent::where('order_item_id', $oldItemId)->count(), 'The old line\'s snapshot went with it.');
        $snapshot = OrderItemKitComponent::where('order_item_id', $items->first()->id)->orderBy('component_product_id')->get();
        $this->assertSame([$a->id, $b->id], $snapshot->pluck('component_product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([2, 1], $snapshot->pluck('quantity_per_kit')->all());
        $this->assertSame([2, 1], $snapshot->pluck('quantity_taken')->all());
        $this->assertSame(2, OrderItemKitComponent::count(), 'One snapshot row per component in total.');

        // Cancelling the edited order gives back exactly the one kit left.
        app(OrderService::class)->cancel($order->fresh());
        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
    }

    public function test_editing_a_kit_line_up_is_refused_when_a_component_runs_out(): void
    {
        $a = $this->part('Part A', 6);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->sellKits($kit, 2);
        $this->assertSame(2, (int) $a->fresh()->stock);

        // 4 kits need 8 of A; 6 exist once the old line is released.
        try {
            DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), [
                ['product_id' => $kit->id, 'quantity' => 4, 'unit_price' => 30.00],
            ]));
            $this->fail('Expected the edit to be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Part A', $e->getMessage());
        }

        $this->assertSame(2, (int) $a->fresh()->stock, 'The refused edit rolled back.');
        $this->assertSame(1, OrderItemKitComponent::count());
        $this->assertKitUntouched($kit);
    }

    public function test_an_edit_shares_one_balance_between_a_kit_and_a_plain_line(): void
    {
        $a = $this->part('Part A', 5);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->sellKits($kit, 1);

        // 2 kits (4 of A) plus 2 plain A: each fits alone, not together.
        try {
            DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), [
                ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
                ['product_id' => $a->id, 'quantity' => 2, 'unit_price' => 10.00],
            ]));
            $this->fail('Expected the edit to be refused.');
        } catch (InsufficientStockException) {
            // expected
        }
        $this->assertSame(3, (int) $a->fresh()->stock);

        // 2 kits plus 1 plain A fit exactly.
        DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), [
            ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
            ['product_id' => $a->id, 'quantity' => 1, 'unit_price' => 10.00],
        ]));
        $this->assertSame(0, (int) $a->fresh()->stock);
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a);
    }

    public function test_an_unchanged_kit_line_is_not_reshuffled_on_edit(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 2);
        $item = $order->items->first();
        $snapshotBefore = OrderItemKitComponent::orderBy('id')->get(['id', 'order_item_id', 'component_product_id', 'quantity_per_kit', 'quantity_taken'])->toArray();
        $ledgerBefore = DB::table('stock_adjustments')->count();

        DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), [
            ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => $item->unit_price],
        ]));

        $this->assertSame([$item->id], $order->fresh()->items->pluck('id')->all());
        $this->assertSame($snapshotBefore, OrderItemKitComponent::orderBy('id')->get(['id', 'order_item_id', 'component_product_id', 'quantity_per_kit', 'quantity_taken'])->toArray());
        $this->assertSame($ledgerBefore, DB::table('stock_adjustments')->count());
        $this->assertSame(6, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: int}> component id, per kit, taken
     */
    private function snapshotOf(Order $order): array
    {
        return OrderItemKitComponent::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->orderBy('order_item_id')
            ->orderBy('component_product_id')
            ->get()
            ->map(fn (OrderItemKitComponent $row) => [(int) $row->component_product_id, (int) $row->quantity_per_kit, (int) $row->quantity_taken])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function editOrder(Order $order, array $items): void
    {
        DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), $items));
    }

    public function test_an_unrelated_edit_after_a_bom_change_keeps_the_kit_line_on_its_snapshot(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->sellKits($kit, 1);
        $this->assertSame(8, (int) $a->fresh()->stock);

        // The BOM is edited for future kits only.
        ProductComponent::where('parent_product_id', $kit->id)->update(['quantity' => 5]);

        // The edit only adds an unrelated plain line; the kit line is resubmitted as is.
        $this->editOrder($order, [
            ['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00],
            ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00],
        ]);

        $this->assertSame(8, (int) $a->fresh()->stock, 'The sold kit keeps taking 2 of A, not the new BOM\'s 5.');
        $this->assertSame(9, (int) $b->fresh()->stock, 'Only the new line takes stock.');
        $this->assertSame([[$a->id, 2, 2]], $this->snapshotOf($order));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);

        // Cancelling still gives back exactly what was taken.
        app(OrderService::class)->cancel($order->fresh());
        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
    }

    public function test_resizing_a_kit_line_after_a_bom_change_keeps_the_old_composition(): void
    {
        $a = $this->part('Part A', 20);
        $b = $this->part('Part B', 10);
        $c = $this->part('Part C', 10);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->sellKits($kit, 3);
        $this->assertSame(14, (int) $a->fresh()->stock);
        $this->assertSame(7, (int) $b->fresh()->stock);

        // New kits take 5 of A and 1 of C; B is dropped.
        ProductComponent::where('parent_product_id', $kit->id)->where('component_product_id', $a->id)->update(['quantity' => 5]);
        ProductComponent::where('parent_product_id', $kit->id)->where('component_product_id', $b->id)->delete();
        ProductComponent::create(['parent_product_id' => $kit->id, 'component_product_id' => $c->id, 'quantity' => 1, 'sort_order' => 2]);

        $this->editOrder($order, [
            ['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00],
        ]);

        $this->assertSame(18, (int) $a->fresh()->stock, 'Net 1 kit at the old 2 of A.');
        $this->assertSame(9, (int) $b->fresh()->stock, 'Net 1 kit at the old 1 of B.');
        $this->assertSame(10, (int) $c->fresh()->stock, 'C was never part of the sold kit.');
        $this->assertSame([[$a->id, 2, 2], [$b->id, 1, 1]], $this->snapshotOf($order));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b, $c);
    }

    public function test_a_component_deleted_after_the_sale_allows_edits_but_not_more_kits(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->sellKits($kit, 2);
        $this->assertSame(6, (int) $a->fresh()->stock);
        $a->delete();

        // An unrelated edit goes through and the kit line keeps its stock.
        $this->editOrder($order, [
            ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
            ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00],
        ]);
        $this->assertSame(6, (int) Product::withTrashed()->find($a->id)->stock);
        $this->assertSame(9, (int) $b->fresh()->stock);
        $this->assertSame([[$a->id, 2, 4]], $this->snapshotOf($order));

        // More kits than the order already had cannot be built from a deleted part.
        $itemsBefore = $order->fresh()->items->pluck('id')->all();
        $ledgerBefore = DB::table('stock_adjustments')->count();
        try {
            $this->editOrder($order, [
                ['product_id' => $kit->id, 'quantity' => 3, 'unit_price' => 30.00],
                ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00],
            ]);
            $this->fail('Expected the edit to be refused.');
        } catch (InvalidOrderItemException $e) {
            $this->assertStringContainsString('Starter Kit', $e->getMessage());
            $this->assertStringContainsString('Part A', $e->getMessage());
        }
        $this->assertSame(6, (int) Product::withTrashed()->find($a->id)->stock);
        $this->assertSame(9, (int) $b->fresh()->stock);
        $this->assertSame($itemsBefore, $order->fresh()->items->pluck('id')->all());
        $this->assertSame($ledgerBefore, DB::table('stock_adjustments')->count());
        $this->assertSame([[$a->id, 2, 4]], $this->snapshotOf($order));

        // Fewer kits are fine: the deleted part's units come back.
        $this->editOrder($order, [
            ['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00],
        ]);
        $this->assertSame(8, (int) Product::withTrashed()->find($a->id)->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame([[$a->id, 2, 2]], $this->snapshotOf($order));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_a_kit_new_to_the_order_in_an_edit_uses_the_current_bom(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $order = $this->createOrder([['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00]]);

        $kit = $this->makeKit([[$a, 2]]);
        ProductComponent::where('parent_product_id', $kit->id)->update(['quantity' => 3]);

        $this->editOrder($order, [
            ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00],
            ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
        ]);

        $this->assertSame(4, (int) $a->fresh()->stock, '2 kits at the current 3 of A.');
        $this->assertSame(9, (int) $b->fresh()->stock);
        $this->assertSame([[$a->id, 3, 6]], $this->snapshotOf($order));

        // A new kit is held to the sellability rules: a deleted component refuses it.
        $c = $this->part('Part C', 10);
        $other = $this->makeKit([[$c, 1]], ['name' => 'Travel Kit']);
        $c->delete();
        try {
            $this->editOrder($order, [
                ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00],
                ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
                ['product_id' => $other->id, 'quantity' => 1, 'unit_price' => 30.00],
            ]);
            $this->fail('Expected the edit to be refused.');
        } catch (InvalidOrderItemException $e) {
            $this->assertStringContainsString('Travel Kit', $e->getMessage());
            $this->assertStringContainsString('deleted', $e->getMessage());
        }
        $this->assertSame(4, (int) $a->fresh()->stock);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_two_kits_sharing_a_component_draw_on_one_balance_at_creation(): void
    {
        $a = $this->part('Part A', 5);
        $b = $this->part('Part B', 10);
        $k1 = $this->makeKit([[$a, 2]], ['name' => 'Kit One']);
        $k2 = $this->makeKit([[$a, 1], [$b, 1]], ['name' => 'Kit Two']);

        // 2 x K1 (4 A) plus 2 x K2 (2 A) need 6 of A; 5 exist.
        try {
            $this->createOrder([
                ['product_id' => $k1->id, 'quantity' => 2, 'unit_price' => 30.00],
                ['product_id' => $k2->id, 'quantity' => 2, 'unit_price' => 30.00],
            ]);
            $this->fail('Expected the order to be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Part A', $e->getMessage());
        }
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(5, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame(0, OrderItemKitComponent::withoutGlobalScopes()->count());
        $this->assertFalse(DB::table('stock_adjustments')->where('type', 'order_fulfillment')->exists());

        // 2 x K1 plus 1 x K2 need exactly 5.
        $this->createOrder([
            ['product_id' => $k1->id, 'quantity' => 2, 'unit_price' => 30.00],
            ['product_id' => $k2->id, 'quantity' => 1, 'unit_price' => 30.00],
        ]);
        $this->assertSame(0, (int) $a->fresh()->stock);
        $this->assertSame(9, (int) $b->fresh()->stock);
        $this->assertKitUntouched($k1);
        $this->assertKitUntouched($k2);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_two_kits_sharing_a_component_draw_on_one_balance_in_an_edit(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $k1 = $this->makeKit([[$a, 2]], ['name' => 'Kit One']);
        $k2 = $this->makeKit([[$a, 1], [$b, 1]], ['name' => 'Kit Two']);

        $order = $this->createOrder([
            ['product_id' => $k1->id, 'quantity' => 2, 'unit_price' => 30.00],
            ['product_id' => $k2->id, 'quantity' => 2, 'unit_price' => 30.00],
        ]);
        $this->assertSame(4, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);
        $snapshotBefore = $this->snapshotOf($order);
        $ledgerBefore = DB::table('stock_adjustments')->count();

        // 5 x K1 (10 A) plus 2 x K2 (2 A) need 12 of A; 10 exist once released.
        try {
            $this->editOrder($order, [
                ['product_id' => $k1->id, 'quantity' => 5, 'unit_price' => 30.00],
                ['product_id' => $k2->id, 'quantity' => 2, 'unit_price' => 30.00],
            ]);
            $this->fail('Expected the edit to be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Part A', $e->getMessage());
        }
        $this->assertSame(4, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);
        $this->assertSame($snapshotBefore, $this->snapshotOf($order));
        $this->assertSame($ledgerBefore, DB::table('stock_adjustments')->count());

        // 4 x K1 (8 A) plus 2 x K2 (2 A) use all 10.
        $this->editOrder($order, [
            ['product_id' => $k1->id, 'quantity' => 4, 'unit_price' => 30.00],
            ['product_id' => $k2->id, 'quantity' => 2, 'unit_price' => 30.00],
        ]);
        $this->assertSame(0, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);
        $this->assertSame([[$a->id, 2, 8], [$a->id, 1, 2], [$b->id, 1, 2]], $this->snapshotOf($order));
        $this->assertKitUntouched($k1);
        $this->assertKitUntouched($k2);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_a_restricted_user_cannot_cancel_a_kit_whose_component_is_in_another_warehouse(): void
    {
        $east = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'East', 'code' => 'EAST', 'priority' => 0, 'is_active' => true]);
        $west = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'West', 'code' => 'WEST', 'priority' => 0, 'is_active' => true]);
        $eastShelf = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'E-1', 'code' => 'E-1', 'warehouse_id' => $east->id, 'is_active' => true]);
        $westShelf = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'W-1', 'code' => 'W-1', 'warehouse_id' => $west->id, 'is_active' => true]);

        $a = $this->part('Part A', 10, ['location_id' => $eastShelf->id]);
        $b = $this->part('Part B', 10, ['location_id' => $westShelf->id]);
        // The kit itself sits in East, which the clerk can reach.
        $kit = $this->makeKit([[$a, 2], [$b, 1]], ['location_id' => $eastShelf->id]);
        $order = $this->sellKits($kit, 2);

        $clerk = $this->makeMember($this->org);
        $clerk->warehouses()->attach($east->id);

        try {
            app(OrderService::class)->cancel($order, $clerk);
            $this->fail('A clerk without West must not move West stock.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(6, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);
        $this->assertNotSame('cancelled', $order->fresh()->status->value);
        $this->assertKitUntouched($kit);
    }

    public function test_a_receiver_restricted_to_one_warehouse_gets_kit_components_back_when_one_also_has_stock_elsewhere(): void
    {
        $east = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'East', 'code' => 'EAST', 'priority' => 0, 'is_active' => true]);
        $west = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'West', 'code' => 'WEST', 'priority' => 0, 'is_active' => true]);
        $eastShelf = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'E-1', 'code' => 'E-1', 'warehouse_id' => $east->id, 'is_active' => true]);
        $westShelf = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'W-1', 'code' => 'W-1', 'warehouse_id' => $west->id, 'is_active' => true]);

        // A lives in East but also holds 5 units in a West bin.
        $a = $this->part('Part A', 10, ['location_id' => $eastShelf->id]);
        ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $a->id, 'location_id' => $westShelf->id, 'quantity' => 5,
        ]);
        $a->update(['stock' => 15]);
        $b = $this->part('Part B', 10, ['location_id' => $eastShelf->id]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]], ['location_id' => $eastShelf->id]);

        $order = $this->sellKits($kit, 1, ['status' => 'delivered']);
        $this->assertSame(13, (int) $a->fresh()->stock);
        $this->assertSame(9, (int) $b->fresh()->stock);
        $this->assertGreaterThan(0, (int) ProductLocationStock::withoutGlobalScopes()
            ->where('product_id', $a->id)->where('location_id', $westShelf->id)->value('quantity'));

        $clerk = $this->makeMember($this->org);
        $clerk->warehouses()->attach($east->id);

        $returns = app(ReturnOrderService::class);
        $return = $returns->create($this->org->id, $this->admin, [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Unwanted',
            'items' => [[
                'order_item_id' => $order->items->first()->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true,
            ]],
        ]);
        $returns->approve($return, $this->admin);

        // Like a plain return, only the location the units are booked into
        // (each component's own, in East) is checked, not every bin.
        $returns->receive($return->fresh(), $clerk);

        $this->assertSame('received', $return->fresh()->status);
        $this->assertSame(15, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame([$a->id => 2, $b->id => 1], $this->ledger($return, 'return'));
        $this->assertKitUntouched($kit);
        $this->assertBinsMatchStock($a, $b);
    }

    public function test_an_edit_that_re_takes_a_kit_whose_component_became_serial_tracked_is_refused(): void
    {
        $a = $this->part('Part A', 10);
        $b = $this->part('Part B', 10);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->sellKits($kit, 1);
        $this->assertSame(8, (int) $a->fresh()->stock);

        // A is switched to serial tracking after the sale.
        Product::whereKey($a->id)->update(['tracking_type' => TrackingType::SERIAL->value]);

        $itemIds = $order->items()->pluck('id')->all();
        $ledgerRows = DB::table('stock_adjustments')->count();
        $snapshot = $this->snapshotOf($order);
        $bins = ProductLocationStock::withoutGlobalScopes()->orderBy('id')->pluck('quantity', 'id')->all();

        try {
            $this->editOrder($order, [
                ['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00],
                ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10.00],
            ]);
            $this->fail('Re-taking a kit whose component is now tracked must be refused.');
        } catch (InvalidOrderItemException $e) {
            $this->assertSame(
                'Kit "Starter Kit" contains "Part A", which is now tracked by serial or batch; remove the kit line and add it again.',
                $e->getMessage(),
            );
        }

        $this->assertSame(8, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame($itemIds, $order->items()->pluck('id')->all());
        $this->assertSame($snapshot, $this->snapshotOf($order));
        $this->assertSame($ledgerRows, DB::table('stock_adjustments')->count());
        $this->assertSame($bins, ProductLocationStock::withoutGlobalScopes()->orderBy('id')->pluck('quantity', 'id')->all());
        $this->assertKitUntouched($kit);

        // A header-only edit (the lines resubmitted unchanged) still works.
        $this->editOrder($order, [['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00]]);
        $this->assertSame($itemIds, $order->items()->pluck('id')->all());
        $this->assertSame(8, (int) $a->fresh()->stock);
    }
}
