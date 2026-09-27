<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Order import and the cost recorded on each line:
 *  - a unit_cost column is used as-is and never flagged as an estimate;
 *  - without it, a HISTORICAL import records today's cost but marks it as an
 *    estimate (the goods were sold at some unknown earlier cost);
 *  - without it, a stock-adjusting import behaves like a hand-entered order:
 *    today's cost, not flagged.
 */
final class OrdersImportUnitCostTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'external_reference,order_date,status,product_sku,variant_sku,quantity,unit_price,unit_cost';

    private const HEADER_WITHOUT_COST = 'external_reference,order_date,status,product_sku,variant_sku,quantity,unit_price';

    private User $admin;

    private Product $widget;

    private ProductVariant $large;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        $this->widget = Product::create([
            'organization_id' => $org->id, 'sku' => 'WID-1', 'name' => 'Widget',
            'price' => 10, 'purchase_price' => 4, 'currency' => 'USD', 'stock' => 20, 'min_stock' => 0,
        ]);
        $shirt = Product::create([
            'organization_id' => $org->id, 'sku' => 'SHIRT', 'name' => 'Shirt', 'price' => 25, 'purchase_price' => 9,
            'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'has_variants' => true,
        ]);
        $this->large = ProductVariant::create([
            'organization_id' => $org->id, 'product_id' => $shirt->id, 'sku' => 'SHIRT-L', 'title' => 'Large',
            'option_values' => ['Size' => 'L'], 'price' => 25, 'purchase_price' => 11, 'stock' => 8,
        ]);
    }

    /** @param array<int, string> $lines */
    private function import(array $lines, bool $historical, string $header = self::HEADER): OrdersImport
    {
        $import = new OrdersImport($this->admin, $historical);
        Excel::import($import, UploadedFile::fake()->createWithContent('orders.csv', $header."\n".implode("\n", $lines)."\n"));

        return $import;
    }

    /** @return array<string, array{0: bool}> */
    public static function modes(): array
    {
        return ['historical' => [true], 'stock-adjusting' => [false]];
    }

    #[DataProvider('modes')]
    public function test_a_supplied_unit_cost_is_used_as_is_and_not_flagged(bool $historical): void
    {
        $import = $this->import([
            'H-1,2025-03-01,delivered,WID-1,,2,10,3.40',
            'H-1,,,,SHIRT-L,1,25,8',
        ], $historical);

        $this->assertSame([], $import->getStats()['errors']);
        $items = Order::where('external_reference', 'H-1')->sole()->items()->orderBy('id')->get();

        $this->assertSame(['3.40', '8.00'], $items->map(fn ($i) => (string) $i->unit_cost)->all());
        $this->assertSame([null, null], $items->pluck('unit_cost_backfilled_at')->all());
    }

    public function test_a_historical_import_without_a_cost_marks_the_captured_cost_as_an_estimate(): void
    {
        $import = $this->import([
            'H-2,2025-03-01,delivered,WID-1,,2,10',
            'H-2,,,,SHIRT-L,1,25',
        ], true, self::HEADER_WITHOUT_COST);

        $this->assertSame([], $import->getStats()['errors']);
        $items = Order::where('external_reference', 'H-2')->sole()->items()->orderBy('id')->get();

        // Today's cost (variant's own for the variant line) ...
        $this->assertSame(['4.00', '11.00'], $items->map(fn ($i) => (string) $i->unit_cost)->all());
        // ... flagged, because the sale happened at an unknown earlier cost.
        $this->assertNotNull($items[0]->unit_cost_backfilled_at);
        $this->assertNotNull($items[1]->unit_cost_backfilled_at);
    }

    public function test_a_blank_cost_cell_on_one_line_only_flags_that_line(): void
    {
        $this->import([
            'H-3,2025-03-01,delivered,WID-1,,2,10,',
            'H-3,,,,SHIRT-L,1,25,7.5',
        ], true);

        $items = Order::where('external_reference', 'H-3')->sole()->items()->orderBy('id')->get();

        $this->assertSame('4.00', (string) $items[0]->unit_cost);
        $this->assertNotNull($items[0]->unit_cost_backfilled_at);
        $this->assertSame('7.50', (string) $items[1]->unit_cost);
        $this->assertNull($items[1]->unit_cost_backfilled_at);
    }

    public function test_a_stock_adjusting_import_without_a_cost_behaves_like_a_hand_entered_order(): void
    {
        $this->import(['S-1,2026-01-05,pending,WID-1,,2,10'], false, self::HEADER_WITHOUT_COST);

        $item = Order::where('external_reference', 'S-1')->sole()->items()->sole();

        $this->assertSame('4.00', (string) $item->unit_cost);
        $this->assertNull($item->unit_cost_backfilled_at);
        $this->assertSame(18, (int) $this->widget->fresh()->stock);
    }

    public function test_a_historical_line_whose_product_has_no_cost_stays_unknown_and_unflagged(): void
    {
        $this->widget->update(['purchase_price' => null]);

        $this->import(['H-4,2025-03-01,delivered,WID-1,,1,10'], true, self::HEADER_WITHOUT_COST);

        $item = Order::where('external_reference', 'H-4')->sole()->items()->sole();
        $this->assertNull($item->unit_cost);
        $this->assertNull($item->unit_cost_backfilled_at);
    }

    /** @return array<string, array{0: string}> */
    public static function badCosts(): array
    {
        return ['negative' => ['-1'], 'text' => ['cheap']];
    }

    #[DataProvider('badCosts')]
    public function test_an_invalid_unit_cost_rejects_the_order(string $cost): void
    {
        $import = $this->import(["H-5,2025-03-01,delivered,WID-1,,1,10,{$cost}"], true);

        $stats = $import->getStats();
        $this->assertSame(0, $stats['imported']);
        $this->assertSame(1, $stats['failed']);
        $this->assertStringContainsString('unit cost', strtolower(implode(' ', $stats['errors'][0]['errors'])));
        $this->assertSame(0, Order::where('external_reference', 'H-5')->count());
    }

    public function test_the_template_offers_the_unit_cost_column(): void
    {
        $csv = $this->actingAs($this->admin)->get(route('import-export.download-order-template'))->streamedContent();
        $lines = preg_split('/\r?\n/', trim($csv));
        $header = str_getcsv($lines[0], escape: '');
        $first = str_getcsv($lines[1], escape: '');

        $this->assertContains('unit_cost', $header);
        $this->assertSame(count($header), count($first));
    }
}
