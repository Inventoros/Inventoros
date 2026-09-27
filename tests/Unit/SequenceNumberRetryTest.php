<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SequenceNumberRetry;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SequenceNumberRetry must retry only a genuine unique-constraint collision
 * (another writer took the number). Every other integrity error (NOT NULL,
 * foreign key, check) shares SQLSTATE 23000 on SQLite and MySQL; retrying it
 * five times and then reporting "failed to allocate a unique sequence number"
 * hid the real cause.
 */
final class SequenceNumberRetryTest extends TestCase
{
    private static function queryException(string $driver, string $sqlState, int|string|null $driverCode, string $message): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlState}]: Integrity constraint violation: {$driverCode} {$message}");
        $pdo->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException($driver, 'insert into "orders" ...', [], $pdo);
    }

    /**
     * @return array<string, array{0: QueryException}>
     */
    public static function uniqueViolations(): array
    {
        return [
            'sqlite' => [self::queryException('sqlite', '23000', 19, 'UNIQUE constraint failed: orders.organization_id, orders.order_number')],
            'sqlite primary key' => [self::queryException('sqlite', '23000', 19, 'UNIQUE constraint failed: orders.id')],
            'mysql' => [self::queryException('mysql', '23000', 1062, "Duplicate entry '1-ORD-0001' for key 'orders_organization_id_order_number_unique'")],
            'mysql with key name' => [self::queryException('mysql', '23000', 1586, "Duplicate entry '1-ORD-0001' for key 'orders_organization_id_order_number_unique'")],
            'pgsql' => [self::queryException('pgsql', '23505', 7, 'ERROR:  duplicate key value violates unique constraint "orders_organization_id_order_number_unique"')],
            'sqlsrv' => [self::queryException('sqlsrv', '23000', 2627, 'Violation of UNIQUE KEY constraint')],
            'sqlsrv unique index' => [self::queryException('sqlsrv', '23000', 2601, 'Cannot insert duplicate key row')],
        ];
    }

    /**
     * @return array<string, array{0: QueryException}>
     */
    public static function otherIntegrityErrors(): array
    {
        return [
            'sqlite not null' => [self::queryException('sqlite', '23000', 19, 'NOT NULL constraint failed: orders.currency')],
            'sqlite foreign key' => [self::queryException('sqlite', '23000', 19, 'FOREIGN KEY constraint failed')],
            'sqlite check' => [self::queryException('sqlite', '23000', 19, 'CHECK constraint failed: stock')],
            'mysql not null' => [self::queryException('mysql', '23000', 1048, "Column 'currency' cannot be null")],
            'mysql foreign key' => [self::queryException('mysql', '23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails')],
            'pgsql not null' => [self::queryException('pgsql', '23502', 7, 'null value in column "currency" violates not-null constraint')],
            'pgsql foreign key' => [self::queryException('pgsql', '23503', 7, 'insert or update on table "orders" violates foreign key constraint')],
            'sqlsrv not null' => [self::queryException('sqlsrv', '23000', 515, 'Cannot insert the value NULL into column')],
        ];
    }

    #[DataProvider('uniqueViolations')]
    public function test_it_recognises_unique_violations(QueryException $e): void
    {
        $this->assertTrue(SequenceNumberRetry::isUniqueConstraintViolation($e));
    }

    #[DataProvider('otherIntegrityErrors')]
    public function test_it_does_not_mistake_other_integrity_errors_for_collisions(QueryException $e): void
    {
        $this->assertFalse(SequenceNumberRetry::isUniqueConstraintViolation($e));
    }

    public function test_laravels_unique_violation_exception_is_recognised(): void
    {
        $pdo = new PDOException('duplicate');
        $pdo->errorInfo = ['HY000', 0, 'duplicate'];

        $this->assertTrue(SequenceNumberRetry::isUniqueConstraintViolation(
            new UniqueConstraintViolationException('sqlite', 'insert', [], $pdo)
        ));
    }

    public function test_a_collision_is_retried_until_it_succeeds(): void
    {
        $attempts = 0;
        $collision = self::uniqueViolations()['sqlite'][0];

        $result = SequenceNumberRetry::create(function () use (&$attempts, $collision) {
            if (++$attempts < 3) {
                throw $collision;
            }

            return 'created';
        });

        $this->assertSame('created', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_any_other_integrity_error_is_rethrown_unchanged_on_the_first_attempt(): void
    {
        $attempts = 0;
        $notNull = self::otherIntegrityErrors()['sqlite not null'][0];

        try {
            SequenceNumberRetry::create(function () use (&$attempts, $notNull) {
                $attempts++;

                throw $notNull;
            });
            $this->fail('Expected the NOT NULL error to propagate.');
        } catch (QueryException $e) {
            $this->assertSame($notNull, $e);
        }

        $this->assertSame(1, $attempts);
    }
}
