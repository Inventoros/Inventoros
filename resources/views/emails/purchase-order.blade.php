@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        Purchase Order {{ $purchaseOrder->po_number }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Hello{{ $purchaseOrder->supplier?->contact_name ? ' '.$purchaseOrder->supplier->contact_name : '' }},
    </p>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        {{ $brandName }} has sent you purchase order <strong>{{ $purchaseOrder->po_number }}</strong>.
        The full order is attached as a PDF.
    </p>

    @if($customMessage)
        <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; border-left: 4px solid #667eea; border-radius: 6px; margin: 20px 0;">
            <tr>
                <td style="padding: 20px; color: #374151; font-size: 15px; line-height: 1.6;">
                    {!! nl2br(e($customMessage)) !!}
                </td>
            </tr>
        </table>
    @endif

    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">PO Number:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $purchaseOrder->po_number }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Order Date:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $purchaseOrder->order_date?->format('M j, Y') ?? '-' }}</strong></td>
        </tr>
        @if($purchaseOrder->expected_date)
            <tr>
                <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Expected Delivery:</span></td>
                <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $purchaseOrder->expected_date->format('M j, Y') }}</strong></td>
            </tr>
        @endif
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Items:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $purchaseOrder->items->count() }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Total:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ number_format((float) $purchaseOrder->total, 2) }} {{ $purchaseOrder->currency }}</strong></td>
        </tr>
    </table>

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        Please confirm receipt of this order and let us know of any changes to pricing or delivery.
    </p>
@endsection
