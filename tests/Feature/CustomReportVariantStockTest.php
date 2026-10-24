<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\ReportDataService;
use App\Services\Reports\SavedReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

final class CustomReportVariantStockTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $org = $this->makeOrganization();
        $this->admin = $this->makeAdmin($org);
        $this->actingAs($this->admin);
        $sized = $this->makeProduct($org, ['sku' => 'SIZED', 'stock' => 999, 'has_variants' => true]);
        $this->variant($sized, 'SMALL', 7);
        $this->variant($sized, 'MEDIUM', 8);
        $this->variant($sized, 'INACTIVE', 1000, false);
        $this->variant($sized, 'DELETED', 2000)->delete();
        $this->makeProduct($org, ['sku' => 'PLAIN', 'stock' => 20]);
        $this->makeProduct($org, ['sku' => 'EMPTY', 'stock' => 9, 'has_variants' => true]);
        $inactiveOnly = $this->makeProduct($org, ['sku' => 'INACTIVE-ONLY', 'stock' => 444, 'has_variants' => true]);
        $this->variant($inactiveOnly, 'DISABLED', 12, false);
    }

    private function variant(Product $product, string $sku, int $stock, bool $active = true): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id,
            'sku' => $sku, 'stock' => $stock, 'is_active' => $active, 'option_values' => ['Size' => $sku],
        ]);
    }

    private function rows(?array $filters = null, ?array $sort = null, array $columns = ['sku', 'stock'])
    {
        return app(ReportDataService::class)->executeReport($this->admin, $this->admin->organization_id, 'products', $columns, $filters, $sort);
    }

    public function test_reported_stock_matches_active_variants_and_parent_fallback(): void
    {
        $stock = $this->rows()->pluck('stock', 'sku')->map(fn ($value) => (int) $value)->all();
        $this->assertEquals(['SIZED' => 15, 'PLAIN' => 20, 'EMPTY' => 9, 'INACTIVE-ONLY' => 0], $stock);
    }

    public function test_string_filters_and_sort_use_the_same_variant_stock_before_the_row_cap(): void
    {
        $this->assertSame(['SIZED'], $this->rows([['field' => 'stock', 'operator' => 'eq', 'value' => '15']])->pluck('sku')->all());
        $this->assertSame(['INACTIVE-ONLY', 'EMPTY'], $this->rows(
            [['field' => 'stock', 'operator' => 'lt', 'value' => '10']], ['field' => 'stock', 'direction' => 'asc'],
        )->pluck('sku')->all());
        config(['reports.max_rows' => 2]);
        $this->assertSame(['PLAIN', 'SIZED'], $this->rows(null, ['field' => 'stock', 'direction' => 'desc'])->pluck('sku')->all());
    }

    public function test_stock_filters_work_even_when_stock_is_not_an_export_column(): void
    {
        $rows = $this->rows([['field' => 'stock', 'operator' => 'gte', 'value' => '15']], ['field' => 'stock', 'direction' => 'asc'], ['sku']);
        $this->assertSame(['SIZED', 'PLAIN'], $rows->pluck('sku')->all());
        $this->assertFalse(property_exists($rows->first(), 'stock'));
    }

    public function test_preview_and_saved_export_share_the_same_stock_definition(): void
    {
        $filter = [['field' => 'stock', 'operator' => 'eq', 'value' => '15']];
        $this->postJson(route('reports.builder.preview'), ['data_source' => 'products', 'columns' => ['sku', 'stock'], 'filters' => $filter])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock', 15);
        $report = SavedReport::create([
            'organization_id' => $this->admin->organization_id, 'created_by' => $this->admin->id,
            'name' => 'Variant stock', 'data_source' => 'products', 'columns' => ['sku', 'stock'], 'filters' => $filter,
        ]);
        $csv = app(SavedReportRenderer::class)->render($report, $this->admin, 'csv')->content;
        $this->assertStringContainsString('SIZED,15', $csv);
        $this->assertStringNotContainsString('999', $csv);
    }
}
