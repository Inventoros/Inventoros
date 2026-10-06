<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\WorkOrderController as ApiWorkOrderController;
use App\Http\Controllers\Inventory\WorkOrderController as WebWorkOrderController;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
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

    public static function unsupportedProducts(): iterable
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

    #[DataProvider('unsupportedProducts')]
    public function test_tracked_products_cannot_create_start_or_complete_work_orders(string $surface, string $side, string $tracking): void
    {
        $this->signIn($surface);
        $product = $side === 'assembly' ? $this->assembly : $this->component;
        $product->update(match ($tracking) {
            'variant' => ['has_variants' => true],
            'kit' => ['type' => 'kit'],
            default => ['tracking_type' => $tracking],
        });

        $create = $surface === 'api'
            ? $this->postJson('/api/v1/work-orders', ['product_id' => $this->assembly->id, 'quantity' => 1])
            : $this->post(route('work-orders.store'), ['product_id' => $this->assembly->id, 'quantity' => 1]);

        if ($surface === 'api') {
            $create->assertUnprocessable()->assertJsonPath('error', 'unsupported_tracking');
        } else {
            $create->assertRedirect()->assertSessionHas('error');
        }
        $this->assertSame(0, WorkOrder::count());

        // Existing work orders also need the guard: products can acquire
        // tracking after the order was created or started.
        $order = $this->workOrder();
        $this->assertActionRefused($surface, $order, 'start');
        $this->assertSame('draft', $order->fresh()->status);
        $order->update(['status' => 'in_progress']);
        $this->assertActionRefused($surface, $order, 'complete');
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->quantity_produced);
        $this->assertSame(0, (int) $order->items()->value('quantity_consumed'));
        $this->assertSame(20, $this->component->fresh()->stock);
        $this->assertSame(0, $this->assembly->fresh()->stock);
        $this->assertSame(20, (int) ProductLocationStock::sum('quantity'));
        $this->assertSame(0, StockAdjustment::count());
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

    private function assertActionRefused(string $surface, WorkOrder $order, string $action): void
    {
        if ($surface === 'api') {
            $this->postJson("/api/v1/work-orders/{$order->id}/{$action}")
                ->assertUnprocessable()->assertJsonPath('error', 'unsupported_tracking');
        } else {
            $this->post(route("work-orders.{$action}", $order))->assertRedirect()->assertSessionHas('error');
        }
    }
}
