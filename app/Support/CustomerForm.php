<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use Illuminate\Http\Request;

final class CustomerForm
{
    /** Only validated extension fields reach plugins, on both web and REST. */
    public static function payload(array $validated, Request $request, ?Customer $customer = null): array
    {
        $data = $customer === null
            ? apply_filters('customer_store_data', $validated, $request)
            : apply_filters('customer_update_data', $validated, $customer, $request);
        unset($data['plugin_fields']);
        $data['organization_id'] = $customer?->organization_id ?? $request->user()->organization_id;

        return $data;
    }
}
