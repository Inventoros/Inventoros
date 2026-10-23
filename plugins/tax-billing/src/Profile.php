<?php

declare(strict_types=1);

namespace Inventoros\Plugins\TaxBilling;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Profile
{
    public const KEY = 'tax-billing.profile';

    public const DEFAULTS = [
        'mode' => 'generic', 'country' => '', 'issuer_name' => '', 'issuer_address' => '',
        'issuer_tax_id' => '', 'region_code' => '', 'tax_id_label' => 'Tax registration',
        'gst_rate' => '0', 'size_option' => 'Size', 'shipping_taxable' => false,
        'local_tax_label' => 'SGST',
        'components' => [['label' => 'Tax', 'rate' => '0']],
    ];

    public static function get(int $organizationId): array
    {
        $stored = json_decode((string) SettingsService::get(self::KEY, '{}', $organizationId), true);

        return array_replace(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function validate(array $data): array
    {
        $validated = Validator::make($data, [
            'mode' => ['required', Rule::in(['generic', 'india_gst'])],
            'country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'issuer_name' => ['required', 'string', 'max:255'],
            'issuer_address' => ['required', 'string', 'max:2000'],
            'issuer_tax_id' => ['nullable', 'string', 'max:80'],
            'region_code' => ['nullable', 'string', 'max:80'],
            'tax_id_label' => ['required', 'string', 'max:50'],
            'size_option' => ['required', 'string', 'max:50'],
            'shipping_taxable' => ['required', 'boolean'],
            'gst_rate' => ['required', 'numeric', 'decimal:0,4', 'between:0,100'],
            'local_tax_label' => ['required', Rule::in(['SGST', 'UTGST'])],
            'components' => ['required', 'array', 'min:1', 'max:10'],
            'components.*.label' => ['required', 'string', 'max:50', 'distinct'],
            'components.*.rate' => ['required', 'numeric', 'decimal:0,4', 'between:0,100'],
        ])->validate();

        if ($validated['mode'] === 'india_gst') {
            Validator::make($validated, [
                'country' => ['in:IN'],
                'region_code' => ['required', 'regex:/^[0-9]{2}$/'],
                'issuer_tax_id' => ['required', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            ])->after(function ($validator) use ($validated) {
                if (substr((string) $validated['issuer_tax_id'], 0, 2) !== $validated['region_code']) {
                    $validator->errors()->add('issuer_tax_id', 'The GSTIN state code must match the supplier region.');
                }
            })->validate();
            $validated['tax_id_label'] = 'GSTIN';
        }

        return $validated;
    }
}
