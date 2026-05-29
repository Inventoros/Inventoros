<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReportDataService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ReportDataServiceTest extends TestCase
{
    private function service(): ReportDataService
    {
        return app(ReportDataService::class);
    }

    public function test_built_in_sources_are_available_and_valid(): void
    {
        $service = $this->service();
        $sources = $service->getAvailableDataSources();

        foreach (['products', 'orders', 'stock_adjustments', 'customers', 'suppliers', 'purchase_orders'] as $source) {
            $this->assertArrayHasKey($source, $sources);
            $this->assertTrue($service->isValidDataSource($source));
        }

        $this->assertContains('sku', $service->getValidColumns('products'));
        $this->assertFalse($service->isValidDataSource('nonexistent'));
    }

    public function test_plugins_can_register_a_data_source(): void
    {
        add_filter('report_data_sources', fn (array $sources) => $sources + [
            'widgets' => [
                'label' => 'Widgets',
                'columns' => [
                    'name' => ['label' => 'Name', 'type' => 'string'],
                    'colour' => ['label' => 'Colour', 'type' => 'string'],
                ],
            ],
        ]);

        $service = $this->service();

        $this->assertTrue($service->isValidDataSource('widgets'));
        $this->assertSame(['name', 'colour'], $service->getValidColumns('widgets'));
        $this->assertArrayHasKey('widgets', $service->getAvailableDataSources());
    }

    public function test_plugin_data_source_rows_resolve_through_query_hook(): void
    {
        add_filter('report_data_sources', fn (array $sources) => $sources + [
            'widgets' => ['label' => 'Widgets', 'columns' => ['name' => ['label' => 'Name', 'type' => 'string']]],
        ]);

        add_filter('report_query_widgets', fn ($value, int $orgId, array $columns, ?array $filters, ?array $sort) => collect([
            ['name' => 'Sprocket', 'org' => $orgId],
        ]));

        $rows = $this->service()->executeReport(42, 'widgets', ['name']);

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertSame('Sprocket', $rows->first()['name']);
        $this->assertSame(42, $rows->first()['org']);
    }

    public function test_plugin_source_without_query_handler_throws(): void
    {
        add_filter('report_data_sources', fn (array $sources) => $sources + [
            'widgets' => ['label' => 'Widgets', 'columns' => ['name' => ['label' => 'Name', 'type' => 'string']]],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No query handler registered');

        $this->service()->executeReport(1, 'widgets', ['name']);
    }

    public function test_unknown_data_source_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid data source');

        $this->service()->executeReport(1, 'nope', ['x']);
    }
}
