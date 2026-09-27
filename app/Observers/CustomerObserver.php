<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Fires customer lifecycle action hooks from the model lifecycle so webhook
 * subscribers observe them from every surface (web, REST, GraphQL, MCP), and
 * only once the surrounding transaction commits.
 */
final class CustomerObserver
{
    public function created(Customer $customer): void
    {
        DB::afterCommit(fn () => do_action('customer_created', $customer, auth()->user()));
    }

    public function updated(Customer $customer): void
    {
        DB::afterCommit(fn () => do_action('customer_updated', $customer, auth()->user()));
    }

    public function deleted(Customer $customer): void
    {
        DB::afterCommit(fn () => do_action('customer_deleted', $customer, auth()->user()));
    }
}
