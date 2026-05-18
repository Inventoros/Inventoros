<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompositeIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Assert that an index covering exactly these columns (in order) exists
     * on $table. Uses Schema::getIndexes which is portable across MySQL,
     * Postgres, and SQLite in Laravel 11+.
     */
    protected function assertIndexExists(string $table, array $columns, ?string $message = null): void
    {
        $indexes = collect(Schema::getIndexes($table));

        $matched = $indexes->contains(
            fn ($index) => array_values($index['columns']) === array_values($columns)
        );

        $this->assertTrue(
            $matched,
            $message ?? "Expected an index on {$table}(" . implode(', ', $columns) . ") to exist. Got: "
                . json_encode($indexes->pluck('columns')->all())
        );
    }

    public function test_products_org_active_created_index_exists(): void
    {
        $this->assertIndexExists('products', ['organization_id', 'is_active', 'created_at']);
    }

    public function test_products_org_category_index_exists(): void
    {
        $this->assertIndexExists('products', ['organization_id', 'category_id']);
    }

    public function test_products_org_location_index_exists(): void
    {
        $this->assertIndexExists('products', ['organization_id', 'location_id']);
    }

    public function test_orders_org_source_date_index_exists(): void
    {
        $this->assertIndexExists('orders', ['organization_id', 'source', 'order_date']);
    }

    public function test_order_items_product_order_index_exists(): void
    {
        $this->assertIndexExists('order_items', ['product_id', 'order_id']);
    }
}
