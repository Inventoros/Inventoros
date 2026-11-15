<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The columns a client may sort each list by, shared by the REST endpoints
 * and the GraphQL list queries. A client-chosen column is never passed to
 * orderBy() unless it is listed here: ordering by an unlisted column (a
 * hidden financial field, a JSON path) leaks the relative order of values
 * the caller cannot otherwise read.
 */
final class ListSortColumns
{
    /**
     * Validation rule for a sort direction, in any letter case.
     */
    public const DIRECTION_RULE = 'regex:/\A(asc|desc)\z/i';

    public const PRODUCTS = ['id', 'created_at', 'updated_at', 'name', 'sku', 'price', 'stock', 'is_active'];

    public const ORDERS = ['id', 'created_at', 'updated_at', 'order_number', 'customer_name', 'total', 'status', 'order_date'];

    public const SUPPLIERS = ['id', 'created_at', 'updated_at', 'name', 'email', 'phone'];

    public const STOCK_ADJUSTMENTS = ['id', 'created_at', 'updated_at', 'type', 'adjustment_quantity'];

    public const PURCHASE_ORDERS = ['id', 'created_at', 'updated_at', 'order_date', 'po_number', 'status', 'total'];
}
