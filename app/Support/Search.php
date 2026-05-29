<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Driver-aware substring search.
 *
 * Applies a case-insensitive "contains" match across several columns. On
 * PostgreSQL it uses ILIKE — which is both case-insensitive (plain LIKE is
 * case-sensitive on pgsql, a latent bug for the previous code) and able to use
 * the pg_trgm GIN indexes added for these columns, so `%term%` no longer forces
 * a sequential scan. On SQLite/MySQL it falls back to LIKE, whose default
 * collation is already case-insensitive for ASCII.
 */
final class Search
{
    /**
     * Add an OR-group of "column contains term" conditions to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, array $columns, string $term): Builder
    {
        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? 'ilike'
            : 'like';

        $pattern = '%'.$term.'%';

        return $query->where(function (Builder $sub) use ($columns, $operator, $pattern): void {
            foreach ($columns as $column) {
                $sub->orWhere($column, $operator, $pattern);
            }
        });
    }
}
