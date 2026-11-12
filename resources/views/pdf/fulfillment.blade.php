<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $isPickList ? __('Pick list') : __('Packing slip') }} - {{ $orderNumber }}</title>
    <style>
        @page { margin: 36px 40px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; line-height: 1.5; color: #20252b; }
        h1 { font-size: 26px; line-height: 1.2; margin: 5px 0 18px; }
        .brand { font-size: 11px; font-weight: bold; color: #59636e; }
        .details { width: 100%; margin-bottom: 22px; border-collapse: collapse; }
        .details td { width: 50%; vertical-align: top; padding: 0 16px 0 0; overflow-wrap: break-word; }
        .label { font-size: 10px; color: #59636e; }
        .reference { font-size: 13px; font-weight: bold; overflow-wrap: break-word; }
        .address { margin-top: 10px; }
        .items { width: 100%; border-collapse: collapse; }
        .items thead { display: table-header-group; }
        .items th { text-align: left; background: #edf0f3; border-bottom: 1px solid #a8b0b9; font-size: 10px; }
        .items th, .items td { padding: 9px 8px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        .items td { border-bottom: 1px solid #dce1e6; }
        .items tr { page-break-inside: avoid; }
        .items .quantity { text-align: right; }
        .items .check { text-align: center; }
        .items .sku { font-size: 10px; }
        .box { display: inline-block; width: 12px; height: 12px; border: 1px solid #59636e; }
        .summary { page-break-inside: avoid; }
        .note { margin-top: 18px; color: #59636e; font-size: 10px; }
        .signatures { margin-top: 24px; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; border-top: 1px solid #dce1e6; padding-top: 6px; font-size: 8px; color: #59636e; text-align: right; }
        .page:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="brand">{{ $organizationName }}</div>
    <h1>{{ $isPickList ? __('Pick list') : __('Packing slip') }}</h1>
    <table class="details">
        <tr>
            <td>
                <div class="label">{{ __('Order') }}</div>
                <div class="reference">{{ $orderNumber }}</div>
                <div>{{ __('Order date') }}: {{ $orderDate }}</div>
                @if($shipmentId)
                    <div>{{ __('Shipment') }}: #{{ $shipmentId }}</div>
                @endif
                @if($warehouseName)
                    <div>{{ __('Warehouse') }}: {{ $warehouseName }}</div>
                @endif
            </td>
            <td>
                @if($isPickList)
                    <div class="label">{{ __('Customer') }}</div>
                    <div>{{ $customerName }}</div>
                @else
                    <div class="label">{{ __('Ship to') }}</div>
                    @foreach($addressLines as $addressLine)
                        <div>{{ $addressLine }}</div>
                    @endforeach
                    @if($carrier)
                        <div class="address">{{ __('Carrier') }}: {{ $carrier }}</div>
                    @endif
                    @if($trackingNumber)
                        <div>{{ __('Tracking number') }}: {{ $trackingNumber }}</div>
                    @endif
                @endif
            </td>
        </tr>
    </table>
    <table class="items">
        <thead>
            <tr>
                <th class="sku" style="width: 25%;">SKU</th>
                <th style="width: {{ $isPickList ? '55%' : '63%' }};">{{ __('Item') }}</th>
                <th class="quantity" style="width: 12%;">{{ __('Quantity') }}</th>
                @if($isPickList)<th class="check" style="width: 8%;">{{ __('Check') }}</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td class="sku">{{ $line['sku'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="quantity">{{ $line['quantity'] }}</td>
                    @if($isPickList)<td class="check"><span class="box"></span></td>@endif
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="summary">
        <p class="note">{{ $isPickList ? __('Only quantities not yet shipped are listed.') : __('Packed items for this shipment only.') }}</p>
        @if($isPickList)
            <p class="signatures">{{ __('Picked by') }}: ____________________ &nbsp;&nbsp; {{ __('Checked by') }}: ____________________</p>
        @endif
    </div>
    <div class="footer">{{ __('Page') }} <span class="page"></span></div>
</body>
</html>
