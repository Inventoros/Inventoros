<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\ProductLocation;
use App\Services\Hooks\DomainHooks;

/**
 * Fires the location_created, location_updated and location_deleted actions
 * from the model lifecycle, so plugins see the change whichever surface
 * made it (web, REST, GraphQL, MCP, imports, commands): once per record per
 * transaction, after it commits, never on rollback (see DomainHooks).
 */
final class ProductLocationObserver
{
    public function __construct(private readonly DomainHooks $hooks) {}

    public function created(ProductLocation $location): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('location_created', $location, fn () => do_action('location_created', $location, $user));
    }

    public function updated(ProductLocation $location): void
    {
        // Part of its creation when that is still waiting to be announced.
        if ($this->hooks->isPending('location_created', $location)) {
            return;
        }

        $user = auth()->user();

        $this->hooks->afterCommit('location_updated', $location, fn () => do_action('location_updated', $location, $user));
    }

    public function deleted(ProductLocation $location): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('location_deleted', $location, fn () => do_action('location_deleted', $location, $user));
    }
}
