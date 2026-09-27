<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar as BasePostgresGrammar;

/**
 * Compiles `like` / `not like` as `ilike` / `not ilike`.
 *
 * LIKE is case-insensitive on SQLite (ASCII) and on MySQL's default
 * collation, but case-sensitive on PostgreSQL, so every search box in the
 * app ("widget" finding "Widget") only misbehaved on PostgreSQL. Rewriting
 * the operator here fixes all of them at once. An explicit
 * whereLike(..., caseSensitive: true) still compiles to a plain LIKE.
 */
class PostgresGrammar extends BasePostgresGrammar
{
    protected function whereBasic(Builder $query, $where)
    {
        $operator = strtolower(trim((string) $where['operator']));

        if ($operator === 'like') {
            $where['operator'] = 'ilike';
        } elseif ($operator === 'not like') {
            $where['operator'] = 'not ilike';
        }

        return parent::whereBasic($query, $where);
    }

    protected function whereLike(Builder $query, $where)
    {
        $where['operator'] = ($where['not'] ? 'not ' : '').($where['caseSensitive'] ? 'like' : 'ilike');

        // Straight to the base compiler so a deliberate case-sensitive LIKE
        // is not rewritten by whereBasic() above.
        return parent::whereBasic($query, $where);
    }
}
