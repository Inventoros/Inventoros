<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Customer;
use App\Services\Hooks\DomainHooks;

/**
 * Fires customer lifecycle action hooks from the model lifecycle so webhook
 * subscribers observe them from every surface (web, REST, GraphQL, MCP), and
 * only once the surrounding transaction commits.
 */
final class CustomerObserver
{
    public function __construct(private readonly DomainHooks $hooks) {}

    public function created(Customer $customer): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('customer_created', $customer, fn () => do_action('customer_created', $customer, $user));
    }

    public function updated(Customer $customer): void
    {
        if ($this->hooks->isPending('customer_created', $customer)) {
            return;
        }

        $user = auth()->user();

        $this->hooks->afterCommit('customer_updated', $customer, fn () => do_action('customer_updated', $customer, $user));
    }

    public function deleted(Customer $customer): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('customer_deleted', $customer, fn () => do_action('customer_deleted', $customer, $user));
    }
}
