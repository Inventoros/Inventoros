<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Re-asserts tenant isolation on a query after it has passed through a plugin
 * filter (e.g. `product_list_query`, `supplier_list_query`).
 *
 * Plugin filters receive a raw Eloquent builder. A malicious or buggy plugin
 * could widen it past the caller's organization — by stripping the global
 * OrganizationScope with `withoutGlobalScope(...)`, clearing the where clauses,
 * or returning an entirely fresh unscoped query. This guard runs *after* the
 * filter and re-applies the organization constraint unconditionally, so no
 * plugin return value can leak another tenant's rows. Re-adding the constraint
 * is idempotent when the query is already correctly scoped.
 *
 * If the filter returned something other than a builder for the expected model,
 * the original value is discarded and a fresh, safely-scoped query is built.
 */
final class PluginQueryGuard
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  mixed  $query  The (possibly plugin-mutated) value returned by a filter
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function organizationScoped(mixed $query, string $model, int $organizationId): Builder
    {
        if (! $query instanceof Builder || ! $query->getModel() instanceof $model) {
            $query = $model::query();
        }

        $table = $query->getModel()->getTable();

        return $query->where($table.'.organization_id', $organizationId);
    }
}
