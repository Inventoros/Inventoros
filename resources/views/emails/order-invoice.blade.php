@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        Invoice {{ $order->invoice_number }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Hello{{ $order->customer_name ? ' '.$order->customer_name : '' }},
    </p>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Thank you for your order. Your invoice from {{ $brandName }} for order <strong>#{{ $order->order_number }}</strong> is attached as a PDF.
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
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Invoice Number:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $order->invoice_number }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Order Number:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">#{{ $order->order_number }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Invoice Date:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ ($order->invoice_issued_at ?? now())->format('M j, Y') }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Total:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ number_format((float) $order->total, 2) }} {{ $order->currency }}</strong></td>
        </tr>
    </table>
@endsection
