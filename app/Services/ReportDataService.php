<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for executing custom report queries.
 *
 * Builds and executes database queries based on report configuration,
 * supporting multiple data sources, column selection, filtering, and sorting.
 */
class ReportDataService
{
    /**
     * Valid data sources for reports.
     */
    private const VALID_DATA_SOURCES = [
        'products',
        'orders',
        'stock_adjustments',
        'customers',
        'suppliers',
        'purchase_orders',
    ];

    /**
     * Valid filter operators.
     */
    private const VALID_OPERATORS = [
        'eq',
        'neq',
        'gt',
        'lt',
        'gte',
        'lte',
        'contains',
        'starts_with',
        'ends_with',
        'is_null',
        'is_not_null',
    ];

    /**
     * Get all available data sources with their column definitions.
     *
     * @return array<string, array{label: string, columns: array<string, array{label: string, type: string}>}>
     */
    public function getAvailableDataSources(): array
    {
        return [
            'products' => [
                'label' => 'Products',
                'columns' => [
                    'name' => ['label' => 'Name', 'type' => 'string'],
                    'sku' => ['label' => 'SKU', 'type' => 'string'],
                    'stock' => ['label' => 'Stock', 'type' => 'number'],
                    'price' => ['label' => 'Price', 'type' => 'currency'],
                    'purchase_price' => ['label' => 'Purchase Price', 'type' => 'currency'],
                    'category_name' => ['label' => 'Category', 'type' => 'string'],
                    'location_name' => ['label' => 'Location', 'type' => 'string'],
                    'type' => ['label' => 'Type', 'type' => 'string'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                    'created_at' => ['label' => 'Created', 'type' => 'date'],
                ],
            ],
            'orders' => [
                'label' => 'Orders',
                'columns' => [
                    'order_number' => ['label' => 'Order Number', 'type' => 'string'],
                    'customer_name' => ['label' => 'Customer', 'type' => 'string'],
                    'status' => ['label' => 'Status', 'type' => 'string'],
                    'total' => ['label' => 'Total', 'type' => 'currency'],
                    'subtotal' => ['label' => 'Subtotal', 'type' => 'currency'],
                    'tax' => ['label' => 'Tax', 'type' => 'currency'],
                    'order_date' => ['label' => 'Order Date', 'type' => 'date'],
                    'created_at' => ['label' => 'Created', 'type' => 'date'],
                ],
            ],
            'stock_adjustments' => [
                'label' => 'Stock Adjustments',
                'columns' => [
                    'product_name' => ['label' => 'Product', 'type' => 'string'],
                    'product_sku' => ['label' => 'SKU', 'type' => 'string'],
                    'type' => ['label' => 'Type', 'type' => 'string'],
                    'quantity' => ['label' => 'Quantity', 'type' => 'number'],
                    'reason' => ['label' => 'Reason', 'type' => 'string'],
                    'created_at' => ['label' => 'Date', 'type' => 'date'],
                ],
            ],
            'customers' => [
                'label' => 'Customers',
                'columns' => [
                    'name' => ['label' => 'Name', 'type' => 'string'],
                    'email' => ['label' => 'Email', 'type' => 'string'],
                    'phone' => ['label' => 'Phone', 'type' => 'string'],
                    'company' => ['label' => 'Company', 'type' => 'string'],
                    'orders_count' => ['label' => 'Orders', 'type' => 'number'],
                    'created_at' => ['label' => 'Created', 'type' => 'date'],
                ],
            ],
            'suppliers' => [
                'label' => 'Suppliers',
                'columns' => [
                    'name' => ['label' => 'Name', 'type' => 'string'],
                    'email' => ['label' => 'Email', 'type' => 'string'],
                    'phone' => ['label' => 'Phone', 'type' => 'string'],
                    'products_count' => ['label' => 'Products', 'type' => 'number'],
                    'created_at' => ['label' => 'Created', 'type' => 'date'],
                ],
            ],
            'purchase_orders' => [
                'label' => 'Purchase Orders',
                'columns' => [
                    'po_number' => ['label' => 'PO Number', 'type' => 'string'],
                    'supplier_name' => ['label' => 'Supplier', 'type' => 'string'],
                    'status' => ['label' => 'Status', 'type' => 'string'],
                    'total' => ['label' => 'Total', 'type' => 'currency'],
                    'order_date' => ['label' => 'Order Date', 'type' => 'date'],
                    'created_at' => ['label' => 'Created', 'type' => 'date'],
                ],
            ],
        ];
    }

    /**
     * Execute a report query based on configuration.
     *
     * @param int $organizationId
     * @param string $dataSource
     * @param array $columns
     * @param array|null $filters
     * @param array|null $sort
     * @return \Illuminate\Support\Collection
     *
     * @throws \InvalidArgumentException
     */
    public function executeReport(
        int $organizationId,
        string $dataSource,
        array $columns,
        ?array $filters = null,
        ?array $sort = null
    ): Collection {
        if (!in_array($dataSource, self::VALID_DATA_SOURCES, true)) {
            throw new \InvalidArgumentException("Invalid data source: {$dataSource}");
        }

        return match ($dataSource) {
            'products' => $this->queryProducts($organizationId, $columns, $filters, $sort),
            'orders' => $this->queryOrders($organizationId, $columns, $filters, $sort),
            'stock_adjustments' => $this->queryStockAdjustments($organizationId, $columns, $filters, $sort),
            'customers' => $this->queryCustomers($organizationId, $columns, $filters, $sort),
            'suppliers' => $this->querySuppliers($organizationId, $columns, $filters, $sort),
            'purchase_orders' => $this->queryPurchaseOrders($organizationId, $columns, $filters, $sort),
        };
    }

    /**
     * Check if a data source is valid.
     *
     * @param string $dataSource
     * @return bool
     */
    public function isValidDataSource(string $dataSource): bool
    {
        return in_array($dataSource, self::VALID_DATA_SOURCES, true);
    }

    /**
     * Get valid columns for a data source.
     *
     * @param string $dataSource
     * @return array<string>
     */
    public function getValidColumns(string $dataSource): array
    {
        $sources = $this->getAvailableDataSources();

        return array_keys($sources[$dataSource]['columns'] ?? []);
    }

    /**
     * Query products data source.
     */
    private function queryProducts(int $organizationId, array $columns, ?array $filters, ?array $sort): Collection
    {
        $query = DB::table('products')
            ->leftJoin('product_categories', 'products.category_id', '=', 'product_categories.id')
            ->leftJoin('product_locations', 'products.location_id', '=', 'product_locations.id')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at');

        // Map report columns to database columns
        $columnMap = [
            'name' => 'products.name',
            'sku' => 'products.sku',
            'stock' => 'products.stock',
            'price' => 'products.price',
            'purchase_price' => 'products.purchase_price',
            'category_name' => 'product_categories.name as category_name',
            'location_name' => 'product_locations.name as location_name',
            'type' => 'products.tracking_type as type',
            'is_active' => 'products.is_active',
            'created_at' => 'products.created_at',
        ];

        // Map for filters/sort (raw column references without aliases)
        $filterMap = [
            'name' => 'products.name',
            'sku' => 'products.sku',
            'stock' => 'products.stock',
            'price' => 'products.price',
            'purchase_price' => 'products.purchase_price',
            'category_name' => 'product_categories.name',
            'location_name' => 'product_locations.name',
            'type' => 'products.tracking_type',
            'is_active' => 'products.is_active',
            'created_at' => 'products.created_at',
        ];

        $selectColumns = $this->buildSelectColumns($columns, $columnMap);
        $query->select($selectColumns);

        $this->applyFilters($query, $filters, $filterMap);
        $this->applySorting($query, $sort, $filterMap);

        return $query->get();
    }

    /**
     * Query orders data source.
     */
    private function queryOrders(int $organizationId, array $columns, ?array $filters, ?array $sort): Collection
    {
        $query = DB::table('orders')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at');

        $columnMap = [
            'order_number' => 'orders.order_number',
            'customer_name' => 'orders.customer_name',
            'status' => 'orders.status',
            'total' => 'orders.total',
            'subtotal' => 'orders.subtotal',
            'tax' => 'orders.tax',
            'order_date' => 'orders.order_date',
            'created_at' => 'orders.created_at',
        ];

        $filterMap = [
            'order_number' => 'orders.order_number',
            'customer_name' => 'orders.customer_name',
            'status' => 'orders.status',
            'total' => 'orders.total',
            'subtotal' => 'orders.subtotal',
            'tax' => 'orders.tax',
            'order_date' => 'orders.order_date',
            'created_at' => 'orders.created_at',
        ];

        $selectColumns = $this->buildSelectColumns($columns, $columnMap);
        $query->select($selectColumns);

        $this->applyFilters($query, $filters, $filterMap);
        $this->applySorting($query, $sort, $filterMap);

        return $query->get();
    }

    /**
     * Query stock adjustments data source.
     */
    private function queryStockAdjustments(int $organizationId, array $columns, ?array $filters, ?array $sort): Collection
    {
        $query = DB::table('stock_adjustments')
            ->leftJoin('products', 'stock_adjustments.product_id', '=', 'products.id')
            ->where('stock_adjustments.organization_id', $organizationId);

        $columnMap = [
            'product_name' => 'products.name as product_name',
            'product_sku' => 'products.sku as product_sku',
            'type' => 'stock_adjustments.type',
            'quantity' => 'stock_adjustments.adjustment_quantity as quantity',
            'reason' => 'stock_adjustments.reason',
            'created_at' => 'stock_adjustments.created_at',
        ];

        $filterMap = [
            'product_name' => 'products.name',
            'product_sku' => 'products.sku',
            'type' => 'stock_adjustments.type',
            'quantity' => 'stock_adjustments.adjustment_quantity',
            'reason' => 'stock_adjustments.reason',
            'created_at' => 'stock_adjustments.created_at',
        ];

        $selectColumns = $this->buildSelectColumns($columns, $columnMap);
        $query->select($selectColumns);

        $this->applyFilters($query, $filters, $filterMap);
        $this->applySorting($query, $sort, $filterMap);

        return $query->get();
    }

    /**
     * Query customers data source.
     */
    private function queryCustomers(int $organizationId, array $columns, ?array $filters, ?array $sort): Collection
    {
        $needsOrdersCount = in_array('orders_count', $columns, true);

        $query = DB::table('customers')
            ->where('customers.organization_id', $organizationId)
            ->whereNull('customers.deleted_at');

        if ($needsOrdersCount) {
            $query->leftJoin('orders', function ($join) {
                $join->on('customers.id', '=', 'orders.customer_id')
                    ->whereNull('orders.deleted_at');
            })
            ->groupBy('customers.id');
        }

        $columnMap = [
            'name' => 'customers.name',
            'email' => 'customers.email',
            'phone' => 'customers.phone',
            'company' => 'customers.company_name as company',
            'orders_count' => DB::raw('COUNT(orders.id) as orders_count'),
            'created_at' => 'customers.created_at',
        ];

        $filterMap = [
            'name' => 'customers.name',
            'email' => 'customers.email',
            'phone' => 'customers.phone',
            'company' => 'customers.company_name',
            'orders_count' => DB::raw('COUNT(orders.id)'),
            'created_at' => 'customers.created_at',
        ];

        $selectColumns = $this->buildSelectColumns($columns, $columnMap);

        // When grouping, ensure primary columns are in the group by
        if ($needsOrdersCount) {
            $groupByColumns = [];
            foreach ($columns as $col) {
                if ($col !== 'orders_count' && isset($filterMap[$col])) {
                    $groupByColumns[] = $filterMap[$col];
                }
            }
            if (!empty($groupByColumns)) {
                $query->groupBy(array_merge(['customers.id'], $groupByColumns));
            }
        }

        $query->select($selectColumns);

        // For aggregate columns, apply having instead of where
        if ($filters) {
            $aggregateFilters = [];
            $normalFilters = [];
            foreach ($filters as $filter) {
                if (($filter['field'] ?? '') === 'orders_count') {
                    $aggregateFilters[] = $filter;
                } else {
                    $normalFilters[] = $filter;
                }
            }
            $this->applyFilters($query, $normalFilters, $filterMap);
            $this->applyHavingFilters($query, $aggregateFilters, $filterMap);
        }

        $this->applySorting($query, $sort, $filterMap);

        return $query->get();
    }

    /**
     * Query suppliers data source.
     */
    private function querySuppliers(int $organizationId, array $columns, ?array $filters, ?array $sort): Collection
    {
        $needsProductsCount = in_array('products_count', $columns, true);

        $query = DB::table('suppliers')
            ->where('suppliers.organization_id', $organizationId)
            ->whereNull('suppliers.deleted_at');

        if ($needsProductsCount) {
            $query->leftJoin('product_supplier', 'suppliers.id', '=', 'product_supplier.supplier_id')
                ->groupBy('suppliers.id');
        }

        $columnMap = [
            'name' => 'suppliers.name',
            'email' => 'suppliers.email',
            'phone' => 'suppliers.phone',
            'products_count' => DB::raw('COUNT(product_supplier.product_id) as products_count'),
            'created_at' => 'suppliers.created_at',
        ];

        $filterMap = [
            'name' => 'suppliers.name',
            'email' => 'suppliers.email',
            'phone' => 'suppliers.phone',
            'products_count' => DB::raw('COUNT(product_supplier.product_id)'),
            'created_at' => 'suppliers.created_at',
        ];

        $selectColumns = $this->buildSelectColumns($columns, $columnMap);

        if ($needsProductsCount) {
            $groupByColumns = [];
            foreach ($columns as $col) {
                if ($col !== 'products_count' && isset($filterMap[$col])) {
                    $groupByColumns[] = $filterMap[$col];
                }
            }
            if (!empty($groupByColumns)) {
                $query->groupBy(array_merge(['suppliers.id'], $groupByColumns));
            }
        }

        $query->select($selectColumns);

        if ($filters) {
            $aggregateFilters = [];
            $normalFilters = [];
            foreach ($filters as $filter) {
                if (($filter['field'] ?? '') === 'products_count') {
                    $aggregateFilters[] = $filter;
                } else {
                    $normalFilters[] = $filter;
                }
            }
            $this->applyFilters($query, $normalFilters, $filterMap);
            $this->applyHavingFilters($query, $aggregateFilters, $filterMap);
        }

        $this->applySorting($query, $sort, $filterMap);

        return $query->get();
    }

    /**
     * Query purchase orders data source.
     */
    private function queryPurchaseOrders(int $organizationId, array $columns, ?array $filters, ?array $sort): Collection
    {
        $query = DB::table('purchase_orders')
            ->leftJoin('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->where('purchase_orders.organization_id', $organizationId)
            ->whereNull('purchase_orders.deleted_at');

        $columnMap = [
            'po_number' => 'purchase_orders.po_number',
            'supplier_name' => 'suppliers.name as supplier_name',
            'status' => 'purchase_orders.status',
            'total' => 'purchase_orders.total',
            'order_date' => 'purchase_orders.order_date',
            'created_at' => 'purchase_orders.created_at',
        ];

        $filterMap = [
            'po_number' => 'purchase_orders.po_number',
            'supplier_name' => 'suppliers.name',
            'status' => 'purchase_orders.status',
            'total' => 'purchase_orders.total',
            'order_date' => 'purchase_orders.order_date',
            'created_at' => 'purchase_orders.created_at',
        ];

        $selectColumns = $this->buildSelectColumns($columns, $columnMap);
        $query->select($selectColumns);

        $this->applyFilters($query, $filters, $filterMap);
        $this->applySorting($query, $sort, $filterMap);

        return $query->get();
    }

    /**
     * Build SELECT column expressions from requested columns.
     *
     * @param array $columns
     * @param array $columnMap
     * @return array
     */
    private function buildSelectColumns(array $columns, array $columnMap): array
    {
        $selectColumns = [];

        foreach ($columns as $column) {
            if (isset($columnMap[$column])) {
                $selectColumns[] = $columnMap[$column];
            }
        }

        return $selectColumns;
    }

    /**
     * Apply WHERE filters to a query.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array|null $filters
     * @param array $filterMap
     * @return void
     */
    private function applyFilters($query, ?array $filters, array $filterMap): void
    {
        if (empty($filters)) {
            return;
        }

        foreach ($filters as $filter) {
            $field = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;

            if (!$field || !$operator || !isset($filterMap[$field])) {
                continue;
            }

            if (!in_array($operator, self::VALID_OPERATORS, true)) {
                continue;
            }

            $dbColumn = $filterMap[$field];

            // Skip DB::raw expressions (aggregate columns) — handled by applyHavingFilters
            if ($dbColumn instanceof \Illuminate\Database\Query\Expression) {
                continue;
            }

            $this->applyFilterCondition($query, $dbColumn, $operator, $value, 'where');
        }
    }

    /**
     * Apply HAVING filters for aggregate columns.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $filters
     * @param array $filterMap
     * @return void
     */
    private function applyHavingFilters($query, array $filters, array $filterMap): void
    {
        foreach ($filters as $filter) {
            $field = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;

            if (!$field || !$operator || !isset($filterMap[$field])) {
                continue;
            }

            if (!in_array($operator, self::VALID_OPERATORS, true)) {
                continue;
            }

            $dbColumn = $filterMap[$field];

            $this->applyFilterCondition($query, $dbColumn, $operator, $value, 'having');
        }
    }

    /**
     * Apply a single filter condition to a query.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param mixed $dbColumn
     * @param string $operator
     * @param mixed $value
     * @param string $method 'where' or 'having'
     * @return void
     */
    private function applyFilterCondition($query, $dbColumn, string $operator, $value, string $method): void
    {
        match ($operator) {
            'eq' => $query->$method($dbColumn, '=', $value),
            'neq' => $query->$method($dbColumn, '!=', $value),
            'gt' => $query->$method($dbColumn, '>', $value),
            'lt' => $query->$method($dbColumn, '<', $value),
            'gte' => $query->$method($dbColumn, '>=', $value),
            'lte' => $query->$method($dbColumn, '<=', $value),
            'contains' => $query->$method($dbColumn, 'like', '%' . $value . '%'),
            'starts_with' => $query->$method($dbColumn, 'like', $value . '%'),
            'ends_with' => $query->$method($dbColumn, 'like', '%' . $value),
            'is_null' => $query->{$method . 'Null'}($dbColumn),
            'is_not_null' => $query->{$method . 'NotNull'}($dbColumn),
            default => null,
        };
    }

    /**
     * Apply sorting to a query.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array|null $sort
     * @param array $filterMap
     * @return void
     */
    private function applySorting($query, ?array $sort, array $filterMap): void
    {
        if (empty($sort) || !isset($sort['field']) || !isset($filterMap[$sort['field']])) {
            return;
        }

        $direction = in_array($sort['direction'] ?? 'asc', ['asc', 'desc'], true)
            ? $sort['direction']
            : 'asc';

        $dbColumn = $filterMap[$sort['field']];
        $query->orderBy($dbColumn, $direction);
    }
}
