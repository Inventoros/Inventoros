<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Warehouse;
use App\Services\Hooks\DomainHooks;

/**
 * Fires the warehouse_created, warehouse_updated and warehouse_deleted actions
 * from the model lifecycle, so plugins see the change whichever surface
 * made it (web, REST, GraphQL, MCP, imports, commands): once per record per
 * transaction, after it commits, never on rollback (see DomainHooks).
 */
final class WarehouseObserver
{
    public function __construct(private readonly DomainHooks $hooks) {}

    public function created(Warehouse $warehouse): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('warehouse_created', $warehouse, fn () => do_action('warehouse_created', $warehouse, $user));
    }

    public function updated(Warehouse $warehouse): void
    {
        // Part of its creation when that is still waiting to be announced.
        if ($this->hooks->isPending('warehouse_created', $warehouse)) {
            return;
        }

        $user = auth()->user();

        $this->hooks->afterCommit('warehouse_updated', $warehouse, fn () => do_action('warehouse_updated', $warehouse, $user));
    }

    public function deleted(Warehouse $warehouse): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('warehouse_deleted', $warehouse, fn () => do_action('warehouse_deleted', $warehouse, $user));
    }
}
