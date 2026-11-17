<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Driver-aware "contains" search shared by every search box.
 *
 * - Case-insensitive on every database: ILIKE on PostgreSQL, LIKE elsewhere.
 * - Apostrophes and quotes are ignored, both in the term and in the stored
 *   value: "Players Handbook", "Player's Handbook" and "Player’s Handbook"
 *   all find each other, and "its" finds "it's" (issue #430). Curly quotes
 *   come from store exports, word processors and phone keyboards, so a
 *   literal comparison missed names that looked identical.
 * - Every other character is matched literally: `%`, `_` and the escape
 *   character are escaped and an explicit ESCAPE clause is sent, because
 *   SQLite has no default LIKE escape and MySQL treats `\` as one. Hyphens,
 *   slashes and dots matter in SKUs, barcodes and location codes, so they
 *   are never ignored.
 *
 * On PostgreSQL the stored value is compared through translate() with the
 * quote list inlined, which matches the trigram expression indexes added by
 * the 2026_11_17_000003 migration, so searches stay index-backed.
 * resources/js/lib/searchText.js applies the same rules to the pickers that
 * filter in the browser.
 */
final class Search
{
    /**
     * The LIKE escape character. Not a backslash, which MySQL string
     * literals would need doubling.
     */
    public const ESCAPE = '!';

    /**
     * Typographic forms of the apostrophe and the double quote. They are
     * ignored like the ASCII ones. Keep in step with LOOKALIKES in
     * resources/js/lib/searchText.js.
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
     * Every character a search ignores: the ASCII apostrophe and double
     * quote, then the LOOKALIKES in order. The PostgreSQL expression indexes
     * are built from this exact string; changing it needs a new migration.
     */
    public const IGNORED = "'\"\u{2018}\u{2019}\u{201B}\u{02BC}\u{2032}\u{FF07}\u{201C}\u{201D}\u{201E}\u{201F}\u{2033}\u{FF02}";

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

        $stripped = self::stripQuotes($term);

        if ($stripped === '') {
            // Only quotes were typed: like an empty search, everything matches.
            $query->whereRaw('1 = 1', [], $boolean);

            return $query;
        }

        $query->whereRaw(self::withoutQuotesSql($value, $pgsql).' '.$like, [self::containsPattern($stripped)], $boolean);

        return $query;
    }

    /**
     * The value with every ignored quote removed.
     */
    public static function stripQuotes(string $value): string
    {
        return str_replace(mb_str_split(self::IGNORED), '', $value);
    }

    /**
     * SQL for $expression with every ignored quote removed. The quote list is
     * inlined (it is a constant, never user input) so the expression is
     * identical to the PostgreSQL index expression.
     */
    public static function withoutQuotesSql(string $expression, bool $pgsql): string
    {
        if ($pgsql) {
            return "translate({$expression}, ".self::literal(self::IGNORED).", '')";
        }

        foreach (mb_str_split(self::IGNORED) as $quote) {
            $expression = "replace({$expression}, ".self::literal($quote).", '')";
        }

        return $expression;
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
     * A single-quoted SQL string literal. Only used for the constant quote
     * list; neither it nor any user input contains a backslash.
     */
    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
