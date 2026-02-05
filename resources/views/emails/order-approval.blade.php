@extends('emails.layout')

@section('content')
    @php
        $isApproved = $order->approval_status === 'approved';
        $icon = $isApproved ? '✅' : '❌';
        $statusColor = $isApproved ? '#10b981' : '#ef4444';
        $statusBgColor = $isApproved ? '#d1fae5' : '#fee2e2';
    @endphp

    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        {{ $icon }} Order {{ ucfirst($order->approval_status) }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Your order <strong>#{{ $order->order_number }}</strong> has been
        <strong style="color: {{ $statusColor }};">{{ $order->approval_status }}</strong>
        by {{ $order->approver->name }}.
    </p>

    <!-- Status Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $statusBgColor }}; border-left: 4px solid {{ $statusColor }}; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <strong style="color: {{ $statusColor }}; font-size: 16px; display: block; margin-bottom: 10px;">
                    Status: {{ ucfirst($order->approval_status) }}
                </strong>

                @if($order->approval_notes)
                    <p style="margin: 10px 0 0 0; color: #374151; font-size: 14px; line-height: 1.5;">
                        <strong>Notes:</strong> {{ $order->approval_notes }}
                    </p>
                @endif
            </td>
        </tr>
    </table>

    <!-- Order Details -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Order Number:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">#{{ $order->order_number }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Customer:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">{{ $order->customer_name }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Total:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">${{ number_format($order->total, 2) }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">{{ $isApproved ? 'Approved' : 'Reviewed' }} By:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">{{ $order->approver->name }}</strong>
            </td>
        </tr>
    </table>

    {{-- HOOK: Additional actions --}}
    {!! apply_filters('email_additional_actions', '', 'order_approval', $order) !!}

    <!-- Action Button -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $notification_url }}" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    View Order Details
                </a>
            </td>
        </tr>
    </table>

    @if($isApproved)
        <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            🎉 Your order has been approved and will be processed shortly.
        </p>
    @endif
@endsection
