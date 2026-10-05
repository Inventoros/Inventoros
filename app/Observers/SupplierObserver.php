<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\Supplier;
use App\Services\Hooks\DomainHooks;

/**
 * Fires the supplier_created, supplier_updated and supplier_deleted actions
 * from the model lifecycle, so plugins see the change whichever surface
 * made it (web, REST, GraphQL, MCP, imports, commands): once per record per
 * transaction, after it commits, never on rollback (see DomainHooks).
 * supplier_before_delete fires synchronously, just before the delete.
 */
final class SupplierObserver
{
    public function __construct(private readonly DomainHooks $hooks) {}

    public function created(Supplier $supplier): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('supplier_created', $supplier, fn () => do_action('supplier_created', $supplier, $user));
    }

    public function updated(Supplier $supplier): void
    {
        // Part of its creation when that is still waiting to be announced.
        if ($this->hooks->isPending('supplier_created', $supplier)) {
            return;
        }

        $user = auth()->user();

        $this->hooks->afterCommit('supplier_updated', $supplier, fn () => do_action('supplier_updated', $supplier, $user));
    }

    public function deleting(Supplier $supplier): void
    {
        do_action('supplier_before_delete', $supplier, auth()->user());
    }

    public function deleted(Supplier $supplier): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('supplier_deleted', $supplier, fn () => do_action('supplier_deleted', $supplier, $user));
    }
}
