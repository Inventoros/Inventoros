<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\ProductCategory;
use App\Services\Hooks\DomainHooks;

/**
 * Fires the category_created, category_updated and category_deleted actions
 * from the model lifecycle, so plugins see the change whichever surface
 * made it (web, REST, GraphQL, MCP, imports, commands): once per record per
 * transaction, after it commits, never on rollback (see DomainHooks).
 */
final class ProductCategoryObserver
{
    public function __construct(private readonly DomainHooks $hooks) {}

    public function created(ProductCategory $category): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('category_created', $category, fn () => do_action('category_created', $category, $user));
    }

    public function updated(ProductCategory $category): void
    {
        // Part of its creation when that is still waiting to be announced.
        if ($this->hooks->isPending('category_created', $category)) {
            return;
        }

        $user = auth()->user();

        $this->hooks->afterCommit('category_updated', $category, fn () => do_action('category_updated', $category, $user));
    }

    public function deleted(ProductCategory $category): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('category_deleted', $category, fn () => do_action('category_deleted', $category, $user));
    }
}
