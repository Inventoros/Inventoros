<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Times StockAuditService::create() and start() on a realistic inventory and
 * counts the queries they run. Excluded from the default suite; run with
 *
 *     php artisan test --group=performance
 *
 * Sizes can be changed with AUDIT_BENCH_PRODUCTS (default 20000) and
 * AUDIT_BENCH_BINS (default 6000). Results are written to STDERR.
 */
#[Group('performance')]
final class StockAuditBenchmarkTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private const CHUNK = 500;

    private Organization $org;

    private User $admin;

    /** @var array<int, ProductLocation> */
    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Bench Co');
        $this->admin = $this->makeAdmin($this->org);
        $this->actingAs($this->admin);

        for ($i = 1; $i <= 20; $i++) {
            $this->locations[] = $this->makeLocation($this->org, "Bin {$i}");
        }
    }

    public function test_create_and_start_on_a_large_inventory(): void
    {
        $products = (int) (getenv('AUDIT_BENCH_PRODUCTS') ?: 20000);
        $bins = (int) (getenv('AUDIT_BENCH_BINS') ?: 6000);

        $this->seedInventory($products, $bins);
        $target = $this->locations[0];

        $lines = [sprintf('StockAudit benchmark (%s): %d products, %d bin rows', DB::getDriverName(), $products, $bins)];

        [$audit, $create] = $this->measure(fn () => app(StockAuditService::class)->create($this->org->id, $this->admin, [
            'name' => 'Full count',
            'audit_type' => 'full',
        ]));
        $this->stockMovesBetweenCreateAndStart();
        [, $start] = $this->measure(fn () => app(StockAuditService::class)->start($audit, $this->admin));
        $lines[] = $this->line('organization-wide', $audit, $create, $start);

        [$audit, $create] = $this->measure(fn () => app(StockAuditService::class)->create($this->org->id, $this->admin, [
            'name' => 'Bin count',
            'audit_type' => 'location',
            'warehouse_location_id' => $target->id,
        ]));
        $this->stockMovesBetweenCreateAndStart();
        [, $start] = $this->measure(fn () => app(StockAuditService::class)->start($audit, $this->admin));
        $lines[] = $this->line('location-scoped', $audit, $create, $start);

        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);

        $this->assertSame('in_progress', $audit->fresh()->status);
    }

    /**
     * Stock moves while the draft waits, so start() has a new quantity to
     * snapshot for every line.
     */
    private function stockMovesBetweenCreateAndStart(): void
    {
        DB::table('products')->where('organization_id', $this->org->id)->increment('stock');
        DB::table('product_location_stocks')->where('organization_id', $this->org->id)->increment('quantity');
    }

    /**
     * @return array{0: mixed, 1: array{ms: float, queries: int}}
     */
    private function measure(callable $work): array
    {
        $queries = 0;
        $listening = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$listening) {
            if ($listening) {
                $queries++;
            }
        });

        $started = hrtime(true);
        $result = $work();
        $ms = (hrtime(true) - $started) / 1e6;
        $listening = false;

        return [$result, ['ms' => $ms, 'queries' => $queries]];
    }

    /**
     * @param  array{ms: float, queries: int}  $create
     * @param  array{ms: float, queries: int}  $start
     */
    private function line(string $label, StockAudit $audit, array $create, array $start): string
    {
        return sprintf(
            '  %-18s items=%-6d create: %8.1f ms %6d queries | start: %8.1f ms %6d queries',
            $label,
            $audit->items()->count(),
            $create['ms'],
            $create['queries'],
            $start['ms'],
            $start['queries'],
        );
    }

    /**
     * Products spread over the locations, every tenth one with three
     * variants, and $bins bin rows weighted towards the first location.
     */
    private function seedInventory(int $products, int $bins): void
    {
        $now = now()->toDateTimeString();
        $locationIds = array_map(fn (ProductLocation $l) => $l->id, $this->locations);

        $rows = [];
        for ($i = 1; $i <= $products; $i++) {
            $rows[] = [
                'organization_id' => $this->org->id,
                'sku' => sprintf('BENCH-%06d', $i),
                'name' => "Bench product {$i}",
                'price' => 10,
                'currency' => 'USD',
                'stock' => $i % 97,
                'min_stock' => 1,
                'is_active' => $i % 50 !== 0,
                'has_variants' => $i % 10 === 0,
                'location_id' => $locationIds[$i % count($locationIds)],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) === self::CHUNK) {
                DB::table('products')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('products')->insert($rows);
        }

        $productIds = DB::table('products')->where('organization_id', $this->org->id)->orderBy('id')->pluck('id')->all();

        $variants = [];
        foreach ($productIds as $n => $productId) {
            if (($n + 1) % 10 !== 0) {
                continue;
            }
            foreach (['S', 'M', 'L'] as $size) {
                $variants[] = [
                    'product_id' => $productId,
                    'organization_id' => $this->org->id,
                    'sku' => "BENCH-V{$productId}-{$size}",
                    'option_values' => json_encode(['Size' => $size]),
                    'stock' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($variants) === self::CHUNK) {
                    DB::table('product_variants')->insert($variants);
                    $variants = [];
                }
            }
        }
        if ($variants !== []) {
            DB::table('product_variants')->insert($variants);
        }

        // Half the bin rows sit in the first location, the rest are spread
        // over the others; one row per product per location.
        $binRows = [];
        $seen = [];
        for ($b = 0; $b < $bins; $b++) {
            $productId = $productIds[($b * 7) % count($productIds)];
            $locationId = $b % 2 === 0 ? $locationIds[0] : $locationIds[1 + ($b % (count($locationIds) - 1))];
            if (isset($seen["{$productId}:{$locationId}"])) {
                continue;
            }
            $seen["{$productId}:{$locationId}"] = true;
            $binRows[] = [
                'organization_id' => $this->org->id,
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $b % 13,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($binRows) === self::CHUNK) {
                DB::table('product_location_stocks')->insert($binRows);
                $binRows = [];
            }
        }
        if ($binRows !== []) {
            DB::table('product_location_stocks')->insert($binRows);
        }
    }
}
