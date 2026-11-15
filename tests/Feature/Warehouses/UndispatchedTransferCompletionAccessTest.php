<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Services\ProductLocationStockService;
use App\Services\StockReservationService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Completing a transfer that was never shipped moves stock out of the source
 * bin, so (like shipping) it needs access to the source warehouse. Receiving
 * a shipped transfer still needs only the destination.
 */
final class UndispatchedTransferCompletionAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    /** Assigned to warehouse B only: the destination end of an A to B move. */
    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        $this->receiver = $this->unassigned;
        $this->warehouseB->users()->attach($this->receiver->id);
    }

    private function transferAToB(): StockTransfer
    {
        return app(StockTransferService::class)->create($this->organization->id, $this->admin, [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => 15]],
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function bins(): array
    {
        $stock = app(ProductLocationStockService::class);

        return [
            $stock->quantityAt($this->productA, $this->locationA->id),
            $stock->quantityAt($this->productA, $this->locationB->id),
        ];
    }

    public function test_destination_only_user_cannot_complete_a_pending_transfer_on_the_web(): void
    {
        $transfer = $this->transferAToB();

        $this->actingAs($this->receiver)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertForbidden();

        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame([60, 40], $this->bins());
    }

    public function test_destination_only_user_cannot_complete_a_prepared_transfer_over_the_api(): void
    {
        $transfer = $this->transferAToB();
        app(StockTransferService::class)->prepare($transfer, $this->admin);

        Sanctum::actingAs($this->receiver, ['*']);
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/complete')->assertForbidden();

        $this->assertSame('prepared', $transfer->fresh()->status);
        $this->assertSame([60, 40], $this->bins());
        $this->assertSame(45, app(StockReservationService::class)->available($this->productA, locationId: $this->locationA->id));
    }

    public function test_source_user_can_complete_an_undispatched_transfer(): void
    {
        $transfer = $this->transferAToB();

        $this->actingAs($this->restricted)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame([45, 55], $this->bins());
    }

    public function test_admin_can_complete_an_undispatched_transfer(): void
    {
        $transfer = $this->transferAToB();
        app(StockTransferService::class)->prepare($transfer, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame([45, 55], $this->bins());
    }

    public function test_show_page_tells_a_destination_only_user_they_cannot_dispatch_an_unwarehoused_source(): void
    {
        // A move from a bin outside any warehouse is not inter-warehouse, so
        // the page offers one-step completion; that needs the source end.
        $loose = ProductLocation::create([
            'organization_id' => $this->organization->id, 'warehouse_id' => null,
            'name' => 'Loose Bin', 'code' => 'LOOSE-1', 'is_active' => true,
        ]);
        $transfer = $this->makeTransfer($loose, $this->locationA, $this->productA);
        $this->assertFalse((bool) $transfer->is_inter_warehouse);

        $this->actingAs($this->restricted)
            ->get(route('stock-transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('StockTransfers/Show')
                ->where('canDispatch', false)
                ->where('canReceive', true));

        $this->actingAs($this->restricted)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertForbidden();

        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_show_page_only_offers_one_step_completion_to_the_source_end(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/StockTransfers/Show.vue'));

        $this->assertMatchesRegularExpression(
            "/const canComplete = computed\\(\\(\\) => [^;]*props\\.canDispatch[^;]*\\['pending', 'prepared'\\]\\.includes\\(props\\.transfer\\.status\\)/s",
            $source,
            'The pending/prepared Complete button must require source access (canDispatch).',
        );
    }

    public function test_receiving_a_shipped_transfer_still_needs_only_the_destination(): void
    {
        $transfer = $this->transferAToB();
        app(StockTransferService::class)->ship($transfer, $this->restricted);

        $this->actingAs($this->restricted)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertForbidden();

        $this->actingAs($this->receiver)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame([45, 55], $this->bins());
    }
}
