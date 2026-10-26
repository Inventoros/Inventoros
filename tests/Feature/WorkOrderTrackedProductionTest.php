<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\WorkOrderController as ApiWorkOrderController;
use App\Http\Controllers\Inventory\WorkOrderController as WebWorkOrderController;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\StartWorkOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderAllocation;
use App\Models\Inventory\WorkOrderItem;
use App\Models\Inventory\WorkOrderOutput;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Work orders that consume or produce variants, serial-tracked and
 * batch-tracked products, and that consume virtual kits.
 *
 * Every success case checks the whole stock picture (totals, bins, variant
 * stock, serial status, batch quantity, ledger rows and the allocation /
 * output records) and every refusal checks that nothing moved at all.
 */
final class WorkOrderTrackedProductionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private ProductLocation $location;

    private Product $assembly;

    private Product $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::factory()->create();
        $this->admin = User::factory()->admin()->forOrganization($this->organization->id)->create();
        $this->location = ProductLocation::factory()->create(['organization_id' => $this->organization->id]);
        $this->assembly = $this->product(['type' => 'assembly', 'stock' => 0, 'name' => 'Widget']);
        $this->component = $this->product(['stock' => 20, 'name' => 'Bolt']);
        $this->bom($this->assembly, $this->component, 2);
    }

    public static function surfaces(): iterable
    {
        yield 'web' => ['web'];
        yield 'api' => ['api'];
    }

    // ==================== VARIANTS ====================

    #[DataProvider('surfaces')]
    public function test_a_variant_component_is_consumed_from_the_chosen_variant(string $surface): void
    {
        $this->component->update(['has_variants' => true]);
        $red = $this->variant($this->component, 'Red', 10);
        $blue = $this->variant($this->component, 'Blue', 10);

        $this->createOrder($surface, [
            'quantity' => 3,
            'component_variants' => [['product_id' => $this->component->id, 'product_variant_id' => $blue->id]],
        ]);
        $order = WorkOrder::sole();
        $this->assertSame($blue->id, $order->items()->sole()->product_variant_id);

        $this->ok($this->act($surface, $order, 'start'));
        $this->ok($this->act($surface, $order, 'complete'));

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(4, $blue->fresh()->stock);
        $this->assertSame(10, $red->fresh()->stock);
        // The parent total and its bin are untouched: variant stock has no bins.
        $this->assertSame(20, $this->component->fresh()->stock);
        $this->assertSame(20, $this->binQuantity($this->component));
        $consumption = StockAdjustment::where('type', 'assembly_consumption')->sole();
        $this->assertSame($blue->id, $consumption->product_variant_id);
        $this->assertSame(-6, $consumption->adjustment_quantity);
        $this->assertSame(3, $this->assembly->fresh()->stock);
        $this->assertSame(3, $this->binQuantity($this->assembly));
    }

    #[DataProvider('surfaces')]
    public function test_a_variant_component_needs_a_variant(string $surface): void
    {
        $this->component->update(['has_variants' => true]);
        $this->variant($this->component, 'Red', 10);

        $response = $this->createOrder($surface, ['quantity' => 1]);

        $this->assertRefused($surface, $response, 'variant_required');
        $this->assertSame(0, WorkOrder::count());
    }

    #[DataProvider('surfaces')]
    public function test_a_variant_of_another_product_is_refused(string $surface): void
    {
        $this->component->update(['has_variants' => true]);
        $this->variant($this->component, 'Red', 10);
        $stranger = $this->variant($this->product(['name' => 'Other']), 'Green', 10);

        $response = $this->createOrder($surface, [
            'quantity' => 1,
            'component_variants' => [['product_id' => $this->component->id, 'product_variant_id' => $stranger->id]],
        ]);

        $this->assertRefused($surface, $response, 'invalid_variant');
        $this->assertSame(0, WorkOrder::count());
    }

    #[DataProvider('surfaces')]
    public function test_the_output_can_be_a_variant(string $surface): void
    {
        $this->assembly->update(['has_variants' => true]);
        $large = $this->variant($this->assembly, 'Large', 1);

        $this->assertRefused($surface, $this->createOrder($surface, ['quantity' => 2]), 'variant_required');
        $this->assertSame(0, WorkOrder::count());

        $this->createOrder($surface, ['quantity' => 2, 'product_variant_id' => $large->id]);
        $order = WorkOrder::sole();
        $this->ok($this->act($surface, $order, 'start'));
        $this->ok($this->act($surface, $order, 'complete'));

        $this->assertSame(3, $large->fresh()->stock);
        $this->assertSame(0, $this->assembly->fresh()->stock);
        $this->assertSame(0, $this->binQuantity($this->assembly));
        $this->assertSame(16, $this->component->fresh()->stock);
        $output = WorkOrderOutput::sole();
        $this->assertSame([$large->id, 2], [$output->product_variant_id, $output->quantity]);
        $this->assertSame($large->id, StockAdjustment::where('type', 'assembly_production')->sole()->product_variant_id);
    }

    public function test_a_component_that_gains_variants_after_creation_is_refused_at_completion(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createOrder('api', ['quantity' => 1])->assertCreated();
        $order = WorkOrder::sole();
        $this->act('api', $order, 'start')->assertOk();
        $this->component->update(['has_variants' => true]);
        $this->variant($this->component, 'Red', 10);
        $before = $this->snapshot();

        $this->assertRefused('api', $this->act('api', $order, 'complete'), 'variant_required');

        $this->assertSame($before, $this->snapshot());
    }

    // ==================== SERIAL-TRACKED COMPONENTS ====================

    #[DataProvider('surfaces')]
    public function test_chosen_serials_are_consumed(string $surface): void
    {
        [$s1, $s2, $s3, $s4] = $this->serialComponent(4);
        $order = $this->startedOrder($surface, 2);
        $item = $order->items()->sole();

        $this->act($surface, $order, 'complete', [
            'allocations' => [['work_order_item_id' => $item->id, 'serial_ids' => [$s4->id, $s2->id, $s1->id, $s3->id]]],
        ]);
        $this->assertSame('in_progress', $order->fresh()->status, 'Four serials for two units is refused.');

        $this->ok($this->act($surface, $order, 'complete', [
            'allocations' => [['work_order_item_id' => $item->id, 'serial_ids' => [$s4->id, $s2->id]]],
        ]));

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(
            [$s1->id => 'available', $s2->id => 'consumed', $s3->id => 'available', $s4->id => 'consumed'],
            ProductSerial::orderBy('id')->pluck('status', 'id')->all(),
        );
        $this->assertSame(2, $this->component->fresh()->stock);
        $this->assertSame(2, $this->binQuantity($this->component));
        $this->assertEqualsCanonicalizing(
            [$s2->id, $s4->id],
            WorkOrderAllocation::where('work_order_item_id', $item->id)->pluck('product_serial_id')->all(),
        );
        $this->assertSame(2, (int) WorkOrderAllocation::sum('quantity'));
    }

    #[DataProvider('surfaces')]
    public function test_serials_are_allocated_oldest_first_when_none_are_chosen(string $surface): void
    {
        [$s1, $s2, $s3] = $this->serialComponent(3);
        $order = $this->startedOrder($surface, 2);

        $this->ok($this->act($surface, $order, 'complete'));

        $this->assertSame(
            [$s1->id => 'consumed', $s2->id => 'consumed', $s3->id => 'available'],
            ProductSerial::orderBy('id')->pluck('status', 'id')->all(),
        );
        $this->assertSame(1, $this->component->fresh()->stock);
        $this->assertSame(2, $this->assembly->fresh()->stock);
    }

    public static function badSerialChoices(): iterable
    {
        foreach (['web', 'api'] as $surface) {
            foreach (['too_few', 'not_available', 'other_product', 'other_organization', 'unknown', 'duplicate'] as $case) {
                yield "$surface $case" => [$surface, $case];
            }
        }
    }

    #[DataProvider('badSerialChoices')]
    public function test_a_bad_serial_choice_changes_nothing(string $surface, string $case): void
    {
        [$s1, $s2, $s3] = $this->serialComponent(3);
        $order = $this->startedOrder($surface, 2);
        $ids = match ($case) {
            'too_few' => [$s1->id],
            'not_available' => [$s1->id, tap($s3)->update(['status' => 'sold'])->id],
            'other_product' => [$s1->id, $this->serialFor($this->product(['tracking_type' => 'serial']), 'OTHER-1')->id],
            'other_organization' => [$s1->id, $this->serialFor($this->foreignProduct(), 'FOREIGN-1')->id],
            'unknown' => [$s1->id, 999999],
            'duplicate' => [$s1->id, $s1->id],
        };
        $before = $this->snapshot();

        $response = $this->act($surface, $order, 'complete', [
            'allocations' => [['work_order_item_id' => $order->items()->value('id'), 'serial_ids' => $ids]],
        ]);

        $this->assertRefused($surface, $response);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    #[DataProvider('surfaces')]
    public function test_too_few_tracked_serials_refuse_start_and_completion(string $surface): void
    {
        // Twenty on hand but only one serial on record.
        $this->component->update(['tracking_type' => 'serial']);
        $this->serialFor($this->component, 'ONLY-1');
        $order = $this->draftOrder(1);
        $before = $this->snapshot();

        $this->assertRefused($surface, $this->act($surface, $order, 'start'), 'insufficient_stock');
        $this->assertSame($before, $this->snapshot());

        $order->update(['status' => 'in_progress']);
        $before = $this->snapshot();
        $this->assertRefused($surface, $this->act($surface, $order, 'complete'), 'insufficient_stock');
        $this->assertSame($before, $this->snapshot());
    }

    // ==================== BATCH-TRACKED COMPONENTS ====================

    #[DataProvider('surfaces')]
    public function test_batches_are_consumed_first_expiry_first_when_none_are_chosen(string $surface): void
    {
        $this->component->update(['tracking_type' => 'batch', 'stock' => 9]);
        $this->setBin($this->component, 9);
        $late = $this->batch($this->component, 'LATE', 4, '2031-01-01');
        $undated = $this->batch($this->component, 'UNDATED', 3, null);
        $soon = $this->batch($this->component, 'SOON', 2, '2030-01-01');
        $order = $this->startedOrder($surface, 2);

        $this->ok($this->act($surface, $order, 'complete'));

        $this->assertSame([0, 2, 3], [$soon->fresh()->quantity, $late->fresh()->quantity, $undated->fresh()->quantity]);
        $this->assertSame(5, $this->component->fresh()->stock);
        $this->assertSame(5, $this->binQuantity($this->component));
        $this->assertSame(
            [$soon->id => 2, $late->id => 2],
            WorkOrderAllocation::orderBy('id')->pluck('quantity', 'product_batch_id')->all(),
        );
    }

    #[DataProvider('surfaces')]
    public function test_chosen_batches_are_consumed(string $surface): void
    {
        $this->component->update(['tracking_type' => 'batch', 'stock' => 9]);
        $first = $this->batch($this->component, 'A', 5, '2030-01-01');
        $second = $this->batch($this->component, 'B', 4, '2031-01-01');
        $order = $this->startedOrder($surface, 2);

        $this->ok($this->act($surface, $order, 'complete', ['allocations' => [[
            'work_order_item_id' => $order->items()->value('id'),
            'batches' => [['product_batch_id' => $second->id, 'quantity' => 3], ['product_batch_id' => $first->id, 'quantity' => 1]],
        ]]]));

        $this->assertSame([4, 1], [$first->fresh()->quantity, $second->fresh()->quantity]);
        $this->assertSame(5, $this->component->fresh()->stock);
        $this->assertSame(4, (int) WorkOrderAllocation::sum('quantity'));
    }

    public static function badBatchChoices(): iterable
    {
        foreach (['web', 'api'] as $surface) {
            foreach (['too_few', 'too_many', 'over_batch', 'other_organization', 'held'] as $case) {
                yield "$surface $case" => [$surface, $case];
            }
        }
    }

    #[DataProvider('badBatchChoices')]
    public function test_a_bad_batch_choice_changes_nothing(string $surface, string $case): void
    {
        $this->component->update(['tracking_type' => 'batch', 'stock' => 9]);
        $first = $this->batch($this->component, 'A', 5, '2030-01-01');
        $second = $this->batch($this->component, 'B', 4, '2031-01-01');
        $order = $this->startedOrder($surface, 2);
        $choice = match ($case) {
            'too_few' => [[$first->id, 3]],
            'too_many' => [[$first->id, 5]],
            'over_batch' => [[$second->id, 4]],
            'other_organization' => [[$first->id, 2], [$this->batch($this->foreignProduct(), 'F', 9, null)->id, 2]],
            'held' => [[$first->id, 4]],
        };
        if ($case === 'over_batch') {
            $second->update(['quantity' => 3]);
        }
        if ($case === 'held') {
            add_filter('batch_allocatable_quantity', fn ($qty, $batch) => $batch->id === $first->id ? 1 : $qty);
        }
        $before = $this->snapshot();

        $response = $this->act($surface, $order, 'complete', ['allocations' => [[
            'work_order_item_id' => $order->items()->value('id'),
            'batches' => array_map(fn ($row) => ['product_batch_id' => $row[0], 'quantity' => $row[1]], $choice),
        ]]]);

        $this->assertRefused($surface, $response);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_held_batch_is_skipped_by_automatic_allocation(): void
    {
        Sanctum::actingAs($this->admin);
        $this->component->update(['tracking_type' => 'batch', 'stock' => 9]);
        $held = $this->batch($this->component, 'HELD', 5, '2030-01-01');
        $free = $this->batch($this->component, 'FREE', 4, '2031-01-01');
        add_filter('batch_allocatable_quantity', fn ($qty, $batch) => $batch->id === $held->id ? 0 : $qty);
        $order = $this->startedOrder('api', 2);

        $this->act('api', $order, 'complete')->assertOk();

        $this->assertSame([5, 0], [$held->fresh()->quantity, $free->fresh()->quantity]);
    }

    // ==================== TRACKED OUTPUT ====================

    #[DataProvider('surfaces')]
    public function test_serial_output_creates_in_stock_serials(string $surface): void
    {
        $this->assembly->update(['tracking_type' => 'serial']);
        $order = $this->startedOrder($surface, 3);

        $this->ok($this->act($surface, $order, 'complete', [
            'quantity_produced' => 2,
            'output' => ['serial_numbers' => ['WID-001', 'WID-002']],
        ]));

        $serials = ProductSerial::where('product_id', $this->assembly->id)->orderBy('serial_number')->get();
        $this->assertSame(['WID-001', 'WID-002'], $serials->pluck('serial_number')->all());
        $this->assertSame(['available', 'available'], $serials->pluck('status')->all());
        $this->assertSame(2, $this->assembly->fresh()->stock);
        $this->assertSame(2, $this->binQuantity($this->assembly));
        $this->assertEqualsCanonicalizing($serials->pluck('id')->all(), WorkOrderOutput::pluck('product_serial_id')->all());
        // 2 of 3 units: ceil(6 * 2/3) = 4 bolts.
        $this->assertSame(16, $this->component->fresh()->stock);
    }

    public static function badOutputSerials(): iterable
    {
        foreach (['web', 'api'] as $surface) {
            foreach (['missing', 'too_few', 'too_many', 'repeated', 'taken'] as $case) {
                yield "$surface $case" => [$surface, $case];
            }
        }
    }

    #[DataProvider('badOutputSerials')]
    public function test_bad_output_serials_change_nothing(string $surface, string $case): void
    {
        $this->assembly->update(['tracking_type' => 'serial']);
        $this->serialFor($this->product(['tracking_type' => 'serial']), 'TAKEN');
        $order = $this->startedOrder($surface, 2);
        $numbers = match ($case) {
            'missing' => null,
            'too_few' => ['A'],
            'too_many' => ['A', 'B', 'C'],
            'repeated' => ['A', 'A'],
            'taken' => ['A', 'TAKEN'],
        };
        $before = $this->snapshot();

        $response = $this->act($surface, $order, 'complete', $numbers === null ? [] : ['output' => ['serial_numbers' => $numbers]]);

        $this->assertRefused($surface, $response);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('surfaces')]
    public function test_batch_output_goes_into_a_new_named_batch(string $surface): void
    {
        $this->assembly->update(['tracking_type' => 'batch']);
        $order = $this->startedOrder($surface, 3);

        $this->ok($this->act($surface, $order, 'complete', [
            'output' => ['batch_number' => 'LOT-7', 'expiry_date' => '2032-05-01'],
        ]));

        $batch = ProductBatch::where('product_id', $this->assembly->id)->sole();
        $this->assertSame(['LOT-7', 3, '2032-05-01'], [$batch->batch_number, $batch->quantity, $batch->expiry_date->toDateString()]);
        $this->assertSame(3, $this->assembly->fresh()->stock);
        $this->assertSame([$batch->id, 3], [WorkOrderOutput::sole()->product_batch_id, WorkOrderOutput::sole()->quantity]);
    }

    public function test_batch_output_tops_up_an_existing_batch_or_generates_a_number(): void
    {
        Sanctum::actingAs($this->admin);
        $this->assembly->update(['tracking_type' => 'batch']);
        $existing = $this->batch($this->assembly, 'LOT-1', 4, '2032-01-01');
        $this->assembly->update(['stock' => 4]);

        $order = $this->startedOrder('api', 2);
        $this->act('api', $order, 'complete', ['output' => ['batch_number' => 'LOT-1']])->assertOk();
        $this->assertSame(6, $existing->fresh()->quantity);

        // A different expiry is a different lot: refused, nothing moves.
        $second = $this->startedOrder('api', 1, 'WO-2');
        $before = $this->snapshot();
        $this->assertRefused('api', $this->act('api', $second, 'complete', [
            'output' => ['batch_number' => 'LOT-1', 'expiry_date' => '2040-01-01'],
        ]), 'batch_expiry_mismatch');
        $this->assertSame($before, $this->snapshot());

        $this->act('api', $second, 'complete')->assertOk();
        $generated = ProductBatch::where('product_id', $this->assembly->id)->whereKeyNot($existing->id)->sole();
        $this->assertStringStartsWith('BATCH-', $generated->batch_number);
        $this->assertSame(1, $generated->quantity);
        $this->assertSame(7, $this->assembly->fresh()->stock);
    }

    // ==================== KITS ====================

    #[DataProvider('surfaces')]
    public function test_a_kit_component_is_exploded_into_its_components(string $surface): void
    {
        $nut = $this->product(['stock' => 30, 'name' => 'Nut']);
        $washer = $this->product(['stock' => 30, 'name' => 'Washer']);
        $kit = $this->product(['type' => 'kit', 'stock' => 0, 'name' => 'Fixing kit']);
        $this->bom($kit, $nut, 2);
        $this->bom($kit, $washer, 1);
        $this->bom($this->assembly, $kit, 3);

        $this->createOrder($surface, ['quantity' => 2]);
        $order = WorkOrder::sole();
        $items = $order->items()->orderBy('product_id')->get();
        $this->assertSame(
            [[$this->component->id, null, 4.0], [$nut->id, $kit->id, 12.0], [$washer->id, $kit->id, 6.0]],
            $items->map(fn ($i) => [$i->product_id, $i->kit_product_id, (float) $i->quantity_required])->all(),
        );

        $this->ok($this->act($surface, $order, 'start'));
        $this->ok($this->act($surface, $order, 'complete'));

        $this->assertSame([16, 18, 24, 0], [
            $this->component->fresh()->stock, $nut->fresh()->stock, $washer->fresh()->stock, $kit->fresh()->stock,
        ]);
        $this->assertSame(18, $this->binQuantity($nut));
        $this->assertSame(0, StockAdjustment::where('product_id', $kit->id)->count());
    }

    #[DataProvider('surfaces')]
    public function test_a_kit_cannot_be_produced(string $surface): void
    {
        $kit = $this->product(['type' => 'kit', 'stock' => 0]);
        $this->bom($kit, $this->component, 1);

        $response = $surface === 'api'
            ? $this->signedIn('api')->postJson('/api/v1/work-orders', ['product_id' => $kit->id, 'quantity' => 1])
            : $this->signedIn('web')->post(route('work-orders.store'), ['product_id' => $kit->id, 'quantity' => 1]);

        if ($surface === 'api') {
            $response->assertUnprocessable()->assertJsonPath('error', 'invalid_product_type');
        } else {
            $response->assertRedirect()->assertSessionHasErrors('product_id');
        }
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_a_component_that_became_a_kit_after_creation_is_refused(): void
    {
        $order = $this->draftOrder(1);
        $order->update(['status' => 'in_progress']);
        $this->component->update(['type' => 'kit']);
        $before = $this->snapshot();

        $this->assertRefused('api', $this->act('api', $order, 'complete'), 'unsupported_kit');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_deleted_component_refuses_completion(): void
    {
        $order = $this->startedOrder('api', 1);
        $this->component->delete();
        $before = $this->snapshot();

        $this->assertRefused('api', $this->act('api', $order, 'complete'), 'invalid_product');
        $this->assertSame($before, $this->snapshot());
    }

    // ==================== ATOMICITY, STALENESS, CANCEL ====================

    #[DataProvider('surfaces')]
    public function test_a_shortfall_on_one_line_rolls_back_every_line(string $surface): void
    {
        [$s1, $s2] = $this->serialComponent(2);
        $glue = $this->product(['tracking_type' => 'batch', 'stock' => 10, 'name' => 'Glue']);
        $this->batch($glue, 'G1', 10, null);
        $this->bom($this->assembly, $glue, 1);
        $this->assembly->update(['tracking_type' => 'serial']);
        $order = $this->startedOrder($surface, 1);
        // The batch drains after the order started.
        ProductBatch::query()->update(['quantity' => 0]);
        $before = $this->snapshot();

        $response = $this->act($surface, $order, 'complete', ['output' => ['serial_numbers' => ['W-1']]]);

        $this->assertRefused($surface, $response, 'insufficient_stock');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['available', 'available'], [$s1->fresh()->status, $s2->fresh()->status]);
        $this->assertSame(0, ProductSerial::where('product_id', $this->assembly->id)->count());
    }

    public static function staleTransitions(): iterable
    {
        foreach (['web', 'api'] as $surface) {
            foreach (['complete', 'cancel'] as $action) {
                yield "$surface $action" => [$surface, $action];
            }
        }
    }

    #[DataProvider('staleTransitions')]
    public function test_a_stale_request_cannot_move_tracked_stock_twice(string $surface, string $action): void
    {
        [$s1, $s2, $s3, $s4] = $this->serialComponent(4);
        $order = $this->startedOrder($surface, 1);
        $stale = $order->fresh();
        $this->ok($this->act($surface, $order, 'complete'));
        $before = $this->snapshot();

        $request = Request::create("/work-orders/{$order->id}/{$action}", 'POST', [
            'allocations' => [['work_order_item_id' => $order->items()->value('id'), 'serial_ids' => [$s3->id, $s4->id]]],
        ]);
        $request->setUserResolver(fn () => $this->admin);
        $this->app->instance('request', $request);
        $controller = app($surface === 'api' ? ApiWorkOrderController::class : WebWorkOrderController::class);
        $response = $controller->{$action}($request, $stale);

        $this->assertSame($surface === 'api' ? 422 : 302, $response->getStatusCode());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(['available', 'available'], [$s3->fresh()->status, $s4->fresh()->status]);
    }

    #[DataProvider('surfaces')]
    public function test_cancelling_a_started_tracked_order_moves_nothing(string $surface): void
    {
        $this->serialComponent(3);
        $order = $this->startedOrder($surface, 1);
        $before = $this->snapshot();

        $this->ok($this->act($surface, $order, 'cancel'));

        $this->assertSame('cancelled', $order->fresh()->status);
        $snapshot = $this->snapshot();
        unset($before['orders'], $snapshot['orders']);
        $this->assertSame($before, $snapshot);
    }

    public function test_cancelling_reverses_recorded_consumption_including_serials_batches_and_variants(): void
    {
        // A work order cannot reach "in progress" with consumption through
        // the app any more (stock moves only on completion), but older data
        // can; cancelling must return exactly what each line recorded.
        Sanctum::actingAs($this->admin);
        $serialPart = $this->product(['tracking_type' => 'serial', 'stock' => 1, 'name' => 'Chip']);
        $serial = tap($this->serialFor($serialPart, 'CHIP-1'))->update(['status' => 'consumed']);
        $batchPart = $this->product(['tracking_type' => 'batch', 'stock' => 3, 'name' => 'Resin']);
        $batch = $this->batch($batchPart, 'R1', 3, null);
        $variantPart = $this->product(['has_variants' => true, 'name' => 'Cap']);
        $cap = $this->variant($variantPart, 'Black', 5);
        $order = WorkOrder::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->assembly->id,
            'created_by' => $this->admin->id, 'work_order_number' => 'WO-LEGACY', 'quantity' => 1, 'status' => 'in_progress',
        ]);
        $serialLine = $order->items()->create(['product_id' => $serialPart->id, 'quantity_required' => 1, 'quantity_consumed' => 1]);
        $batchLine = $order->items()->create(['product_id' => $batchPart->id, 'quantity_required' => 2, 'quantity_consumed' => 2]);
        $order->items()->create(['product_id' => $variantPart->id, 'product_variant_id' => $cap->id, 'quantity_required' => 2, 'quantity_consumed' => 2]);
        $this->allocation($order, $serialLine, ['product_serial_id' => $serial->id, 'quantity' => 1]);
        $this->allocation($order, $batchLine, ['product_batch_id' => $batch->id, 'quantity' => 2]);

        $this->act('api', $order, 'cancel')->assertOk();

        $this->assertSame('available', $serial->fresh()->status);
        $this->assertSame(5, $batch->fresh()->quantity);
        $this->assertSame([2, 5, 7], [$serialPart->fresh()->stock, $batchPart->fresh()->stock, $cap->fresh()->stock]);
        $this->assertSame(0, WorkOrderAllocation::count());
        $this->assertSame(3, StockAdjustment::where('type', 'assembly_reversal')->count());
    }

    // ==================== OTHER SURFACES ====================

    public function test_the_mcp_start_tool_uses_the_same_checks(): void
    {
        $this->component->update(['tracking_type' => 'serial']);
        $this->serialFor($this->component, 'ONLY-1');
        $order = $this->draftOrder(1);

        InventorosServer::actingAs($this->admin)
            ->tool(StartWorkOrderTool::class, ['id' => $order->id])
            ->assertHasErrors();
        $this->assertSame('draft', $order->fresh()->status);

        $this->serialFor($this->component, 'ONLY-2');
        InventorosServer::actingAs($this->admin)
            ->tool(StartWorkOrderTool::class, ['id' => $order->id])
            ->assertOk();
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_the_api_shows_allocations_and_outputs(): void
    {
        [$s1] = $this->serialComponent(2);
        $this->assembly->update(['tracking_type' => 'serial']);
        $order = $this->startedOrder('api', 1);
        $this->act('api', $order, 'complete', ['output' => ['serial_numbers' => ['OUT-1']]])->assertOk();

        $this->getJson("/api/v1/work-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.allocations.0.serial.serial_number', $s1->serial_number)
            ->assertJsonPath('data.outputs.0.serial.serial_number', 'OUT-1');
    }

    public function test_the_create_page_lists_exploded_kit_lines_and_variants(): void
    {
        $nut = $this->product(['stock' => 30, 'name' => 'Nut', 'has_variants' => true]);
        $steel = $this->variant($nut, 'Steel', 7);
        $kit = $this->product(['type' => 'kit', 'name' => 'Fixing kit']);
        $this->bom($kit, $nut, 2);
        $this->bom($this->assembly, $kit, 3);
        $this->assembly->update(['has_variants' => true, 'tracking_type' => 'serial']);
        $large = $this->variant($this->assembly, 'Large', 0);

        $this->signedIn('web')->get(route('work-orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkOrders/Create')
                ->where('assemblyProducts.0.variants.0.id', $large->id)
                ->where('assemblyProducts.0.tracking_type', 'serial')
                ->where('assemblyProducts.0.components.0.product_id', $this->component->id)
                ->where('assemblyProducts.0.components.1.product_id', $nut->id)
                ->where('assemblyProducts.0.components.1.quantity', 6)
                ->where('assemblyProducts.0.components.1.kit.id', $kit->id)
                ->where('assemblyProducts.0.components.1.variants.0.id', $steel->id)
            );
    }

    public function test_the_show_page_offers_the_serials_and_batches_to_allocate(): void
    {
        [$s1, $s2] = $this->serialComponent(2);
        $glue = $this->product(['tracking_type' => 'batch', 'stock' => 5, 'name' => 'Glue']);
        $lot = $this->batch($glue, 'G1', 5, '2030-01-01');
        $this->bom($this->assembly, $glue, 1);
        $this->assembly->update(['tracking_type' => 'batch']);
        // PostgreSQL can move this tuple behind the later component on
        // UPDATE. Equal BOM positions must still retain creation order.
        $this->assembly->components()->orderBy('id')->firstOrFail()->update(['notes' => 'Updated earlier component']);
        $order = $this->startedOrder('web', 1);

        $this->get(route('work-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkOrders/Show')
                ->where('outputTrackingType', 'batch')
                ->where('items.0.tracking_type', 'serial')
                ->where('items.0.available_serials.0.id', $s1->id)
                ->where('items.0.available_serials.1.id', $s2->id)
                ->where('items.1.tracking_type', 'batch')
                ->where('items.1.available_batches.0.id', $lot->id)
            );
    }

    // ==================== HELPERS ====================

    private function product(array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes + [
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'stock' => 0,
            'min_stock' => 0,
            'location_id' => $this->location->id,
            'tracking_type' => 'none',
        ]);

        if ($product->stock > 0) {
            $this->setBin($product, $product->stock);
        }

        return $product;
    }

    private function foreignProduct(): Product
    {
        return Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'stock' => 9, 'min_stock' => 0, 'tracking_type' => 'serial', 'location_id' => null,
        ]);
    }

    private function bom(Product $parent, Product $child, float $quantity): void
    {
        ProductComponent::create(['parent_product_id' => $parent->id, 'component_product_id' => $child->id, 'quantity' => $quantity]);
    }

    private function variant(Product $product, string $name, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id, 'sku' => "{$product->sku}-{$name}",
            'title' => $name, 'option_values' => ['Option' => $name], 'stock' => $stock, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    /** @return list<ProductSerial> */
    private function serialComponent(int $count): array
    {
        $this->component->update(['tracking_type' => 'serial', 'stock' => $count]);
        $this->setBin($this->component, $count);
        $this->bomQuantity(1);

        return array_map(fn ($n) => $this->serialFor($this->component, "BOLT-{$n}"), range(1, $count));
    }

    private function bomQuantity(float $quantity): void
    {
        ProductComponent::where('parent_product_id', $this->assembly->id)
            ->where('component_product_id', $this->component->id)
            ->update(['quantity' => $quantity]);
    }

    private function serialFor(Product $product, string $number): ProductSerial
    {
        return ProductSerial::withoutGlobalScopes()->create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'serial_number' => $number, 'status' => 'available',
        ]);
    }

    private function batch(Product $product, string $number, int $quantity, ?string $expiry): ProductBatch
    {
        return ProductBatch::withoutGlobalScopes()->create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'batch_number' => $number, 'quantity' => $quantity, 'expiry_date' => $expiry,
        ]);
    }

    private function allocation(WorkOrder $order, WorkOrderItem $item, array $attributes): void
    {
        WorkOrderAllocation::create($attributes + [
            'organization_id' => $order->organization_id, 'work_order_id' => $order->id,
            'work_order_item_id' => $item->id, 'product_id' => $item->product_id,
        ]);
    }

    private function setBin(Product $product, int $quantity): void
    {
        ProductLocationStock::updateOrCreate(
            ['product_id' => $product->id, 'location_id' => $this->location->id],
            ['organization_id' => $product->organization_id, 'quantity' => $quantity],
        );
    }

    private function binQuantity(Product $product): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->sum('quantity');
    }

    private function signedIn(string $surface): static
    {
        if ($surface === 'api') {
            Sanctum::actingAs($this->admin);
        } else {
            $this->actingAs($this->admin);
        }

        return $this;
    }

    private function createOrder(string $surface, array $payload): TestResponse
    {
        $payload += ['product_id' => $this->assembly->id];

        return $surface === 'api'
            ? $this->signedIn('api')->postJson('/api/v1/work-orders', $payload)
            : $this->signedIn('web')->post(route('work-orders.store'), $payload);
    }

    private function act(string $surface, WorkOrder $order, string $action, array $payload = []): TestResponse
    {
        return $surface === 'api'
            ? $this->signedIn('api')->postJson("/api/v1/work-orders/{$order->id}/{$action}", $payload)
            : $this->signedIn('web')->post(route("work-orders.{$action}", $order), $payload);
    }

    private function draftOrder(int $quantity, string $number = 'WO-1'): WorkOrder
    {
        $order = WorkOrder::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->assembly->id,
            'created_by' => $this->admin->id, 'work_order_number' => $number, 'quantity' => $quantity, 'status' => 'draft',
        ]);

        foreach ($this->assembly->components()->get() as $line) {
            $order->items()->create([
                'product_id' => $line->component_product_id,
                'quantity_required' => $line->quantity * $quantity,
                'quantity_consumed' => 0,
            ]);
        }

        return $order;
    }

    private function startedOrder(string $surface, int $quantity, string $number = 'WO-1'): WorkOrder
    {
        $order = $this->draftOrder($quantity, $number);
        $this->ok($this->act($surface, $order, 'start'));
        $this->assertSame('in_progress', $order->fresh()->status);

        return $order;
    }

    /**
     * A web success is a redirect flashing success and no error; an API
     * success is any 2xx.
     */
    private function ok(TestResponse $response): void
    {
        if ($response->isRedirect()) {
            $response->assertSessionHas('success')->assertSessionMissing('error')->assertSessionHasNoErrors();

            return;
        }

        $response->assertSuccessful();
    }

    /**
     * A web refusal is a redirect carrying an error (flash or validation);
     * an API refusal is a 422, with $code as its error when given.
     */
    private function assertRefused(string $surface, TestResponse $response, ?string $code = null): void
    {
        if ($surface === 'api') {
            $response->assertUnprocessable();
            if ($code !== null) {
                $response->assertJsonPath('error', $code);
            }

            return;
        }

        $response->assertRedirect();
        $session = $response->baseResponse->getSession();
        $this->assertTrue(
            $session->has('error') || $session->has('errors'),
            'Expected the web response to carry an error.',
        );
    }

    /**
     * Everything a work order can move, so a refusal can be shown to have
     * moved none of it.
     */
    private function snapshot(): array
    {
        return [
            'products' => DB::table('products')->orderBy('id')->pluck('stock', 'id')->all(),
            'variants' => DB::table('product_variants')->orderBy('id')->pluck('stock', 'id')->all(),
            'bins' => DB::table('product_location_stocks')->orderBy('id')->pluck('quantity', 'id')->all(),
            'serials' => DB::table('product_serials')->orderBy('id')->get(['id', 'product_id', 'serial_number', 'status'])->map(fn ($r) => (array) $r)->all(),
            'batches' => DB::table('product_batches')->orderBy('id')->get(['id', 'batch_number', 'quantity'])->map(fn ($r) => (array) $r)->all(),
            'adjustments' => DB::table('stock_adjustments')->count(),
            'allocations' => DB::table('work_order_allocations')->count(),
            'outputs' => DB::table('work_order_outputs')->count(),
            'orders' => DB::table('work_orders')->orderBy('id')->get(['id', 'status', 'quantity_produced'])->map(fn ($r) => (array) $r)->all(),
            'consumed' => DB::table('work_order_items')->orderBy('id')->pluck('quantity_consumed', 'id')->map(fn ($q) => (float) $q)->all(),
        ];
    }
}
