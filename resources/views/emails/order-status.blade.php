@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        📦 Order Status Updated
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Order <strong>#{{ $order->order_number }}</strong> status has been updated.
    </p>

    <!-- Status Change Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 25px;">
                <table width="100%">
                    <tr>
                        <td style="padding-bottom: 15px;">
                            <span style="color: #6b7280; font-size: 14px; display: block; margin-bottom: 5px;">
                                Previous Status
                            </span>
                            <span style="display: inline-block; padding: 6px 12px; background-color: #e5e7eb; color: #374151; border-radius: 4px; font-size: 14px; font-weight: 500;">
                                {{ ucfirst($old_status) }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 10px 0;">
                            <span style="color: #9ca3af; font-size: 20px;">↓</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top: 15px;">
                            <span style="color: #6b7280; font-size: 14px; display: block; margin-bottom: 5px;">
                                New Status
                            </span>
                            <span style="display: inline-block; padding: 6px 12px; background-color: #d1fae5; color: #065f46; border-radius: 4px; font-size: 14px; font-weight: 600;">
                                {{ ucfirst($order->status) }}
                            </span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Order Details -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
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
                <span style="color: #6b7280; font-size: 14px;">Order Total:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">${{ number_format($order->total, 2) }}</strong>
            </td>
        </tr>
    </table>

    {{-- HOOK: Additional actions --}}
    {!! apply_filters('email_additional_actions', '', 'order_status', $order) !!}

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
@endsection
