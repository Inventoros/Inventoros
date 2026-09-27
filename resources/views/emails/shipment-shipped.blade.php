@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        Your order is on its way
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Hello{{ $order->customer_name ? ' '.$order->customer_name : '' }},
    </p>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Good news: {{ $brandName }} has shipped items from order <strong>#{{ $order->order_number }}</strong>.
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        @if($carrierLabel !== '')
            <tr>
                <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Carrier:</span></td>
                <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $carrierLabel }}</strong></td>
            </tr>
        @endif
        @if($shipment->tracking_number)
            <tr>
                <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Tracking number:</span></td>
                <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $shipment->tracking_number }}</strong></td>
            </tr>
        @endif
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0;">
        <tr>
            <td style="padding: 0 0 8px 0; color: #6b7280; font-size: 14px;">In this shipment:</td>
        </tr>
        @foreach($shipment->items as $item)
            <tr>
                <td style="padding: 4px 0; color: #111827; font-size: 14px;">{{ $item->quantity }} x {{ $item->orderItem?->product_name }}</td>
            </tr>
        @endforeach
    </table>

    @if($shipment->tracking_url)
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
            <tr>
                <td align="center">
                    <a href="{{ $shipment->tracking_url }}" style="display: inline-block; padding: 14px 32px; background-color: #667eea; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                        Track your package
                    </a>
                </td>
            </tr>
            <tr>
                <td align="center" style="padding-top: 12px; color: #6b7280; font-size: 12px; word-break: break-all;">
                    {{ $shipment->tracking_url }}
                </td>
            </tr>
        </table>
    @endif
@endsection
