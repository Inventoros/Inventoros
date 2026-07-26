<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Services\LocationStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The detection net for per-location drift: SUM(bins) should never exceed
 * products.stock, and reconcile() brings the bins back in line without
 * touching the total.
 */
class LocationStockReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create([
            'name' => 'Recon Org', 'email' => 'r@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true,
        ]);
    }

    private function product(string $sku, int $stock, ?int $binned): Product
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'location_id' => $this->location->id, 'is_active' => true,
        ]);
        if ($binned !== null) {
            ProductLocationStock::create([
                'organization_id' => $this->org->id, 'product_id' => $product->id,
                'location_id' => $this->location->id, 'quantity' => $binned,
            ]);
        }

        return $product;
    }

    private function binTotal(Product $product): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->sum('quantity');
    }

    public function test_reports_over_claim_and_unassigned_but_not_in_sync_or_unbinned(): void
    {
        $overClaim = $this->product('OVER', 10, 15);  // bins claim 5 more than exist
        $unassigned = $this->product('UNDER', 10, 6);  // 4 units not yet binned
        $this->product('SYNC', 10, 10);               // in sync — excluded
        $this->product('NOBIN', 10, null);            // no bins — excluded

        $rows = app(LocationStockReconciliationService::class)
            ->discrepancies($this->org->id)
            ->keyBy(fn (array $r) => $r['product']->id);

        $this->assertCount(2, $rows);
        $this->assertSame(-5, $rows[$overClaim->id]['difference']);
        $this->assertSame(15, $rows[$overClaim->id]['binned_total']);
        $this->assertSame(4, $rows[$unassigned->id]['difference']);
    }

    public function test_reconcile_brings_bins_to_stock_without_changing_the_total(): void
    {
        $overClaim = $this->product('OVER', 10, 15);
        $unassigned = $this->product('UNDER', 10, 6);

        $fixed = app(LocationStockReconciliationService::class)->reconcile($this->org->id);

        $this->assertSame(2, $fixed);
        // Bins now sum to stock; the total itself is untouched.
        $this->assertSame(10, $this->binTotal($overClaim));
        $this->assertSame(10, (int) $overClaim->fresh()->stock);
        $this->assertSame(10, $this->binTotal($unassigned));
        $this->assertSame(10, (int) $unassigned->fresh()->stock);

        // Idempotent.
        $this->assertSame(0, app(LocationStockReconciliationService::class)->reconcile($this->org->id));
    }

    public function test_command_reports_without_fix_then_corrects(): void
    {
        $product = $this->product('OVER', 10, 15);

        $this->artisan('inventory:reconcile-location-stock')
            ->expectsOutputToContain('1 product(s) drifted')
            ->assertExitCode(0);
        $this->assertSame(15, $this->binTotal($product)); // report-only left it alone

        $this->artisan('inventory:reconcile-location-stock --fix')
            ->assertExitCode(0);
        $this->assertSame(10, $this->binTotal($product));
    }
}
