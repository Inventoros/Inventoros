<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Services\ProductLocationStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProductLocationStockService::receive() books restocked units (order
 * cancel, work order complete/cancel) into a bin. For a product that had
 * never been binned it used to create a bin holding ONLY the received units,
 * so a product with 10 on hand plus 3 restocked showed 13 in total but 3 in
 * its bins: 10 units silently fell into "unassigned". consume(), move() and
 * applyDelta() all seed the bin from the on-hand first; receive() must too.
 */
final class LocationBinReceiveSeedTest extends TestCase
{
    use RefreshDatabase;

    private function product(?int $locationId): Product
    {
        $org = Organization::create([
            'name' => 'Bin Org', 'email' => 'bin@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->actingAs(User::create([
            'name' => 'Clerk', 'email' => 'clerk@bin.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]));

        return Product::create([
            'organization_id' => $org->id, 'sku' => 'BIN-1', 'name' => 'Binless',
            'price' => 10, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0,
            'location_id' => $locationId, 'is_active' => true,
        ]);
    }

    private function location(int $orgId, string $code): ProductLocation
    {
        return ProductLocation::create([
            'organization_id' => $orgId, 'name' => $code, 'code' => $code, 'is_active' => true,
        ]);
    }

    public function test_receiving_into_an_unbinned_product_seeds_its_existing_stock_first(): void
    {
        $product = $this->product(null);
        $location = $this->location($product->organization_id, 'A');
        $product->update(['location_id' => $location->id]);

        // Callers raise the total first, then book the units into a bin.
        StockAdjustment::adjust($product, 3, 'order_cancellation', 'Restock', syncBins: false);
        app(ProductLocationStockService::class)->receive($product, 3);

        $this->assertSame(13, (int) $product->fresh()->stock);
        $this->assertSame(13, (int) ProductLocationStock::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_receiving_into_another_location_keeps_the_seed_at_the_primary(): void
    {
        $product = $this->product(null);
        $primary = $this->location($product->organization_id, 'P');
        $other = $this->location($product->organization_id, 'O');
        $product->update(['location_id' => $primary->id]);

        StockAdjustment::adjust($product, 4, 'return', 'Restock', syncBins: false);
        app(ProductLocationStockService::class)->receive($product, 4, $other->id);

        $this->assertSame(10, app(ProductLocationStockService::class)->quantityAt($product, $primary->id));
        $this->assertSame(4, app(ProductLocationStockService::class)->quantityAt($product, $other->id));
    }

    public function test_receiving_into_an_already_binned_product_only_adds_the_units(): void
    {
        $product = $this->product(null);
        $location = $this->location($product->organization_id, 'A');
        $product->update(['location_id' => $location->id]);
        ProductLocationStock::create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'location_id' => $location->id, 'quantity' => 10,
        ]);

        StockAdjustment::adjust($product, 2, 'return', 'Restock', syncBins: false);
        app(ProductLocationStockService::class)->receive($product, 2);

        $this->assertSame(12, (int) ProductLocationStock::where('product_id', $product->id)->sum('quantity'));
    }
}
