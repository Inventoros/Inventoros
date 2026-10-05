<?php

declare(strict_types=1);

namespace App\Services\Hooks;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;

/**
 * Fires the domain change hooks (product_*, variant_*, order_*,
 * purchase_order_*, customer_*, stock_changed) once per record per
 * transaction, after it commits.
 *
 * The model observers call this instead of do_action() directly, so a hook
 * fires the same way whichever surface made the change (web, REST, GraphQL,
 * MCP, imports, scheduled commands, the customer portal), and a record that
 * is saved several times inside one transaction (a product, then its
 * variants, then its totals) is announced once. Nothing fires when the
 * transaction rolls back.
 *
 * Outside a transaction a hook fires immediately, as DB::afterCommit() does.
 */
final class DomainHooks
{
    /**
     * Keys whose callback is waiting for the transaction to commit, with the
     * transaction level they were registered at.
     *
     * @var array<string, int>
     */
    private array $pending = [];

    /**
     * Accumulated stock changes, keyed like $pending.
     *
     * @var array<string, array{product_id: int, variant_id: int|null, before: int|null, after: int|null, locations: array<int, array{before: int, after: int}>}>
     */
    private array $stock = [];

    /**
     * Forget everything registered inside a transaction that rolled back:
     * Laravel drops those after-commit callbacks, so their keys must not keep
     * suppressing later changes.
     */
    public function handleRollback(TransactionRolledBack $event): void
    {
        $level = $event->connection->transactionLevel();

        foreach ($this->pending as $key => $registeredAt) {
            if ($registeredAt > $level) {
                unset($this->pending[$key], $this->stock[$key]);
            }
        }
    }

    /**
     * Run $fire once after the current transaction commits, unless the same
     * hook is already waiting for this record.
     */
    public function afterCommit(string $hook, Model $model, Closure $fire): void
    {
        $this->register($this->key($hook, $model), $model->getConnection(), $fire);
    }

    /**
     * Keep $hook from firing for this record until the transaction commits
     * (for example order_updated while the order is still being created).
     */
    public function suppressUntilCommit(string $hook, Model $model): void
    {
        $this->afterCommit($hook, $model, static fn () => null);
    }

    /**
     * Whether $hook is waiting to fire (or suppressed) for this record.
     */
    public function isPending(string $hook, Model $model): bool
    {
        return isset($this->pending[$this->key($hook, $model)]);
    }

    /**
     * Record a change of a product's or variant's on-hand total. The
     * stock_changed hook reports the value before the first change and after
     * the last one in the transaction.
     */
    public function totalStockChanged(Model $subject, int $productId, ?int $variantId, int $before, int $after): void
    {
        $key = $this->stockKey($productId, $variantId);
        $new = ! isset($this->stock[$key]);

        $this->stock[$key] ??= $this->emptyStockEntry($productId, $variantId);
        $this->stock[$key]['before'] ??= $before;
        $this->stock[$key]['after'] = $after;

        if ($new) {
            $this->register($key, $subject->getConnection(), fn () => $this->fireStockChanged($key));
        }
    }

    /**
     * Record a change of a product's quantity at one location bin.
     */
    public function locationStockChanged(Model $subject, int $productId, int $locationId, int $before, int $after): void
    {
        $key = $this->stockKey($productId, null);
        $new = ! isset($this->stock[$key]);

        $this->stock[$key] ??= $this->emptyStockEntry($productId, null);
        $this->stock[$key]['locations'][$locationId] = [
            'before' => $this->stock[$key]['locations'][$locationId]['before'] ?? $before,
            'after' => $after,
        ];

        if ($new) {
            $this->register($key, $subject->getConnection(), fn () => $this->fireStockChanged($key));
        }
    }

    private function stockKey(int $productId, ?int $variantId): string
    {
        return 'stock_changed|'.$productId.'|'.($variantId ?? 0);
    }

    /**
     * @return array{product_id: int, variant_id: int|null, before: int|null, after: int|null, locations: array<int, array{before: int, after: int}>}
     */
    private function emptyStockEntry(int $productId, ?int $variantId): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'before' => null,
            'after' => null,
            'locations' => [],
        ];
    }

    private function fireStockChanged(string $key): void
    {
        $entry = $this->stock[$key] ?? null;
        unset($this->stock[$key]);

        if ($entry === null) {
            return;
        }

        $product = Product::withoutGlobalScopes()->withTrashed()->find($entry['product_id']);
        $variant = $entry['variant_id'] === null
            ? null
            : ProductVariant::withoutGlobalScopes()->withTrashed()->find($entry['variant_id']);

        if ($product === null) {
            return;
        }

        $current = (int) ($variant ?? $product)->stock;
        $change = [
            'before' => $entry['before'] ?? $current,
            'after' => $entry['after'] ?? $current,
            'locations' => $entry['locations'],
        ];

        $unchanged = $change['before'] === $change['after']
            && array_filter($change['locations'], fn (array $bin) => $bin['before'] !== $bin['after']) === [];

        if ($unchanged) {
            return;
        }

        do_action('stock_changed', $product, $variant, $change);
    }

    private function register(string $key, Connection $connection, Closure $fire): void
    {
        if (isset($this->pending[$key])) {
            return;
        }

        $this->pending[$key] = $connection->transactionLevel();

        $connection->afterCommit(function () use ($key, $fire) {
            unset($this->pending[$key]);
            $fire();
        });
    }

    private function key(string $hook, Model $model): string
    {
        return $hook.'|'.$model::class.'|'.$model->getKey();
    }
}
