<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice['number'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        p { margin: 4px 0; }
        .address { white-space: pre-line; }
        .muted { color: #556171; }
        table { border-collapse: collapse; width: 100%; margin-top: 14px; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { padding: 7px 5px; border-bottom: 1px solid #d5dce5; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background: #eff3f8; font-size: 9px; }
        .number { text-align: right; }
        .totals { margin: 20px 0 0 45%; width: 55%; }
        .total { font-size: 13px; font-weight: bold; }
        .size { display: inline-block; border: 1px solid #c3ccd8; padding: 2px 5px; margin: 3px 3px 0 0; }
        .small { font-size: 8px; }
    </style>
</head>
<body>
    <h1>{{ $invoice['profile']['mode'] === 'india_gst' ? 'TAX INVOICE' : 'INVOICE' }}</h1>
    <p><strong>{{ $invoice['number'] }}</strong> · {{ $invoice['issued_date'] }}</p>
    <p class="muted">Order {{ $invoice['order_number'] }} · Currency {{ $invoice['currency'] }}</p>
    <h2>{{ $invoice['profile']['issuer_name'] }}</h2>
    <p class="address">{{ $invoice['profile']['issuer_address'] }}</p>
    @if($invoice['profile']['issuer_tax_id'])
        <p>{{ $invoice['profile']['tax_id_label'] }}: {{ $invoice['profile']['issuer_tax_id'] }}</p>
    @endif
    <h2>Customer</h2>
    <p><strong>{{ $invoice['customer_name'] }}</strong></p>
    <p class="address">{{ $invoice['customer_address'] }}</p>
    @if($invoice['delivery_address'] && $invoice['delivery_address'] !== $invoice['customer_address'])
        <p class="address">Deliver to: {{ $invoice['delivery_address'] }}</p>
    @endif
    @if($invoice['customer_tax_id'])
        <p>{{ $invoice['profile']['tax_id_label'] }}: {{ $invoice['customer_tax_id'] }}</p>
    @endif
    <p>Place of supply: {{ $invoice['supply_country'] }} {{ $invoice['region_code'] }}</p>
    <table>
        <thead><tr>
            <th style="width:40%">Item / size quantities</th>
            <th style="width:14%">HSN/SAC</th>
            <th style="width:12%" class="number">Quantity</th>
            <th style="width:16%" class="number">Unit price</th>
            <th style="width:18%" class="number">After discounts</th>
        </tr></thead>
        <tbody>
        @foreach($invoice['rows'] as $row)
            <tr>
                <td><strong>{{ $row['name'] }}</strong><br>
                    @foreach($row['options'] as $option => $value)<span class="muted">{{ $option }}: {{ $value }}</span><br>@endforeach
                    @foreach($row['sizes'] as $size => $quantity)<span class="size">{{ $size }}: {{ $quantity }}</span>@endforeach
                    <p class="muted small">{{ implode(', ', array_filter($row['skus'])) }}</p>
                </td>
                <td>{{ $row['hsn_code'] ?: '-' }}</td>
                <td class="number">{{ $row['quantity'] }} {{ $row['unit'] }}</td>
                <td class="number">{{ $row['unit_price'] }}</td>
                <td class="number">{{ $row['amount'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Gross subtotal</td><td class="number">{{ $invoice['subtotal'] }}</td></tr>
        <tr><td>Discounts</td><td class="number">-{{ $invoice['discount'] }}</td></tr>
        <tr><td>Taxable value{{ $invoice['profile']['shipping_taxable'] ? ' (including shipping)' : '' }}</td><td class="number">{{ $invoice['taxable_value'] }}</td></tr>
        @foreach($invoice['components'] as $component)
            <tr><td>{{ $component['label'] }} {{ rtrim(rtrim(number_format((float) $component['rate'], 4, '.', ''), '0'), '.') }}%</td><td class="number">{{ $component['amount'] }}</td></tr>
        @endforeach
        <tr><td>Shipping</td><td class="number">{{ $invoice['shipping'] }}</td></tr>
        <tr class="total"><td>Total {{ $invoice['currency'] }}</td><td class="number">{{ $invoice['total'] }}</td></tr>
    </table>
    <p class="muted small">Tax registration details and tax treatment are supplied by the issuer. This document does not constitute a government e-invoice acknowledgement or a tax return.</p>
</body>
</html>
