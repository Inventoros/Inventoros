<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\WorkOrderController as ApiWorkOrderController;
use App\Http\Controllers\Inventory\WorkOrderController as WebWorkOrderController;
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
use App\Models\Inventory\WorkOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\TrackedStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WorkOrderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $assembly;

    private Product $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::factory()->create();
        $this->admin = User::factory()->admin()->forOrganization($organization->id)->create();
        $location = ProductLocation::factory()->create(['organization_id' => $organization->id]);
        $common = ['organization_id' => $organization->id, 'min_stock' => 0, 'location_id' => $location->id];
        $this->assembly = Product::factory()->create($common + ['type' => 'assembly', 'stock' => 0]);
        $this->component = Product::factory()->create($common + ['type' => 'standard', 'stock' => 20]);
        ProductComponent::create([
            'parent_product_id' => $this->assembly->id,
            'component_product_id' => $this->component->id,
            'quantity' => 2,
        ]);
        ProductLocationStock::create([
            'organization_id' => $organization->id,
            'product_id' => $this->component->id,
            'location_id' => $location->id,
            'quantity' => 20,
        ]);
    }

    public static function trackedProducts(): iterable
    {
        foreach (['web', 'api'] as $surface) {
            foreach (['serial', 'batch', 'variant'] as $tracking) {
                foreach (['assembly', 'component'] as $side) {
                    yield "$surface $side $tracking" => [$surface, $side, $tracking];
                }
            }
            yield "$surface component kit" => [$surface, 'component', 'kit'];
        }
    }

    /**
     * Work orders used to refuse these products because completion moved
     * only product totals and bins. They now book the serials, batches and
     * variants too, so a full create, start and complete must leave every
     * record agreeing: tracked totals match stock, bins match stock, and the
     * ledger explains every change.
     */
    #[DataProvider('trackedProducts')]
    public function test_tracked_products_complete_work_orders_with_every_record_in_step(string $surface, string $side, string $tracking): void
    {
        $this->signIn($surface);
        $organizationId = $this->admin->organization_id;
        $payload = ['product_id' => $this->assembly->id, 'quantity' => 2];
        $complete = [];
        $variant = null;

        if ($side === 'component') {
            match ($tracking) {
                'serial' => collect(range(1, 20))->each(fn ($n) => ProductSerial::create([
                    'organization_id' => $organizationId, 'product_id' => $this->component->id, 'serial_number' => "C-{$n}", 'status' => 'available',
                ])),
                'batch' => collect([12, 8])->each(fn ($qty, $n) => ProductBatch::create([
                    'organization_id' => $organizationId, 'product_id' => $this->component->id, 'batch_number' => "B-{$n}", 'quantity' => $qty,
                ])),
                default => null,
            };
            if ($tracking === 'serial' || $tracking === 'batch') {
                $this->component->update(['tracking_type' => $tracking]);
            }
            if ($tracking === 'variant') {
                $this->component->update(['has_variants' => true]);
                $variant = $this->variantOf($this->component, 10);
                $payload['component_variants'] = [['product_id' => $this->component->id, 'product_variant_id' => $variant->id]];
            }
            if ($tracking === 'kit') {
                // The assembly now takes a kit of two bolts instead of bolts.
                $kit = Product::factory()->create(['organization_id' => $organizationId, 'type' => 'kit', 'stock' => 0, 'min_stock' => 0]);
                ProductComponent::create(['parent_product_id' => $kit->id, 'component_product_id' => $this->component->id, 'quantity' => 1]);
                ProductComponent::where('parent_product_id', $this->assembly->id)->update(['component_product_id' => $kit->id]);
            }
        } else {
            match ($tracking) {
                'serial' => $complete['output'] = ['serial_numbers' => ['A-1', 'A-2']],
                'batch' => $complete['output'] = ['batch_number' => 'LOT-1', 'expiry_date' => '2033-01-01'],
                default => null,
            };
            if ($tracking === 'serial' || $tracking === 'batch') {
                $this->assembly->update(['tracking_type' => $tracking]);
            } else {
                $this->assembly->update(['has_variants' => true]);
                $variant = $this->variantOf($this->assembly, 0);
                $payload['product_variant_id'] = $variant->id;
            }
        }

        $surface === 'api'
            ? $this->postJson('/api/v1/work-orders', $payload)->assertCreated()
            : $this->post(route('work-orders.store'), $payload)->assertSessionHas('success');
        $order = WorkOrder::sole();
        foreach (['start' => [], 'complete' => $complete] as $action => $data) {
            $surface === 'api'
                ? $this->postJson("/api/v1/work-orders/{$order->id}/{$action}", $data)->assertOk()
                : $this->post(route("work-orders.{$action}", $order), $data)->assertSessionHas('success');
        }

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(2, $order->fresh()->quantity_produced);
        $this->assertSame([], app(TrackedStockReconciliationService::class)->discrepancies($organizationId)->all());

        foreach ([$this->component->fresh(), $this->assembly->fresh()] as $product) {
            $this->assertSame($product->stock, (int) ProductLocationStock::where('product_id', $product->id)->sum('quantity'), "Bins of {$product->name} match its stock.");
            $this->assertSame($product->stock - ($product->is($this->component) ? 20 : 0), (int) StockAdjustment::where('product_id', $product->id)->whereNull('product_variant_id')->sum('adjustment_quantity'), "The ledger explains {$product->name}.");
        }

        $variantOnComponent = $side === 'component' && $tracking === 'variant';
        $variantOnAssembly = $side === 'assembly' && $tracking === 'variant';
        $this->assertSame($variantOnComponent ? 20 : 16, $this->component->fresh()->stock);
        $this->assertSame($variantOnAssembly ? 0 : 2, $this->assembly->fresh()->stock);
        if ($variant !== null) {
            $this->assertSame($variantOnComponent ? 6 : 2, $variant->fresh()->stock);
            $this->assertSame($variantOnComponent ? -4 : 2, (int) StockAdjustment::where('product_variant_id', $variant->id)->sum('adjustment_quantity'));
        }
    }

    private function variantOf(Product $product, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id, 'sku' => $product->sku.'-V',
            'title' => 'Default', 'option_values' => ['Option' => 'Default'], 'stock' => $stock, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    public static function staleStarts(): iterable
    {
        foreach (['web', 'api'] as $surface) {
            foreach (['completed', 'cancelled'] as $status) {
                yield "$surface $status" => [$surface, $status];
            }
        }
    }

    #[DataProvider('staleStarts')]
    public function test_a_stale_start_cannot_reopen_finished_work(string $surface, string $status): void
    {
        $this->signIn($surface);
        $order = $this->workOrder();
        // This is the instance route binding gave the first request before
        // another request started and finished the order.
        $stale = $order->fresh();
        $order->update(['status' => 'in_progress']);
        $action = $status === 'completed' ? 'complete' : 'cancel';
        if ($surface === 'api') {
            $this->postJson("/api/v1/work-orders/{$order->id}/{$action}")->assertOk();
        } else {
            $this->post(route("work-orders.{$action}", $order))->assertSessionHas('success');
        }
        $stock = [$this->component->fresh()->stock, $this->assembly->fresh()->stock];
        $adjustments = StockAdjustment::count();

        // Resume the first request with that stale route-bound instance.
        $request = Request::create('/work-orders/'.$order->id.'/start', 'POST');
        $request->setUserResolver(fn () => $this->admin);
        $response = app($surface === 'api' ? ApiWorkOrderController::class : WebWorkOrderController::class)->start($request, $stale);

        $this->assertSame($surface === 'api' ? 422 : 302, $response->getStatusCode());
        if ($surface === 'api') {
            $this->assertSame('invalid_status', $response->getData(true)['error']);
        }
        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame($stock, [$this->component->fresh()->stock, $this->assembly->fresh()->stock]);
        $this->assertSame($adjustments, StockAdjustment::count());
    }

    private function signIn(string $surface): void
    {
        if ($surface === 'api') {
            Sanctum::actingAs($this->admin);
        } else {
            $this->actingAs($this->admin);
        }
    }

    private function workOrder(): WorkOrder
    {
        $order = WorkOrder::create([
            'organization_id' => $this->admin->organization_id,
            'product_id' => $this->assembly->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-INTEGRITY-1',
            'quantity' => 1,
            'status' => 'draft',
        ]);
        WorkOrderItem::create([
            'work_order_id' => $order->id,
            'product_id' => $this->component->id,
            'quantity_required' => 2,
            'quantity_consumed' => 0,
        ]);

        return $order;
    }
}
