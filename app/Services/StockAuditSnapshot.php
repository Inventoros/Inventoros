<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\DB;

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
     * @param  EloquentBuilder<Product>|QueryBuilder  $products  a query over the products table
     * @return int the number of lines inserted
     */
    public function seed(StockAudit $audit, EloquentBuilder|QueryBuilder $products): int
    {
        $base = $products instanceof EloquentBuilder ? $products->toBase() : $products;
        $base = DB::query()->fromSub((clone $base)->reorder()->select('products.*'), 'products');
        $grammar = $base->getGrammar();
        $locationId = $this->locationId($audit);
        $auditId = (int) $audit->getKey();

        $select = (clone $base)
            ->reorder()
            ->whereRaw('NOT (products.has_variants = TRUE AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL))')
            ->select([
                new Expression((string) $auditId),
                'products.id',
                new Expression($locationId !== null ? (string) $locationId : $grammar->wrap('products.location_id')),
                new Expression($this->systemQuantity($grammar, $locationId)),
            ])
            ->orderBy('products.id');

        if ($audit->warehouse_id !== null) {
            $this->warehouseBins($select, $audit, false, $auditId);
        }

        $items = StockAuditItem::query()->toBase();
        $inserted = $items->insertUsing(['stock_audit_id', 'product_id', 'location_id', 'system_quantity'], $select);

        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('products.id', (clone $base)->reorder()->select('products.id'))
            ->where('products.has_variants', true)
            ->where('product_variants.is_active', true)->whereNull('product_variants.deleted_at')
            ->whereColumn('products.organization_id', 'product_variants.organization_id')
            ->select([
                new Expression((string) $auditId), 'products.id', 'product_variants.id',
                new Expression($locationId !== null ? (string) $locationId : $grammar->wrap('products.location_id')),
                new Expression($this->systemQuantity($grammar, $locationId, true)),
            ])->orderBy('products.id')->orderBy('product_variants.id');
        if ($audit->warehouse_id !== null) {
            $this->warehouseBins($variants, $audit, true, $auditId);
        }
        $inserted += StockAuditItem::query()->toBase()->insertUsing(
            ['stock_audit_id', 'product_id', 'product_variant_id', 'location_id', 'system_quantity'], $variants,
        );

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
        $location = $audit->warehouse_id !== null ? $grammar->wrap('stock_audit_items.location_id') : $this->locationId($audit);

        $quantity = sprintf(
            'COALESCE((SELECT %s FROM %s WHERE %s = %s), 0)',
            $this->systemQuantity($grammar, $location),
            $products,
            $grammar->wrap('products.id'),
            $grammar->wrap('stock_audit_items.product_id'),
        );

        $variantQuantity = sprintf(
            'COALESCE((SELECT %s FROM %s JOIN %s ON %s = %s WHERE %s = %s AND %s = %s), 0)',
            $this->systemQuantity($grammar, $location, true),
            $grammar->wrapTable('product_variants'), $products,
            $grammar->wrap('products.id'), $grammar->wrap('product_variants.product_id'),
            $grammar->wrap('product_variants.id'), $grammar->wrap('stock_audit_items.product_variant_id'),
            $grammar->wrap('products.id'), $grammar->wrap('stock_audit_items.product_id'),
        );
        $quantity = 'CASE WHEN '.$grammar->wrap('stock_audit_items.product_variant_id').' IS NOT NULL THEN '.$variantQuantity.' ELSE '.$quantity.' END';

        return $items
            ->where('stock_audit_id', (int) $audit->getKey())
            ->update(['system_quantity' => new Expression($quantity)]);
    }

    private function warehouseBins(QueryBuilder $query, StockAudit $audit, bool $variant, int $auditId): void
    {
        $table = $variant ? 'variant_location_stocks' : 'product_location_stocks';
        $target = $variant ? 'product_variants' : 'products';
        $column = $variant ? 'product_variant_id' : 'product_id';
        $query->leftJoin($table.' as audit_bins', 'audit_bins.'.$column, '=', $target.'.id')
            ->join('product_locations as audit_locations', function ($join): void {
                $join->on('audit_locations.id', '=', DB::raw('COALESCE(audit_bins.location_id, products.location_id)'));
            })
            ->where('audit_locations.warehouse_id', $audit->warehouse_id)->whereNull('audit_locations.deleted_at')
            ->when($audit->warehouse_location_id !== null, fn ($q) => $q->where('audit_locations.id', $audit->warehouse_location_id));
        $columns = [new Expression((string) $auditId), 'products.id'];
        if ($variant) {
            $columns[] = 'product_variants.id';
        }
        $columns[] = 'audit_locations.id';
        $columns[] = new Expression('COALESCE(audit_bins.quantity, '.$target.'.stock)');
        $query->select($columns)->orderBy('audit_locations.id');
    }

    private function locationId(StockAudit $audit): ?int
    {
        return $audit->warehouse_location_id !== null ? (int) $audit->warehouse_location_id : null;
    }

    /**
     * SQL for the system quantity of the current row of products.
     */
    private function systemQuantity(Grammar $grammar, int|string|null $locationId, bool $variant = false): string
    {
        $target = $variant ? 'product_variants' : 'products';
        $binTable = $variant ? 'variant_location_stocks' : 'product_location_stocks';
        $targetColumn = $variant ? 'product_variant_id' : 'product_id';
        $stock = $grammar->wrap($target.'.stock');

        if ($locationId === null) {
            return "COALESCE({$stock}, 0)";
        }

        $bins = $grammar->wrapTable($binTable);
        $binProduct = $grammar->wrap($binTable.'.'.$targetColumn);
        $binLocation = $grammar->wrap($binTable.'.location_id');
        $binQuantity = $grammar->wrap($binTable.'.quantity');
        $productId = $grammar->wrap($target.'.id');
        $primary = $grammar->wrap('products.location_id');

        return 'CASE'
            ." WHEN EXISTS (SELECT 1 FROM {$bins} WHERE {$binProduct} = {$productId})"
            ." THEN COALESCE((SELECT {$binQuantity} FROM {$bins} WHERE {$binProduct} = {$productId} AND {$binLocation} = {$locationId}), 0)"
            ." WHEN {$primary} = {$locationId} THEN COALESCE({$stock}, 0)"
            .' ELSE 0 END';
    }
}
