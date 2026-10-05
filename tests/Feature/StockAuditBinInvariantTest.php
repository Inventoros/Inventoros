<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Completing a stock audit keeps the per-location bins in step with
 * products.stock: SUM(bins) never exceeds the on-hand total.
 *
 * A location-scoped audit counts one bin, so its variance lands in that bin.
 * An organization-wide audit counts the whole product: a shortfall drains the
 * bins in the order fulfilment uses (warehouse priority, then the primary
 * location, then the fullest bin) and an overage lands in the primary bin.
 */
final class StockAuditBinInvariantTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $primary;

    private ProductLocation $back;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Bins Co');
        $this->admin = $this->makeAdmin($this->org);
        $this->primary = $this->makeLocation($this->org, 'Front');
        $this->back = $this->makeLocation($this->org, 'Back');
    }

    /**
     * A product with 10 on hand: 6 in its primary bin and 4 in the back bin.
     */
    private function splitProduct(int $primaryQty = 6, int $backQty = 4): Product
    {
        $product = $this->makeProduct($this->org, ['stock' => $primaryQty + $backQty, 'location_id' => $this->primary->id]);
        $this->bin($product, $this->primary, $primaryQty);
        $this->bin($product, $this->back, $backQty);

        return $product;
    }

    private function bin(Product $product, ProductLocation $location, int $quantity): void
    {
        ProductLocationStock::query()->withoutGlobalScopes()->updateOrCreate(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['organization_id' => $this->org->id, 'quantity' => $quantity],
        );
    }

    private function binQty(Product $product, ProductLocation $location): int
    {
        return (int) ProductLocationStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function assertBinsMatchStock(Product $product): void
    {
        $stock = (int) Product::withoutGlobalScopes()->whereKey($product->id)->value('stock');
        $bins = (int) ProductLocationStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->sum('quantity');

        $this->assertSame($stock, $bins, "SUM(bins) {$bins} must equal products.stock {$stock}");
    }

    /**
     * Create, start, count and complete an audit through the service.
     *
     * @param  array<int, int>  $counts  product id => counted quantity
     */
    private function audit(array $counts, ?ProductLocation $location = null): StockAudit
    {
        $audits = app(StockAuditService::class);

        $audit = $audits->create($this->org->id, $this->admin, [
            'name' => 'Count',
            'audit_type' => $location ? 'location' : 'full',
            'warehouse_location_id' => $location?->id,
            'product_ids' => $location ? null : array_keys($counts),
        ]);
        $audits->start($audit, $this->admin);

        foreach ($audit->items()->get() as $item) {
            if (array_key_exists($item->product_id, $counts)) {
                $audits->recordCount($audit, $item, $this->admin, $counts[$item->product_id]);
            }
        }

        $audits->complete($audit->fresh(), $this->admin, allowUncounted: true);

        return $audit->fresh('items');
    }

    public function test_an_organization_wide_shortfall_drains_bins_in_fulfilment_order(): void
    {
        $product = $this->splitProduct();

        $this->audit([$product->id => 7]);

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(3, $this->binQty($product, $this->primary));
        $this->assertSame(4, $this->binQty($product, $this->back));
        $this->assertBinsMatchStock($product);
    }

    public function test_an_organization_wide_shortfall_larger_than_the_primary_bin_spills_into_the_next(): void
    {
        $product = $this->splitProduct();

        $this->audit([$product->id => 2]);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $this->binQty($product, $this->primary));
        $this->assertSame(2, $this->binQty($product, $this->back));
        $this->assertBinsMatchStock($product);
    }

    public function test_an_organization_wide_shortfall_drains_the_higher_priority_warehouse_first(): void
    {
        $preferred = \App\Models\Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Preferred', 'code' => 'PRF', 'priority' => 10, 'is_active' => true]);
        $this->back->update(['warehouse_id' => $preferred->id]);
        $product = $this->splitProduct();

        $this->audit([$product->id => 8]);

        $this->assertSame(6, $this->binQty($product, $this->primary));
        $this->assertSame(2, $this->binQty($product, $this->back));
        $this->assertBinsMatchStock($product);
    }

    public function test_an_organization_wide_overage_lands_in_the_primary_bin(): void
    {
        $product = $this->splitProduct();

        $this->audit([$product->id => 13]);

        $this->assertSame(13, $product->fresh()->stock);
        $this->assertSame(9, $this->binQty($product, $this->primary));
        $this->assertSame(4, $this->binQty($product, $this->back));
        $this->assertBinsMatchStock($product);
    }

    public function test_an_organization_wide_audit_of_an_unbinned_product_seeds_its_bin_first(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10, 'location_id' => $this->primary->id]);
        ProductLocationStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->delete();

        $this->audit([$product->id => 8]);

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(8, $this->binQty($product, $this->primary));
        $this->assertBinsMatchStock($product);
    }

    public function test_a_location_audit_counts_and_corrects_only_that_bin(): void
    {
        $product = $this->splitProduct();

        $audit = $this->audit([$product->id => 1], $this->back);

        $item = $audit->items->sole();
        $this->assertSame($this->back->id, $item->location_id);
        // The snapshot is what the bin held, not the product's total.
        $this->assertSame(4, $item->system_quantity);
        $this->assertSame(-3, $item->discrepancy);

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(6, $this->binQty($product, $this->primary));
        $this->assertSame(1, $this->binQty($product, $this->back));
        $this->assertBinsMatchStock($product);
    }

    public function test_a_location_audit_overage_raises_that_bin(): void
    {
        $product = $this->splitProduct();

        $this->audit([$product->id => 9], $this->primary);

        $this->assertSame(13, $product->fresh()->stock);
        $this->assertSame(9, $this->binQty($product, $this->primary));
        $this->assertSame(4, $this->binQty($product, $this->back));
        $this->assertBinsMatchStock($product);
    }

    public function test_a_location_audit_of_an_unbinned_product_snapshots_its_whole_stock(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10, 'location_id' => $this->primary->id]);
        ProductLocationStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->delete();

        $audit = $this->audit([$product->id => 7], $this->primary);

        $this->assertSame(10, $audit->items->sole()->system_quantity);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(7, $this->binQty($product, $this->primary));
        $this->assertBinsMatchStock($product);
    }

    public function test_a_location_audit_with_no_difference_changes_nothing(): void
    {
        $product = $this->splitProduct();

        $this->audit([$product->id => 4], $this->back);

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(6, $this->binQty($product, $this->primary));
        $this->assertSame(4, $this->binQty($product, $this->back));
    }

    public function test_the_recount_adjustment_records_the_bin_it_corrected(): void
    {
        $product = $this->splitProduct();

        $this->audit([$product->id => 1], $this->back);

        $adjustment = \App\Models\Inventory\StockAdjustment::query()->withoutGlobalScopes()->where('product_id', $product->id)->sole();
        $this->assertSame('recount', $adjustment->type);
        $this->assertSame($this->back->id, $adjustment->location_id);
        $this->assertSame(-3, $adjustment->adjustment_quantity);
    }
}
