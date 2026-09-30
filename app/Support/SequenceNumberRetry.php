<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * The retry must own the transaction: wrap `DB::transaction(...)` in this
 * helper, never the other way round. Under MySQL's REPEATABLE READ a
 * transaction reads from the snapshot taken at its first read, so retrying
 * inside a transaction that is still open re-reads the same MAX, regenerates
 * the same number and collides every time. For the same reason a call nested
 * inside another SequenceNumberRetry (e.g. OrderService::create inside the
 * order import's per-order retry) makes a single attempt and lets a
 * collision propagate: the outermost loop rolls back its whole transaction
 * and re-runs it with a fresh snapshot.
 */
final class SequenceNumberRetry
{
    public const MAX_ATTEMPTS = 5;

    /**
     * How many create() calls are running on the stack.
     */
    private static int $depth = 0;

    /**
     * Run $factory; on unique-constraint QueryException retry up to
     * $maxAttempts times.
     */
    public static function create(Closure $factory, int $maxAttempts = self::MAX_ATTEMPTS)
    {
        // Nested: the enclosing retry owns the transaction and the retrying.
        if (self::$depth > 0) {
            return $factory();
        }

        $lastException = null;

        self::$depth++;

        try {
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
        } finally {
            self::$depth--;
        }

        throw new RuntimeException(
            'Failed to allocate a unique sequence number after ' . $maxAttempts . ' attempts',
            0,
            $lastException instanceof Throwable ? $lastException : null
        );
    }

    /**
     * Whether a query failed because another row already holds the value
     * (a genuine collision worth retrying with a fresh number).
     *
     * SQLSTATE alone cannot tell: SQLite and MySQL report EVERY integrity
     * violation (NOT NULL, foreign key, check, unique) as 23000, so this
     * looks at the driver-specific detail:
     *
     *  - Laravel's own UniqueConstraintViolationException (raised by the
     *    connection when it recognises the error) is always a collision.
     *  - Postgres: SQLSTATE 23505 (23502 not-null, 23503 FK are not).
     *  - MySQL/MariaDB: driver code 1062 ER_DUP_ENTRY or 1586
     *    ER_DUP_ENTRY_WITH_KEY_NAME (1048 not-null, 1451/1452 FK are not).
     *  - SQL Server: driver code 2627 (unique/PK constraint) or 2601
     *    (unique index) (515 not-null is not).
     *  - SQLite: driver code 19 covers every constraint, so the message
     *    decides: "UNIQUE constraint failed" (or the legacy "is not unique").
     *
     * Anything else is not retried: create() rethrows it unchanged.
     */
    public static function isUniqueConstraintViolation(QueryException $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState !== '23000') {
            return false;
        }

        if (in_array($driverCode, [1062, 1586, 2627, 2601], true)) {
            return true;
        }

        return (bool) preg_match('/UNIQUE constraint failed|columns? .* (is|are) not unique/i', $message);
    }
}
