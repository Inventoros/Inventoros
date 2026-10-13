<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;

/**
 * Seeds and refreshes the lines of a stock audit with set-based SQL, so a
 * whole-inventory count costs a fixed number of queries instead of a few per
 * product.
 *
 * The system quantity is the one ProductLocationStockService::onHandAt()
 * gives for a location-scoped audit (the audited bin; a product never binned
 * holds its whole stock at its primary location) and products.stock for an
 * organization-wide audit.
 *
 * Only integers the code itself casts are written into the SQL. They are
 * inlined rather than bound because PostgreSQL cannot infer the type of a
 * placeholder in the select list of an INSERT ... SELECT.
 */
final class StockAuditSnapshot
{
    /**
     * Insert one pending line per product $products selects, in id order.
     * The line's location is the audited bin, or the product's primary
     * location for an organization-wide audit.
     *
     * @param  EloquentBuilder<\App\Models\Inventory\Product>|QueryBuilder  $products  a query over the products table
     * @return int the number of lines inserted
     */
    public function seed(StockAudit $audit, EloquentBuilder|QueryBuilder $products): int
    {
        $base = $products instanceof EloquentBuilder ? $products->toBase() : $products;
        $grammar = $base->getGrammar();
        $locationId = $this->locationId($audit);
        $auditId = (int) $audit->getKey();

        $select = (clone $base)
            ->reorder()
            ->select([
                new Expression((string) $auditId),
                'products.id',
                new Expression($locationId !== null ? (string) $locationId : $grammar->wrap('products.location_id')),
                new Expression($this->systemQuantity($grammar, $locationId)),
            ])
            ->orderBy('products.id');

        $items = StockAuditItem::query()->toBase();
        $inserted = $items->insertUsing(['stock_audit_id', 'product_id', 'location_id', 'system_quantity'], $select);

        // Timestamps are bound in a second statement for the same reason the
        // ids are inlined above.
        $now = (new StockAuditItem)->freshTimestampString();
        StockAuditItem::query()->toBase()
            ->where('stock_audit_id', $auditId)
            ->update(['created_at' => $now, 'updated_at' => $now]);

        return $inserted;
    }

    /**
     * Re-read the system quantity of every line of $audit.
     *
     * @return int the number of lines updated
     */
    public function refresh(StockAudit $audit): int
    {
        $items = StockAuditItem::query();
        $grammar = $items->toBase()->getGrammar();
        $products = $grammar->wrapTable('products');

        $quantity = sprintf(
            'COALESCE((SELECT %s FROM %s WHERE %s = %s), 0)',
            $this->systemQuantity($grammar, $this->locationId($audit)),
            $products,
            $grammar->wrap('products.id'),
            $grammar->wrap('stock_audit_items.product_id'),
        );

        return $items
            ->where('stock_audit_id', (int) $audit->getKey())
            ->update(['system_quantity' => new Expression($quantity)]);
    }

    private function locationId(StockAudit $audit): ?int
    {
        return $audit->warehouse_location_id !== null ? (int) $audit->warehouse_location_id : null;
    }

    /**
     * SQL for the system quantity of the current row of products.
     */
    private function systemQuantity(Grammar $grammar, ?int $locationId): string
    {
        $stock = $grammar->wrap('products.stock');

        if ($locationId === null) {
            return "COALESCE({$stock}, 0)";
        }

        $bins = $grammar->wrapTable((new ProductLocationStock)->getTable());
        $binProduct = $grammar->wrap('product_location_stocks.product_id');
        $binLocation = $grammar->wrap('product_location_stocks.location_id');
        $binQuantity = $grammar->wrap('product_location_stocks.quantity');
        $productId = $grammar->wrap('products.id');
        $primary = $grammar->wrap('products.location_id');

        return "CASE"
            ." WHEN EXISTS (SELECT 1 FROM {$bins} WHERE {$binProduct} = {$productId})"
            ." THEN COALESCE((SELECT {$binQuantity} FROM {$bins} WHERE {$binProduct} = {$productId} AND {$binLocation} = {$locationId}), 0)"
            ." WHEN {$primary} = {$locationId} THEN COALESCE({$stock}, 0)"
            .' ELSE 0 END';
    }
}
