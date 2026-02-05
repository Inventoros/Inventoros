@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        🎉 Test Email Successful!
    </h2>

    <p style="margin: 0 0 15px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Great news! Your email configuration is working correctly.
    </p>

    <!-- Success Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #d1fae5; border-left: 4px solid #10b981; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <strong style="color: #065f46; font-size: 16px; display: block; margin-bottom: 10px;">
                    ✓ Email Configuration Test Passed
                </strong>
                <p style="margin: 0; color: #047857; font-size: 14px;">
                    Your organization's email settings are properly configured and ready to send notifications.
                </p>
            </td>
        </tr>
    </table>

    <!-- Test Details -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="40%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Organization:</span>
            </td>
            <td width="60%" style="padding: 8px 0;">
                <strong style="color: #111827; font-size: 14px;">{{ $organization }}</strong>
            </td>
        </tr>
        <tr>
            <td width="40%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Tested By:</span>
            </td>
            <td width="60%" style="padding: 8px 0;">
                <strong style="color: #111827; font-size: 14px;">{{ $tested_by }}</strong>
            </td>
        </tr>
        <tr>
            <td width="40%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Test Time:</span>
            </td>
            <td width="60%" style="padding: 8px 0;">
                <strong style="color: #111827; font-size: 14px;">{{ now()->format('M d, Y h:i A') }}</strong>
            </td>
        </tr>
    </table>

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        You can now close this email. Your system is ready to send email notifications to your users.
    </p>
@endsection
