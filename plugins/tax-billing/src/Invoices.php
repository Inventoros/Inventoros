<?php

declare(strict_types=1);

namespace Inventoros\Plugins\TaxBilling;

use App\Models\Order\Order;
use App\Services\Documents\OrderInvoiceNumberService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Invoices
{
    public const SNAPSHOT = 'tax_billing_invoice';

    public function issue(Order $order, array $input, int $userId): array
    {
        return DB::transaction(function () use ($order, $input, $userId) {
            $locked = Order::where('organization_id', $order->organization_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (isset($locked->metadata[self::SNAPSHOT])) {
                return $locked->metadata[self::SNAPSHOT];
            }
            if ($locked->status->value === 'cancelled' || in_array($locked->getRawOriginal('approval_status'), ['pending', 'rejected'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Cancelled orders and orders awaiting approval cannot be invoiced.']);
            }
            $profile = Profile::validate(Profile::get((int) $locked->organization_id));
            $locked->load(['items.variant' => fn ($query) => $query->withTrashed(), 'customer' => fn ($query) => $query->withTrashed()]);
            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages(['invoice' => 'An invoice needs at least one order line.']);
            }
            $rows = $this->rows($locked, $profile, $input);
            $region = trim((string) ($input['region_code'] ?? ''));
            $customerTaxId = trim((string) ($locked->customer?->tax_id ?? ''));
            $billingAddress = $locked->customer?->billing_address
                ? implode("\n", array_filter([$locked->customer->billing_address, $locked->customer->billing_city, $locked->customer->billing_state, $locked->customer->billing_zip_code, $locked->customer->billing_country]))
                : $locked->customer_address;
            $base = Money::subtract($locked->subtotal, $locked->discount_amount);
            if ($profile['shipping_taxable']) {
                $base = Money::add($base, $locked->shipping);
            }
            if (Money::isNegative($base) || Money::isNegative($locked->tax) || Money::isNegative($locked->shipping)
                || Money::compare(Money::add(Money::subtract($locked->subtotal, $locked->discount_amount), $locked->tax, $locked->shipping), $locked->total) !== 0
                || Money::compare(Money::add(...array_column($rows, 'amount')), Money::subtract($locked->subtotal, $locked->discount_amount)) !== 0) {
                throw ValidationException::withMessages(['invoice' => 'The recorded order totals do not reconcile with its lines. Correct the order before issuing.']);
            }
            $components = $profile['components'];
            if ($profile['mode'] === 'india_gst') {
                if (blank($billingAddress)) {
                    throw ValidationException::withMessages(['invoice' => 'Add the customer address before issuing an India GST invoice.']);
                }
                if (($input['supply_country'] ?? '') !== 'IN') {
                    throw ValidationException::withMessages(['supply_country' => 'The India GST profile supports domestic supplies. Exports and special supplies need a dedicated profile.']);
                }
                if (! preg_match('/^[0-9]{2}$/', $region)) {
                    throw ValidationException::withMessages(['region_code' => 'Enter the two-digit place-of-supply state code.']);
                }
                if ($customerTaxId !== '' && ! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $customerTaxId)) {
                    throw ValidationException::withMessages(['invoice' => 'Correct the customer GSTIN in Business Details before issuing.']);
                }
                $half = bcdiv((string) $profile['gst_rate'], '2', 5);
                $components = $region === $profile['region_code']
                    ? [['label' => 'CGST', 'rate' => $half], ['label' => $profile['local_tax_label'], 'rate' => $half]]
                    : [['label' => 'IGST', 'rate' => $profile['gst_rate']]];
            }
            $rate = '0';
            foreach ($components as $component) {
                $rate = bcadd($rate, (string) $component['rate'], 5);
            }
            $expected = Money::percentOf($base, $rate);
            if (Money::compare(Money::subtract($locked->tax, $expected), '0.01') > 0
                || Money::compare(Money::subtract($expected, $locked->tax), '0.01') > 0) {
                throw ValidationException::withMessages(['invoice' => 'The recorded order tax does not match this profile. Correct the order or choose the applicable tax profile before issuing.']);
            }
            $components = $this->allocateTax($components, Money::of($locked->tax), $rate);
            $number = app(OrderInvoiceNumberService::class)->ensureAssigned($locked);
            if ($profile['mode'] === 'india_gst' && ! preg_match('/^[A-Za-z0-9\/-]{1,16}$/', $number)) {
                throw ValidationException::withMessages(['invoice' => 'The invoice number must use at most 16 letters, numbers, slashes or hyphens for India GST.']);
            }
            $snapshot = [
                'number' => $number, 'issued_at' => now()->toIso8601String(), 'issued_by' => $userId,
                'issued_date' => now()->timezone($locked->organization?->timezone ?? 'UTC')->toDateString(),
                'order_number' => $locked->order_number, 'profile' => $profile,
                'customer_name' => $locked->customer_name, 'customer_address' => $billingAddress,
                'delivery_address' => $locked->customer_address,
                'customer_tax_id' => $customerTaxId, 'region_code' => $region,
                'supply_country' => $input['supply_country'] ?? $profile['country'],
                'currency' => $locked->currency, 'rows' => $rows, 'components' => $components,
                'subtotal' => Money::of($locked->subtotal), 'discount' => Money::of($locked->discount_amount),
                'taxable_value' => $base, 'tax' => Money::of($locked->tax),
                'shipping' => Money::of($locked->shipping), 'total' => Money::of($locked->total),
            ];
            $locked->forceFill(['metadata' => array_replace($locked->metadata ?? [], [self::SNAPSHOT => $snapshot, 'invoice_document_provider' => 'tax-billing'])])->save();

            return $snapshot;
        });
    }

    private function rows(Order $order, array $profile, array $input): array
    {
        $rows = [];
        foreach ($order->items as $item) {
            $details = $input['items'][$item->id] ?? [];
            $hsn = trim((string) ($details['hsn_code'] ?? ''));
            if ($profile['mode'] === 'india_gst' && ! preg_match('/^(?:[0-9]{4}|[0-9]{6}|[0-9]{8})$/', $hsn)) {
                throw ValidationException::withMessages(["items.{$item->id}.hsn_code" => 'Enter a 4, 6 or 8-digit HSN/SAC code.']);
            }
            if ($item->quantity < 1 || Money::isNegative($item->unit_price) || Money::isNegative($item->discount_amount)
                || Money::compare($item->discount_amount, $item->subtotal) > 0
                || Money::compare(Money::multiply($item->unit_price, $item->quantity), $item->subtotal) !== 0) {
                throw ValidationException::withMessages(['invoice' => 'The recorded invoice lines contain inconsistent quantities or amounts.']);
            }
            $unit = trim((string) ($details['unit'] ?? 'NOS'));
            $size = '';
            $otherOptions = [];
            foreach ($item->variant?->option_values ?? [] as $key => $value) {
                if (mb_strtolower($key) === mb_strtolower($profile['size_option'])) {
                    $size = (string) $value;
                } else {
                    $otherOptions[$key] = $value;
                }
            }
            ksort($otherOptions);
            $key = json_encode([$item->product_id ?? $item->id, $item->unit_price, $hsn, $unit, $otherOptions]);
            $rows[$key] ??= [
                'name' => $item->product_name, 'hsn_code' => $hsn, 'unit' => $unit,
                'options' => $otherOptions,
                'unit_price' => $item->unit_price, 'quantity' => 0, 'amount' => '0.00', 'sizes' => [], 'skus' => [],
            ];
            $rows[$key]['quantity'] += $item->quantity;
            $rows[$key]['amount'] = Money::add($rows[$key]['amount'], Money::subtract($item->subtotal, $item->discount_amount));
            $rows[$key]['sizes'][$size ?: 'Standard'] = ($rows[$key]['sizes'][$size ?: 'Standard'] ?? 0) + $item->quantity;
            $rows[$key]['skus'][] = $item->sku;
        }

        $rows = array_values($rows);
        $discount = $order->orderDiscountAmount();
        if (! Money::isZero($discount)) {
            $net = Money::add(...array_column($rows, 'amount'));
            if (Money::compare($discount, $net) > 0 || Money::isNegative($discount)) {
                throw ValidationException::withMessages(['invoice' => 'The order discount exceeds the invoice lines.']);
            }
            $allocations = $this->allocateTax(array_map(fn ($row) => ['rate' => $row['amount']], $rows), $discount, $net);
            foreach ($rows as $index => &$row) {
                $row['amount'] = Money::subtract($row['amount'], $allocations[$index]['amount']);
            }
        }

        return $rows;
    }

    private function allocateTax(array $components, string $tax, string $rate): array
    {
        if (bccomp($rate, '0', 5) === 0 && ! Money::isZero($tax)) {
            throw ValidationException::withMessages(['invoice' => 'A zero-rate profile requires zero recorded tax.']);
        }
        $remaining = (int) bcmul($tax, '100', 0);
        $shares = [];
        foreach ($components as $index => $component) {
            $raw = bccomp($rate, '0', 5) === 0 ? '0' : bcdiv(bcmul($tax, bcmul((string) $component['rate'], '100', 8), 8), $rate, 8);
            $whole = (int) $raw;
            $shares[$index] = ['cents' => $whole, 'fraction' => bcsub($raw, (string) $whole, 8)];
            $remaining -= $whole;
        }
        $indices = array_keys($shares);
        usort($indices, fn ($a, $b) => bccomp($shares[$b]['fraction'], $shares[$a]['fraction'], 8) ?: $a <=> $b);
        for ($index = 0; $index < $remaining; $index++) {
            $shares[$indices[$index % count($indices)]]['cents']++;
        }
        foreach ($components as $index => &$component) {
            $component['amount'] = bcdiv((string) $shares[$index]['cents'], '100', 2);
        }

        return $components;
    }
}
