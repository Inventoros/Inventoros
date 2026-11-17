<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Driver-aware "contains" search shared by every search box.
 *
 * - Case-insensitive on every database: ILIKE on PostgreSQL (which can use the
 *   pg_trgm GIN indexes on the searched columns), LIKE elsewhere.
 * - What the user typed is matched literally: `%`, `_` and the escape
 *   character are escaped and an explicit ESCAPE clause is sent, because
 *   SQLite has no default LIKE escape and MySQL treats `\` as one.
 * - Typographic quotes count as their plain ASCII forms, both in the term and
 *   in the stored value, so "Player's Handbook" finds "Player’s Handbook"
 *   (curly apostrophes come from store exports, word processors and phone
 *   keyboards) and the other way round. resources/js/lib/searchText.js
 *   applies the same folding to the pickers that filter in the browser.
 */
final class Search
{
    /**
     * The LIKE escape character. Not a backslash, which MySQL string
     * literals would need doubling.
     */
    public const ESCAPE = '!';

    /**
     * Characters searched as the ASCII character they stand in for.
     * Keep in step with LOOKALIKES in resources/js/lib/searchText.js.
     */
    public const LOOKALIKES = [
        "\u{2018}" => "'", // left single quotation mark
        "\u{2019}" => "'", // right single quotation mark / typographic apostrophe
        "\u{201B}" => "'", // single high-reversed-9 quotation mark
        "\u{02BC}" => "'", // modifier letter apostrophe
        "\u{2032}" => "'", // prime
        "\u{FF07}" => "'", // fullwidth apostrophe
        "\u{201C}" => '"', // left double quotation mark
        "\u{201D}" => '"', // right double quotation mark
        "\u{201E}" => '"', // double low-9 quotation mark
        "\u{201F}" => '"', // double high-reversed-9 quotation mark
        "\u{2033}" => '"', // double prime
        "\u{FF02}" => '"', // fullwidth quotation mark
    ];

    /**
     * Add an OR-group of "column contains term" conditions to the query.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  array<int, string>  $columns
     * @return TBuilder
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, array $columns, string $term): EloquentBuilder|QueryBuilder
    {
        return $query->where(function (EloquentBuilder|QueryBuilder $group) use ($columns, $term): void {
            foreach ($columns as $column) {
                self::orWhereContains($group, $column, $term);
            }
        });
    }

    /**
     * Add one "column contains term" condition, joined with OR.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function orWhereContains(EloquentBuilder|QueryBuilder $query, string $column, string $term): EloquentBuilder|QueryBuilder
    {
        return self::whereContains($query, $column, $term, 'or');
    }

    /**
     * Add one "column contains term" condition.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  'and'|'or'  $boolean
     * @return TBuilder
     */
    public static function whereContains(EloquentBuilder|QueryBuilder $query, string $column, string $term, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $pgsql = $base->getConnection()->getDriverName() === 'pgsql';
        $like = ($pgsql ? 'ilike' : 'like').' ? escape \''.self::ESCAPE.'\'';

        $wrapped = $base->getGrammar()->wrap($column);
        // Laravel's own pgsql grammar compares `::text` for LIKE, which keeps
        // json selectors working and matches the trigram index expression.
        $value = $pgsql ? $wrapped.'::text' : $wrapped;

        $folded = self::fold($term);
        $lookalikes = self::lookalikesFor($folded);

        if ($lookalikes === []) {
            $query->whereRaw("{$value} {$like}", [self::containsPattern($folded)], $boolean);

            return $query;
        }

        // The term has a quote in it. Fold the stored value too, so either
        // form matches. The first condition swaps each quote for the
        // single-character wildcard: it never excludes a real match, and on
        // PostgreSQL it lets the trigram index narrow the rows before the
        // REPLACE() comparison (which no index covers) checks them exactly.
        $replaced = $value;
        $bindings = [];
        foreach ($lookalikes as $from => $to) {
            $replaced = "replace({$replaced}, ?, ?)";
            array_push($bindings, $from, $to);
        }

        $loose = '%'.strtr(self::escape($folded), ["'" => '_', '"' => '_']).'%';

        $query->whereRaw(
            "({$value} {$like} and {$replaced} {$like})",
            [$loose, ...$bindings, self::containsPattern($folded)],
            $boolean,
        );

        return $query;
    }

    /**
     * Map typographic quotes to the ASCII character they stand in for.
     */
    public static function fold(string $value): string
    {
        return strtr($value, self::LOOKALIKES);
    }

    /**
     * Escape LIKE wildcards so the term is matched literally (use with
     * `ESCAPE '!'`).
     */
    public static function escape(string $value): string
    {
        return strtr($value, [
            self::ESCAPE => self::ESCAPE.self::ESCAPE,
            '%' => self::ESCAPE.'%',
            '_' => self::ESCAPE.'_',
        ]);
    }

    private static function containsPattern(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /**
     * The lookalikes whose ASCII form occurs in the (folded) term. Only those
     * have to be folded in the stored value.
     *
     * @return array<string, string>
     */
    private static function lookalikesFor(string $folded): array
    {
        return array_filter(self::LOOKALIKES, fn (string $ascii): bool => str_contains($folded, $ascii));
    }
}
