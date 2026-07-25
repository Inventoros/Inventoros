<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Services\ProductLocationStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 1 of per-location quantity: the read model over product_location_stocks
 * and the idempotent backfill from products' assigned locations. No live-stock
 * writes yet — products.stock is untouched here.
 */
class ProductLocationStockTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
    }

    private function location(Organization $org, string $name): ProductLocation
    {
        return ProductLocation::create([
            'organization_id' => $org->id, 'name' => $name, 'code' => $name, 'is_active' => true,
        ]);
    }

    private function product(Organization $org, string $sku, int $stock, ?int $locationId = null): Product
    {
        return Product::create([
            'organization_id' => $org->id, 'sku' => $sku, 'name' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => $stock,
            'location_id' => $locationId, 'is_active' => true,
        ]);
    }

    public function test_read_model_returns_quantity_breakdown_and_totals(): void
    {
        $org = $this->org();
        $a = $this->location($org, 'A');
        $b = $this->location($org, 'B');
        $product = $this->product($org, 'P-1', 30);

        ProductLocationStock::create(['organization_id' => $org->id, 'product_id' => $product->id, 'location_id' => $a->id, 'quantity' => 10]);
        ProductLocationStock::create(['organization_id' => $org->id, 'product_id' => $product->id, 'location_id' => $b->id, 'quantity' => 20]);

        $service = app(ProductLocationStockService::class);

        $this->assertSame(10, $service->quantityAt($product, $a->id));
        $this->assertSame(20, $service->quantityAt($product, $b->id));
        // A location the product has never been binned at reads as zero, not null.
        $this->assertSame(0, $service->quantityAt($product, 99999));
        $this->assertSame(30, $service->totalAssigned($product));

        // Breakdown is richest-first with the location eager-loaded.
        $breakdown = $service->breakdown($product);
        $this->assertSame([$b->id, $a->id], $breakdown->pluck('location_id')->all());
        $this->assertSame('B', $breakdown->first()->location->name);

        // The relation is wired the same way.
        $this->assertSame(2, $product->locationStocks()->count());
    }

    public function test_backfill_seeds_rows_from_assigned_locations_and_skips_unassigned(): void
    {
        $org = $this->org();
        $a = $this->location($org, 'A');

        $binned = $this->product($org, 'P-BINNED', 15, $a->id);
        $unassigned = $this->product($org, 'P-UNASSIGNED', 7, null);

        $created = app(ProductLocationStockService::class)->backfill();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('product_location_stocks', [
            'product_id' => $binned->id, 'location_id' => $a->id, 'quantity' => 15,
        ]);
        // Stock with no assigned location stays unbinned — no phantom row.
        $this->assertDatabaseMissing('product_location_stocks', ['product_id' => $unassigned->id]);
    }

    public function test_backfill_is_idempotent_and_does_not_clobber_existing_rows(): void
    {
        $org = $this->org();
        $a = $this->location($org, 'A');
        $product = $this->product($org, 'P-1', 15, $a->id);

        // A prior, hand-adjusted row must survive re-runs untouched.
        ProductLocationStock::create(['organization_id' => $org->id, 'product_id' => $product->id, 'location_id' => $a->id, 'quantity' => 3]);

        $service = app(ProductLocationStockService::class);
        $this->assertSame(0, $service->backfill());
        $this->assertSame(0, $service->backfill());

        $this->assertSame(1, ProductLocationStock::where('product_id', $product->id)->count());
        $this->assertSame(3, $service->quantityAt($product, $a->id));
    }

    public function test_backfill_command_reports_created_rows(): void
    {
        $org = $this->org();
        $a = $this->location($org, 'A');
        $this->product($org, 'P-1', 5, $a->id);

        $this->artisan('inventory:backfill-location-stock')
            ->expectsOutputToContain('Backfilled 1 product-location stock row(s).')
            ->assertExitCode(0);
    }
}
