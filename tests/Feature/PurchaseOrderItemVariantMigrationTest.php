<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rolling back the purchase-order variant column must work on every driver:
 * on SQLite the extra index on the column blocked dropping it.
 */
final class PurchaseOrderItemVariantMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_09_27_000001_add_product_variant_id_to_purchase_order_items_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('purchase_order_items', 'product_variant_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('purchase_order_items', 'product_variant_id'));
    }
}
