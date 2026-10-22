<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Services\ProductLocationStockService;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * StockAuditService::create() and start() snapshot every line with a fixed
 * number of queries, and the snapshot is the one onHandAt() gives.
 */
final class StockAuditSnapshotTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $front;

    private ProductLocation $back;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Snapshot Co');
        $this->admin = $this->makeAdmin($this->org);
        $this->actingAs($this->admin);
        $this->front = $this->makeLocation($this->org, 'Front');
        $this->back = $this->makeLocation($this->org, 'Back');
    }

    private function bin(Product $product, ProductLocation $location, int $quantity): void
    {
        ProductLocationStock::query()->withoutGlobalScopes()->updateOrCreate(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['organization_id' => $this->org->id, 'quantity' => $quantity],
        );
    }

    /**
     * Products in every shape the snapshot distinguishes.
     *
     * @return array<string, Product>
     */
    private function mixedInventory(): array
    {
        $products = [
            'binned here' => $this->makeProduct($this->org, ['stock' => 10, 'location_id' => $this->front->id]),
            'binned elsewhere' => $this->makeProduct($this->org, ['stock' => 7, 'location_id' => $this->front->id]),
            'unbinned, primary here' => $this->makeProduct($this->org, ['stock' => 5, 'location_id' => $this->front->id]),
            'unbinned, primary elsewhere' => $this->makeProduct($this->org, ['stock' => 4, 'location_id' => $this->back->id]),
            'no location' => $this->makeProduct($this->org, ['stock' => 3, 'location_id' => null]),
            'with variants' => $this->makeProduct($this->org, ['stock' => 9, 'location_id' => $this->back->id, 'has_variants' => true]),
        ];

        $this->bin($products['binned here'], $this->front, 6);
        $this->bin($products['binned here'], $this->back, 4);
        $this->bin($products['binned elsewhere'], $this->back, 7);
        $this->bin($products['with variants'], $this->front, 2);
        $this->bin($products['with variants'], $this->back, 7);

        ProductVariant::create([
            'product_id' => $products['with variants']->id,
            'organization_id' => $this->org->id,
            'sku' => 'VAR-'.uniqid(),
            'option_values' => ['Size' => 'S'],
            'stock' => 9,
        ]);
        $variant = $products['with variants']->variants()->firstOrFail();
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($variant, $this->back->id, $this->front->id, 2));

        return $products;
    }

    /**
     * @param  array<int, int>|null  $productIds
     */
    private function create(?ProductLocation $location = null, ?array $productIds = null): StockAudit
    {
        return app(StockAuditService::class)->create($this->org->id, $this->admin, [
            'name' => 'Count',
            'audit_type' => $location ? 'location' : 'full',
            'warehouse_location_id' => $location?->id,
            'product_ids' => $productIds,
        ]);
    }

    /**
     * @return array<int, StockAuditItem> keyed by product id
     */
    private function lines(StockAudit $audit): array
    {
        return $audit->items()->get()->keyBy('product_id')->all();
    }

    /**
     * What the per-product implementation snapshots for $product.
     */
    private function expected(Product $product, ?ProductLocation $location): int
    {
        $product = $product->fresh();
        $target = $product->has_variants ? $product->variants()->first() : $product;

        return $location
            ? app(ProductLocationStockService::class)->onHandAt($target, $location->id)
            : (int) $target->stock;
    }

    public function test_a_location_scoped_audit_snapshots_the_audited_bin(): void
    {
        $products = $this->mixedInventory();
        $ids = array_map(fn (Product $p) => $p->id, $products);

        $audit = $this->create($this->front, array_values($ids));
        $lines = $this->lines($audit);

        $this->assertCount(count($products), $lines);
        $this->assertSame(6, $lines[$ids['binned here']]->system_quantity);
        $this->assertSame(0, $lines[$ids['binned elsewhere']]->system_quantity);
        $this->assertSame(5, $lines[$ids['unbinned, primary here']]->system_quantity);
        $this->assertSame(0, $lines[$ids['unbinned, primary elsewhere']]->system_quantity);
        $this->assertSame(0, $lines[$ids['no location']]->system_quantity);
        $this->assertSame(2, $lines[$ids['with variants']]->system_quantity);

        foreach ($products as $label => $product) {
            $line = $lines[$product->id];
            $this->assertSame($this->expected($product, $this->front), $line->system_quantity, $label);
            $this->assertSame($this->front->id, $line->location_id, $label);
            $this->assertSame($product->has_variants ? $product->variants()->first()->id : null, $line->product_variant_id, $label);
            $this->assertSame('pending', $line->status, $label);
            $this->assertNull($line->counted_quantity, $label);
            $this->assertSame(0, $line->discrepancy, $label);
            $this->assertNotNull($line->created_at, $label);
            $this->assertNotNull($line->updated_at, $label);
        }
    }

    public function test_an_organization_wide_audit_snapshots_the_total_at_the_primary_location(): void
    {
        $products = $this->mixedInventory();
        $inactive = $this->makeProduct($this->org, ['is_active' => false]);
        $trashed = $this->makeProduct($this->org);
        $trashed->delete();
        $other = $this->makeProduct($this->makeOrganization('Other Co'));

        $audit = $this->create();
        $lines = $this->lines($audit);

        $this->assertSame(
            array_values(array_map(fn (Product $p) => $p->id, $products)),
            array_keys($lines),
        );
        $this->assertArrayNotHasKey($inactive->id, $lines);
        $this->assertArrayNotHasKey($trashed->id, $lines);
        $this->assertArrayNotHasKey($other->id, $lines);

        foreach ($products as $label => $product) {
            $this->assertSame($this->expected($product, null), $lines[$product->id]->system_quantity, $label);
            $this->assertSame($product->location_id, $lines[$product->id]->location_id, $label);
        }
    }

    public function test_a_location_audit_without_a_product_list_takes_products_assigned_or_binned_there(): void
    {
        $products = $this->mixedInventory();

        $lines = $this->lines($this->create($this->front));

        $this->assertEqualsCanonicalizing([
            $products['binned here']->id,
            $products['binned elsewhere']->id,
            $products['unbinned, primary here']->id,
            $products['with variants']->id,
        ], array_keys($lines));
    }

    public function test_start_snapshots_stock_that_moved_while_the_draft_waited(): void
    {
        $products = $this->mixedInventory();
        $ids = array_values(array_map(fn (Product $p) => $p->id, $products));
        $scoped = $this->create($this->front, $ids);
        $wide = $this->create(null, $ids);

        DB::table('products')->whereIn('id', $ids)->increment('stock', 3);
        DB::table('product_location_stocks')->whereIn('product_id', $ids)->increment('quantity', 2);
        $this->bin($products['unbinned, primary here'], $this->back, 1);

        $started = app(StockAuditService::class)->start($scoped, $this->admin);
        app(StockAuditService::class)->start($wide, $this->admin);

        $this->assertSame('in_progress', $started->status);
        $this->assertNotNull($started->started_at);
        $this->assertFalse($started->relationLoaded('items'));

        $scopedLines = $this->lines($scoped);
        $wideLines = $this->lines($wide);
        foreach ($products as $label => $product) {
            $this->assertSame($this->expected($product, $this->front), $scopedLines[$product->id]->system_quantity, $label);
            $this->assertSame($this->expected($product, null), $wideLines[$product->id]->system_quantity, $label);
        }

        // The product binned elsewhere for the first time now holds nothing here.
        $this->assertSame(0, $scopedLines[$products['unbinned, primary here']->id]->system_quantity);
        $this->assertSame(8, $scopedLines[$products['binned here']->id]->system_quantity);
    }

    public function test_start_leaves_other_audits_alone(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10]);
        $first = $this->create(null, [$product->id]);
        $second = $this->create(null, [$product->id]);

        $product->update(['stock' => 25]);
        app(StockAuditService::class)->start($first, $this->admin);

        $this->assertSame(25, $first->items()->sole()->system_quantity);
        $this->assertSame(10, $second->items()->sole()->system_quantity);
    }

    public function test_create_and_start_run_a_fixed_number_of_queries_whatever_the_inventory_size(): void
    {
        $small = $this->queriesFor(5);
        $large = $this->queriesFor(60);

        $this->assertSame($small, $large, 'create()/start() queries must not grow with the number of products');
    }

    /**
     * Seed $count products (half of them binned in front) on a fresh
     * organization and count the queries of an organization-wide and a
     * location-scoped create() + start().
     *
     * @return array<string, int>
     */
    private function queriesFor(int $count): array
    {
        $this->org = $this->makeOrganization("Size {$count}");
        $this->admin = $this->makeAdmin($this->org);
        $this->actingAs($this->admin);
        $this->front = $this->makeLocation($this->org, 'Front');

        for ($i = 0; $i < $count; $i++) {
            $product = $this->makeProduct($this->org, ['stock' => $i, 'location_id' => $this->front->id]);
            if ($i % 2 === 0) {
                $this->bin($product, $this->front, $i);
            }
        }

        $counts = [];
        foreach (['organization-wide' => null, 'location-scoped' => $this->front] as $label => $location) {
            $audit = $this->counted("{$label} create", $counts, fn () => $this->create($location));
            $this->assertSame($count, $audit->items()->count());
            $this->counted("{$label} start", $counts, fn () => app(StockAuditService::class)->start($audit, $this->admin));
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counted(string $label, array &$counts, callable $work): mixed
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $work();
        $counts[$label] = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $result;
    }
}
