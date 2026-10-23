<?php

use App\Http\Middleware\EnsurePluginActive;
use App\Models\Plugin;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\TaxBilling\Controller;
use Inventoros\Plugins\TaxBilling\Invoices;
use Inventoros\Plugins\TaxBilling\Profile;

require_once __DIR__.'/src/Profile.php';
require_once __DIR__.'/src/Invoices.php';
require_once __DIR__.'/src/Controller.php';

return function (): void {
    app('view')->addNamespace('tax-billing', __DIR__.'/views');
    foreach (['issue' => 'Issue tax invoices', 'manage' => 'Manage tax billing profiles'] as $ability => $label) {
        register_permission('tax-billing.'.$ability, $label, 'Tax billing');
    }

    Route::middleware(['web', 'auth', EnsurePluginActive::class.':tax-billing'])->group(function () {
        Route::post('/p/tax-billing/settings', [Controller::class, 'save'])->middleware('permission:tax-billing.manage')->name('plg.tax-billing.save');
        Route::get('/p/tax-billing/orders/{orderId}', [Controller::class, 'edit'])->whereNumber('orderId')->middleware('permission:view_orders')->name('plg.tax-billing.invoice');
        Route::post('/p/tax-billing/orders/{orderId}/issue', [Controller::class, 'issue'])->whereNumber('orderId')->middleware('permission:view_orders|tax-billing.issue,all')->name('plg.tax-billing.issue');
        Route::get('/p/tax-billing/orders/{orderId}/download', [Controller::class, 'download'])->whereNumber('orderId')->middleware('permission:view_orders')->name('plg.tax-billing.download');
    });

    register_page('plg.tax-billing.settings', 'Plugin::tax-billing/Settings', [
        'uri' => '/p/tax-billing/settings', 'title' => 'Tax billing', 'permission' => 'tax-billing.manage',
        'middleware' => [EnsurePluginActive::class.':tax-billing'],
        'props' => fn ($request, $user) => ['profile' => Profile::get((int) $user->organization_id), 'saveUrl' => route('plg.tax-billing.save')],
    ]);

    register_menu_item(['label' => 'Tax billing', 'route' => 'plg.tax-billing.settings', 'icon' => 'FileText', 'permission' => 'tax-billing.manage', 'group' => 'settings']);
    add_page_component('orders.show', 'actions', [
        'plugin' => 'tax-billing', 'component' => 'InvoiceLink', 'permission' => 'view_orders',
        'data' => fn ($user) => ['url' => route('plg.tax-billing.invoice', ['orderId' => request()->route('order')?->id])],
    ]);

    foreach (['customers.create', 'customers.edit'] as $page) {
        add_page_component($page, 'business-details', ['plugin' => 'tax-billing', 'component' => 'CustomerFields', 'data' => fn ($user) => ['mode' => Profile::get((int) $user->organization_id)['mode']]]);
    }
    add_page_component('customers.show', 'business-details', ['plugin' => 'tax-billing', 'component' => 'CustomerTaxDetails']);
    add_filter('customer_form_data', function ($values, $customer) {
        return $values + ['tax_billing' => ['region_code' => $customer?->metadata['tax_billing']['region_code'] ?? '']];
    });
    foreach (['customer_store_validation_rules', 'customer_update_validation_rules'] as $hook) {
        add_filter($hook, function ($rules) {
            $profile = Profile::get((int) auth()->user()->organization_id);
            if ($profile['mode'] === 'india_gst') {
                $rules['tax_id'] = ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i'];
            }

            return $rules + ['plugin_fields.tax_billing.region_code' => $profile['mode'] === 'india_gst'
                ? ['nullable', 'regex:/^[0-9]{2}$/'] : ['nullable', 'string', 'max:80']];
        });
    }
    add_filter('customer_store_data', function ($data) {
        if (isset($data['tax_id']) && Profile::get((int) auth()->user()->organization_id)['mode'] === 'india_gst') {
            $data['tax_id'] = strtoupper(trim($data['tax_id']));
        }
        if (isset($data['plugin_fields']['tax_billing'])) {
            $data['metadata'] = array_replace($data['metadata'] ?? [], ['tax_billing' => $data['plugin_fields']['tax_billing']]);
        }

        return $data;
    });
    add_filter('customer_update_data', function ($data, $customer) {
        if (isset($data['tax_id']) && Profile::get((int) $customer->organization_id)['mode'] === 'india_gst') {
            $data['tax_id'] = strtoupper(trim($data['tax_id']));
        }
        if (isset($data['plugin_fields']['tax_billing'])) {
            $data['metadata'] = array_replace($customer->metadata ?? [], $data['metadata'] ?? [], ['tax_billing' => $data['plugin_fields']['tax_billing']]);
        }

        return $data;
    });

    add_filter('order_invoice_document', function ($document, $provider, $order) {
        if ($provider === 'tax-billing' && isset($order->metadata[Invoices::SNAPSHOT])
            && Plugin::where('slug', 'tax-billing')->where('is_active', true)->exists()) {
            return ['view' => 'tax-billing::invoice', 'data' => ['invoice' => $order->metadata[Invoices::SNAPSHOT]]];
        }

        return $document;
    });

};
