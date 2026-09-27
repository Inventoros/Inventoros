<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shipping\ShippingSetting;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Shipping: EasyPost credentials and mode, the default ship-from
 * address and parcel, and customer shipment emails. Stored secrets are never
 * sent to the browser: the page gets "<field>_set" flags, and a blank field on
 * save keeps the stored value (the same contract as the email settings).
 */
class ShippingSettingsController extends Controller
{
    private const SECRETS = ['easypost_api_key', 'easypost_test_api_key', 'easypost_webhook_secret'];

    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;
        $settings = ShippingSetting::forOrganization($organizationId);

        return Inertia::render('Settings/Shipping', [
            'settings' => [
                'easypost_enabled' => $settings->easypost_enabled,
                'easypost_test_mode' => $settings->easypost_test_mode,
                'easypost_api_key_set' => filled($settings->easypost_api_key),
                'easypost_test_api_key_set' => filled($settings->easypost_test_api_key),
                'easypost_webhook_secret_set' => filled($settings->easypost_webhook_secret),
                'default_warehouse_id' => $settings->default_warehouse_id,
                'from_address' => $settings->from_address ?? (object) [],
                'default_parcel' => $settings->default_parcel ?? (object) [],
                'notify_customers' => $settings->notify_customers,
            ],
            'webhookUrl' => route('webhooks.easypost', $settings->webhook_token),
            'warehouses' => Warehouse::where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $settings = ShippingSetting::forOrganization($organizationId);

        $validator = validator($request->all(), [
            'easypost_enabled' => ['required', 'boolean'],
            'easypost_test_mode' => ['required', 'boolean'],
            'easypost_api_key' => ['nullable', 'string', 'max:255'],
            'easypost_test_api_key' => ['nullable', 'string', 'max:255'],
            'easypost_webhook_secret' => ['nullable', 'string', 'max:255'],
            'default_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'from_address' => ['nullable', 'array'],
            'from_address.name' => ['nullable', 'string', 'max:255'],
            'from_address.company' => ['nullable', 'string', 'max:255'],
            'from_address.street1' => ['nullable', 'string', 'max:255'],
            'from_address.street2' => ['nullable', 'string', 'max:255'],
            'from_address.city' => ['nullable', 'string', 'max:255'],
            'from_address.state' => ['nullable', 'string', 'max:100'],
            'from_address.zip' => ['nullable', 'string', 'max:20'],
            'from_address.country' => ['nullable', 'string', 'size:2'],
            'from_address.phone' => ['nullable', 'string', 'max:50'],
            'from_address.email' => ['nullable', 'email', 'max:255'],
            'default_parcel' => ['nullable', 'array'],
            'default_parcel.weight_oz' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'default_parcel.length_in' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'default_parcel.width_in' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'default_parcel.height_in' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'notify_customers' => ['required', 'boolean'],
        ]);

        // Turning EasyPost on needs a key for the chosen mode, either submitted
        // now or already stored.
        $validator->after(function (Validator $v) use ($request, $settings) {
            if (! $request->boolean('easypost_enabled')) {
                return;
            }

            $field = $request->boolean('easypost_test_mode') ? 'easypost_test_api_key' : 'easypost_api_key';

            if (blank($request->input($field)) && blank($settings->{$field})) {
                $v->errors()->add($field, $request->boolean('easypost_test_mode')
                    ? 'Enter your EasyPost test API key to use test mode.'
                    : 'Enter your EasyPost production API key.');
            }
        });

        $validated = $validator->validate();

        $settings->fill([
            'easypost_enabled' => (bool) $validated['easypost_enabled'],
            'easypost_test_mode' => (bool) $validated['easypost_test_mode'],
            'default_warehouse_id' => $validated['default_warehouse_id'] ?? null,
            'from_address' => $this->filled($validated['from_address'] ?? []),
            'default_parcel' => $this->filled($validated['default_parcel'] ?? []),
            'notify_customers' => (bool) $validated['notify_customers'],
        ]);

        foreach (self::SECRETS as $secret) {
            if (filled($validated[$secret] ?? null)) {
                $settings->{$secret} = trim((string) $validated[$secret]);
            }
        }

        $settings->save();

        return redirect()->back()->with('success', 'Shipping settings saved.');
    }

    /**
     * Issue a new webhook URL token (the old URL stops working).
     */
    public function rotateWebhookToken(Request $request): RedirectResponse
    {
        $settings = ShippingSetting::forOrganization((int) $request->user()->organization_id);
        $settings->forceFill(['webhook_token' => ShippingSetting::newWebhookToken()])->save();

        return redirect()->back()->with('success', 'Webhook URL regenerated. Update it in your EasyPost dashboard.');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function filled(array $values): ?array
    {
        $kept = array_filter($values, fn ($v) => $v !== null && $v !== '');

        return $kept === [] ? null : $kept;
    }
}
