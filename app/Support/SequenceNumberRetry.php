<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * Tiny retry helper for inserts that depend on a "next sequence number"
 * generator.
 *
 * Generators like Order::generateOrderNumber compute the next number by
 * reading the current MAX(...) for the (organization_id, date_bucket)
 * group and adding 1. That read-then-write is not atomic — two concurrent
 * order creations in the same tenant on the same day can land on the
 * same number and the loser hits the per-org UNIQUE constraint we
 * landed in P0-10.
 *
 * Callers wrap their create() in this helper and the closure is re-run
 * on a unique-constraint collision; the generator gets a fresh read and
 * computes the next number. After max attempts the original exception
 * is re-thrown wrapped in a RuntimeException so the failure remains
 * observable.
 */
final class SequenceNumberRetry
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Run $factory; on unique-constraint QueryException retry up to
     * $maxAttempts times.
     */
    public static function create(Closure $factory, int $maxAttempts = self::MAX_ATTEMPTS)
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $factory();
            } catch (QueryException $e) {
                if (!self::isUniqueConstraintViolation($e)) {
                    throw $e;
                }
                $lastException = $e;
            }
        }

        throw new RuntimeException(
            'Failed to allocate a unique sequence number after ' . $maxAttempts . ' attempts',
            0,
            $lastException instanceof Throwable ? $lastException : null
        );
    }

    /**
     * Detect a unique-constraint violation across MySQL, Postgres, and
     * SQLite. MySQL/SQLite use SQLSTATE 23000; Postgres uses 23505.
     */
    public static function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
