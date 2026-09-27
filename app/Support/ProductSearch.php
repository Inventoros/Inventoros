<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs the documented `product_search_query` plugin filter.
 *
 * Every product search (the product list, global search and the REST product
 * list) builds its match conditions inside one nested OR group and hands that
 * group to the filter. A plugin extends the search with `orWhere(...)` and the
 * extra conditions stay inside the group, so they can never loosen the
 * organization, warehouse or other filters around it. Only the group passed in
 * is used: returning a different builder cannot replace the outer query.
 */
final class ProductSearch
{
    /**
     * @param  Builder<Product>  $searchGroup  The nested builder holding the search conditions.
     */
    public static function applyPluginFilter(Builder $searchGroup, string $term): void
    {
        apply_filters('product_search_query', $searchGroup, $term);
    }
}
