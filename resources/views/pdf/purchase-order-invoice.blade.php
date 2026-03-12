<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $purchaseOrder->po_number }}</title>
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
            border-bottom: 3px solid #059669;
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
            color: #065f46;
            margin-bottom: 5px;
        }
        .company-details {
            font-size: 11px;
            color: #6b7280;
            line-height: 1.6;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #065f46;
            text-align: right;
        }
        .po-number {
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
            background-color: #059669;
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
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-draft { background-color: #f3f4f6; color: #6b7280; }
        .status-sent { background-color: #dbeafe; color: #2563eb; }
        .status-partial { background-color: #fef3c7; color: #d97706; }
        .status-received { background-color: #d1fae5; color: #059669; }
        .status-cancelled { background-color: #fee2e2; color: #dc2626; }
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
            border-top: 2px solid #059669;
            padding-top: 10px;
            font-size: 16px;
            font-weight: bold;
        }
        .total-row td:last-child {
            color: #065f46;
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
        /* Notes */
        .notes-section {
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }
        .notes-label {
            font-size: 11px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .notes-text {
            font-size: 12px;
            color: #374151;
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
                        <div class="invoice-title">PURCHASE ORDER</div>
                        <div class="po-number">{{ $purchaseOrder->po_number }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Supplier / PO Details -->
        <div class="info-section">
            <table class="info-table">
                <tr>
                    <td>
                        <div class="info-label">Supplier</div>
                        <div class="info-value">
                            @if($supplier)
                                <strong>{{ $supplier->name }}</strong>
                                @if($supplier->contact_name){{ $supplier->contact_name }}<br>@endif
                                @if($supplier->email){{ $supplier->email }}<br>@endif
                                @if($supplier->phone){{ $supplier->phone }}<br>@endif
                                @if($supplier->address){{ $supplier->address }}<br>@endif
                                @if($supplier->city || $supplier->state || $supplier->zip_code)
                                    {{ $supplier->city }}@if($supplier->city && $supplier->state), @endif{{ $supplier->state }} {{ $supplier->zip_code }}<br>
                                @endif
                                @if($supplier->country){{ $supplier->country }}@endif
                            @else
                                <em>No supplier information</em>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div class="info-label">Order Details</div>
                        <div class="info-value">
                            <strong>Status:</strong>
                            <span class="status-badge status-{{ $purchaseOrder->status }}">{{ ucfirst($purchaseOrder->status) }}</span><br>
                            <strong>Order Date:</strong> {{ $purchaseOrder->order_date ? $purchaseOrder->order_date->format('F j, Y') : '-' }}<br>
                            @if($purchaseOrder->expected_date)
                                <strong>Expected Date:</strong> {{ $purchaseOrder->expected_date->format('F j, Y') }}<br>
                            @endif
                            @if($purchaseOrder->received_date)
                                <strong>Received Date:</strong> {{ $purchaseOrder->received_date->format('F j, Y') }}<br>
                            @endif
                            <strong>Currency:</strong> {{ $purchaseOrder->currency ?? 'USD' }}
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
                        <th style="width: 40%;">Item</th>
                        <th style="width: 15%;">Qty Ordered</th>
                        <th style="width: 20%;">Unit Cost</th>
                        <th style="width: 25%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchaseOrder->items as $item)
                    <tr>
                        <td>
                            <div class="item-name">{{ $item->product_name }}</div>
                            @if($item->sku)<div class="item-sku">SKU: {{ $item->sku }}</div>@endif
                            @if($item->supplier_sku)<div class="item-sku">Supplier SKU: {{ $item->supplier_sku }}</div>@endif
                        </td>
                        <td>{{ $item->quantity_ordered }}</td>
                        <td>{{ $purchaseOrder->currency ?? 'USD' }} {{ number_format((float) $item->unit_cost, 2) }}</td>
                        <td>{{ $purchaseOrder->currency ?? 'USD' }} {{ number_format((float) $item->subtotal, 2) }}</td>
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
                    <td>{{ $purchaseOrder->currency ?? 'USD' }} {{ number_format((float) $purchaseOrder->subtotal, 2) }}</td>
                </tr>
                @if((float) $purchaseOrder->tax > 0)
                <tr>
                    <td>Tax</td>
                    <td>{{ $purchaseOrder->currency ?? 'USD' }} {{ number_format((float) $purchaseOrder->tax, 2) }}</td>
                </tr>
                @endif
                @if((float) $purchaseOrder->shipping > 0)
                <tr>
                    <td>Shipping</td>
                    <td>{{ $purchaseOrder->currency ?? 'USD' }} {{ number_format((float) $purchaseOrder->shipping, 2) }}</td>
                </tr>
                @endif
                <tr class="total-row">
                    <td>Total</td>
                    <td>{{ $purchaseOrder->currency ?? 'USD' }} {{ number_format((float) $purchaseOrder->total, 2) }}</td>
                </tr>
            </table>
        </div>

        <!-- Notes -->
        @if($purchaseOrder->notes)
        <div class="notes-section">
            <div class="notes-label">Notes</div>
            <div class="notes-text">{{ $purchaseOrder->notes }}</div>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>Generated on {{ $generatedDate }}</p>
            <p>{{ $organization->name }}@if($organization->email) &mdash; {{ $organization->email }}@endif</p>
        </div>
    </div>
</body>
</html>
