<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Next number in a per-organization, per-prefix sequence such as
 * ORD-20260928-0001, RMA-20260928-0002 or PO-20260928-0003.
 *
 * The next value is MAX(existing suffix) + 1, where the suffix is the part
 * after the LAST '-'. The maximum is found numerically, not as text: rows are
 * ordered longest first, then highest, so ORD-20260928-10000 beats
 * ORD-20260928-9999 (a plain text sort puts '9999' above '10000' and would
 * hand out ...-10000 again forever once a tenant passes 9,999 in a day).
 * The suffix is zero padded to $pad digits and simply grows past it.
 *
 * Soft-deleted rows are included: the unique index still counts them, so
 * ignoring a trashed highest number would regenerate a colliding value that
 * no retry can escape.
 *
 * The read-then-write is not atomic; callers generate the number inside the
 * transaction that inserts it and wrap that transaction in
 * SequenceNumberRetry, which re-runs it on a unique-index collision.
 */
final class SequenceNumber
{
    /**
     * @param  class-string<Model>  $model
     * @param  string  $column  the sequence column, e.g. order_number
     * @param  string  $prefix  everything before the counter, including the trailing '-'
     * @param  int|null  $organizationId  the tenant; null keeps the model's default (global) scopes
     */
    public static function next(string $model, string $column, string $prefix, ?int $organizationId, int $pad = 4): string
    {
        $query = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        if ($organizationId !== null) {
            // The caller names the tenant explicitly, which also makes this
            // work in queue workers and console commands with no user.
            $query->withoutGlobalScope(OrganizationScope::class)
                ->where((new $model)->qualifyColumn('organization_id'), $organizationId);
        }

        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $last = $query
            ->where($column, 'like', $prefix.'%')
            ->orderByRaw("LENGTH({$wrapped}) DESC")
            ->orderByDesc($column)
            ->value($column);

        $next = $last === null ? 1 : self::suffix((string) $last) + 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * The numeric counter after the last '-'.
     */
    public static function suffix(string $number): int
    {
        $position = strrpos($number, '-');

        return (int) ($position === false ? $number : substr($number, $position + 1));
    }
}
