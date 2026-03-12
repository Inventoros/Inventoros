<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.5;
        }
        .invoice-container {
            padding: 40px;
        }
        /* Header */
        .header {
            border-bottom: 3px solid #2563eb;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header-table {
            width: 100%;
        }
        .header-table td {
            vertical-align: top;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }
        .company-details {
            font-size: 11px;
            color: #6b7280;
            line-height: 1.6;
        }
        .invoice-title {
            font-size: 32px;
            font-weight: bold;
            color: #1e40af;
            text-align: right;
        }
        .invoice-number {
            font-size: 14px;
            color: #6b7280;
            text-align: right;
            margin-top: 5px;
        }
        /* Info Section */
        .info-section {
            margin-bottom: 30px;
        }
        .info-table {
            width: 100%;
        }
        .info-table td {
            vertical-align: top;
            width: 50%;
        }
        .info-label {
            font-size: 10px;
            font-weight: bold;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .info-value {
            font-size: 12px;
            color: #374151;
            line-height: 1.6;
        }
        .info-value strong {
            display: block;
            font-size: 13px;
            color: #111827;
        }
        /* Items Table */
        .items-section {
            margin-bottom: 30px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table th {
            background-color: #2563eb;
            color: #ffffff;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .items-table th:last-child,
        .items-table th:nth-child(3),
        .items-table th:nth-child(4) {
            text-align: right;
        }
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
        }
        .items-table td:last-child,
        .items-table td:nth-child(3),
        .items-table td:nth-child(4) {
            text-align: right;
        }
        .items-table tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .item-name {
            font-weight: 600;
            color: #111827;
        }
        .item-sku {
            font-size: 10px;
            color: #9ca3af;
        }
        /* Totals */
        .totals-section {
            margin-bottom: 40px;
        }
        .totals-table {
            width: 300px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 6px 12px;
            font-size: 12px;
        }
        .totals-table td:first-child {
            color: #6b7280;
            text-align: right;
        }
        .totals-table td:last-child {
            text-align: right;
            font-weight: 500;
            color: #374151;
        }
        .total-row td {
            border-top: 2px solid #2563eb;
            padding-top: 10px;
            font-size: 16px;
            font-weight: bold;
        }
        .total-row td:last-child {
            color: #1e40af;
        }
        /* Footer */
        .footer {
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
        }
        .footer p {
            margin-bottom: 3px;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <table class="header-table">
                <tr>
                    <td style="width: 60%;">
                        <div class="company-name">{{ $organization->name }}</div>
                        <div class="company-details">
                            @if($organization->address){{ $organization->address }}<br>@endif
                            @if($organization->city || $organization->state || $organization->zip)
                                {{ $organization->city }}@if($organization->city && $organization->state), @endif{{ $organization->state }} {{ $organization->zip }}<br>
                            @endif
                            @if($organization->country){{ $organization->country }}<br>@endif
                            @if($organization->email){{ $organization->email }}@endif
                            @if($organization->phone) | {{ $organization->phone }}@endif
                        </div>
                    </td>
                    <td style="width: 40%;">
                        <div class="invoice-title">INVOICE</div>
                        <div class="invoice-number">{{ $invoiceNumber }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Bill To / Invoice Details -->
        <div class="info-section">
            <table class="info-table">
                <tr>
                    <td>
                        <div class="info-label">Bill To</div>
                        <div class="info-value">
                            <strong>{{ $order->customer_name }}</strong>
                            @if($order->customer_email){{ $order->customer_email }}<br>@endif
                            @if($order->customer_address){{ $order->customer_address }}@endif
                        </div>
                    </td>
                    <td>
                        <div class="info-label">Invoice Details</div>
                        <div class="info-value">
                            <strong>Invoice Date:</strong> {{ $generatedDate }}<br>
                            <strong>Order Date:</strong> {{ $order->order_date ? $order->order_date->format('F j, Y') : '-' }}<br>
                            <strong>Order #:</strong> {{ $order->order_number }}<br>
                            <strong>Currency:</strong> {{ $order->currency ?? 'USD' }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Line Items -->
        <div class="items-section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Item</th>
                        <th style="width: 15%;">Qty</th>
                        <th style="width: 20%;">Unit Price</th>
                        <th style="width: 20%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                    <tr>
                        <td>
                            <div class="item-name">{{ $item->product_name }}</div>
                            @if($item->sku)<div class="item-sku">SKU: {{ $item->sku }}</div>@endif
                        </td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $order->currency ?? 'USD' }} {{ number_format((float) $item->unit_price, 2) }}</td>
                        <td>{{ $order->currency ?? 'USD' }} {{ number_format((float) $item->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Subtotal</td>
                    <td>{{ $order->currency ?? 'USD' }} {{ number_format((float) $order->subtotal, 2) }}</td>
                </tr>
                @if((float) $order->tax > 0)
                <tr>
                    <td>Tax</td>
                    <td>{{ $order->currency ?? 'USD' }} {{ number_format((float) $order->tax, 2) }}</td>
                </tr>
                @endif
                @if((float) $order->shipping > 0)
                <tr>
                    <td>Shipping</td>
                    <td>{{ $order->currency ?? 'USD' }} {{ number_format((float) $order->shipping, 2) }}</td>
                </tr>
                @endif
                <tr class="total-row">
                    <td>Total</td>
                    <td>{{ $order->currency ?? 'USD' }} {{ number_format((float) $order->total, 2) }}</td>
                </tr>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Generated on {{ $generatedDate }}</p>
            <p>{{ $organization->name }}@if($organization->email) &mdash; {{ $organization->email }}@endif</p>
            <p>Thank you for your business!</p>
        </div>
    </div>
</body>
</html>
