<?php

declare(strict_types=1);

namespace Tests\Feature\Kits;

use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;
use App\Services\Inventory\KitSaleService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

final class KitSaleServiceTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization();
    }

    private function service(): KitSaleService
    {
        return app(KitSaleService::class);
    }

    /**
     * @param  array<int, array{0: Product, 1: string|int|float}>  $parts
     */
    private function makeKit(array $parts, string $name = 'Starter Kit'): Product
    {
        $kit = $this->makeProduct($this->org, ['type' => 'kit', 'name' => $name, 'stock' => 0]);
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
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function standardKit(): array
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10, 'purchase_price' => 2.50]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 9, 'purchase_price' => 1.00]);

        return [$this->makeKit([[$a, 2], [$b, 3]]), $a, $b];
    }

    public function test_availability_is_the_scarcest_component(): void
    {
        [$kit] = $this->standardKit();

        $this->assertSame(3, $this->service()->available($kit));
    }

    public function test_availability_subtracts_reservations(): void
    {
        [$kit, $a] = $this->standardKit();

        app(StockReservationService::class)->reserve($a, null, 4, 'shop', 'CART-1');

        $this->assertSame(3, $this->service()->available($kit));

        app(StockReservationService::class)->reserve($a, null, 2, 'shop', 'CART-2');

        // A now gives (10 - 6) / 2 = 2.
        $this->assertSame(2, $this->service()->available($kit->fresh()));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsellableKits(): array
    {
        return [
            'no components' => ['none', 'has no components'],
            'fractional quantity' => ['fractional', 'whole numbers'],
            'serial tracked component' => ['serial', 'tracked'],
            'component is a kit' => ['nested', 'kit or assembly'],
            'kit has variants' => ['variants', 'variants'],
            'deleted component' => ['trashed', 'has been deleted'],
            'component from another organization' => ['foreign', 'another organization'],
            'component has variants' => ['component_variants', 'which has variants'],
        ];
    }

    #[DataProvider('unsellableKits')]
    public function test_each_unsellable_kit_is_refused_with_its_reason(string $case, string $reason): void
    {
        $part = $this->makeProduct($this->org, ['name' => 'Part', 'stock' => 10]);

        $kit = match ($case) {
            'none' => $this->makeKit([], 'Bare Kit'),
            'fractional' => $this->makeKit([[$part, '1.50']], 'Bare Kit'),
            'serial' => $this->makeKit([[
                $this->makeProduct($this->org, ['name' => 'Camera', 'stock' => 5, 'tracking_type' => 'serial']), 1,
            ]], 'Bare Kit'),
            'nested' => $this->makeKit([[
                $this->makeProduct($this->org, ['type' => 'kit', 'name' => 'Inner', 'stock' => 0]), 1,
            ]], 'Bare Kit'),
            'variants' => $this->makeKit([[$part, 1]], 'Bare Kit'),
            'trashed' => $this->makeKit([[$part, 1]], 'Bare Kit'),
            'foreign' => $this->makeKit([[
                $this->makeProduct($this->makeOrganization('Other Org'), ['name' => 'Foreign Part', 'stock' => 10]), 1,
            ]], 'Bare Kit'),
            'component_variants' => $this->makeKit([[
                $this->makeProduct($this->org, ['name' => 'Shirt', 'stock' => 10, 'has_variants' => true]), 1,
            ]], 'Bare Kit'),
        };

        if ($case === 'trashed') {
            $part->delete();
            $kit = $kit->fresh();
        }

        if ($case === 'variants') {
            $kit->forceFill(['has_variants' => true])->save();
            ProductVariant::create([
                'organization_id' => $this->org->id,
                'product_id' => $kit->id,
                'sku' => 'KIT-V1',
                'option_values' => [],
                'stock' => 0,
            ]);
            $kit = $kit->fresh();
        }

        $problem = $this->service()->problem($kit);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('Bare Kit', $problem);
        $this->assertStringContainsString($reason, $problem);
        $this->assertSame(0, $this->service()->available($kit));

        try {
            $this->service()->components($kit);
            $this->fail('Expected InvalidOrderItemException');
        } catch (InvalidOrderItemException $e) {
            $this->assertSame($problem, $e->getMessage());
        }
    }

    public function test_reservations_beyond_stock_clamp_availability_to_zero(): void
    {
        [$kit, $a] = $this->standardKit();

        app(StockReservationService::class)->reserve($a, null, 8, 'shop', 'CART-1');
        // Stock later drops below what is held (e.g. a physical write-off).
        $a->forceFill(['stock' => 3])->saveQuietly();

        $this->assertSame(0, $this->service()->available($kit->fresh()));
        $this->assertSame([$kit->id => 0], $this->service()->availableFor(collect([$kit->fresh()])));
    }

    public function test_a_sellable_kit_has_no_problem_and_draws_per_component(): void
    {
        [$kit, $a, $b] = $this->standardKit();

        $this->assertNull($this->service()->problem($kit));

        $draws = $this->service()->draws($kit);
        $this->assertCount(2, $draws);
        $this->assertSame('p'.$a->id, $draws[0]['key']);
        $this->assertSame(2, $draws[0]['per']);
        $this->assertSame($a->id, $draws[0]['target']->id);
        $this->assertSame('p'.$b->id, $draws[1]['key']);
        $this->assertSame(3, $draws[1]['per']);
    }

    public function test_cost_at_sale_sums_component_costs(): void
    {
        [$kit, , $b] = $this->standardKit();

        $this->assertSame('8.00', $this->service()->costAtSale($kit));

        $b->forceFill(['purchase_price' => null])->save();

        $this->assertNull($this->service()->costAtSale($kit->fresh()));
    }

    public function test_available_for_batches_many_kits(): void
    {
        $kits = collect([
            $this->standardKit()[0],
            $this->standardKit()[0],
            $this->standardKit()[0],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->service()->availableFor($kits);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries);
        $this->assertSame([3, 3, 3], array_values($result));
        $this->assertSame($kits->pluck('id')->all(), array_keys($result));
    }

    public function test_breakdown_names_the_limiting_component_and_what_one_more_kit_needs(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'sku' => 'PA', 'stock' => 7]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'sku' => 'PB', 'stock' => 20]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $breakdown = $this->service()->breakdown($kit);

        $this->assertSame(3, $breakdown['available']);
        $this->assertNull($breakdown['problem']);
        $this->assertSame([
            ['product_id' => $a->id, 'name' => 'Part A', 'sku' => 'PA', 'free' => 7, 'per_kit' => 2, 'allows' => 3, 'limiting' => true, 'needed_for_next' => 1],
            ['product_id' => $b->id, 'name' => 'Part B', 'sku' => 'PB', 'free' => 20, 'per_kit' => 1, 'allows' => 20, 'limiting' => false, 'needed_for_next' => 0],
        ], $breakdown['components']);
    }

    public function test_breakdown_marks_every_tied_component_as_limiting(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 4]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 6]);
        $c = $this->makeProduct($this->org, ['name' => 'Part C', 'stock' => 50]);
        $kit = $this->makeKit([[$a, 2], [$b, 3], [$c, 1]]);

        $components = collect($this->service()->breakdown($kit)['components'])->keyBy('product_id');

        $this->assertTrue($components[$a->id]['limiting']);
        $this->assertTrue($components[$b->id]['limiting']);
        $this->assertFalse($components[$c->id]['limiting']);
        $this->assertSame(2, $components[$a->id]['needed_for_next']);
        $this->assertSame(3, $components[$b->id]['needed_for_next']);
    }

    public function test_breakdown_counts_reserved_and_negative_stock_as_not_free(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => -3]);
        $kit = $this->makeKit([[$a, 1], [$b, 2]]);
        app(StockReservationService::class)->reserve($a, null, 4, 'shop', 'CART-1');

        $breakdown = $this->service()->breakdown($kit);
        $components = collect($breakdown['components'])->keyBy('product_id');

        $this->assertSame(0, $breakdown['available']);
        $this->assertSame(6, $components[$a->id]['free']);
        $this->assertSame(0, $components[$b->id]['free']);
        $this->assertTrue($components[$b->id]['limiting']);
        $this->assertFalse($components[$a->id]['limiting']);
        $this->assertSame(2, $components[$b->id]['needed_for_next']);
    }

    public function test_breakdown_of_an_unsellable_kit_gives_the_reason_and_no_components(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $kit = $this->makeKit([[$a, '1.5']]);
        $empty = $this->makeKit([], 'Empty Kit');

        $result = $this->service()->breakdownFor(collect([$kit, $empty]));

        $this->assertSame(0, $result[$kit->id]['available']);
        $this->assertSame([], $result[$kit->id]['components']);
        $this->assertStringContainsString('fractional', (string) $result[$kit->id]['problem']);
        $this->assertSame(0, $result[$empty->id]['available']);
        $this->assertStringContainsString('no components', (string) $result[$empty->id]['problem']);
    }
}
