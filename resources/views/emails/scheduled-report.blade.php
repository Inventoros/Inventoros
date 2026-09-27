@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        {{ $reportName }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Your {{ $frequency }} report from {{ $brandName }} is attached as <strong>{{ $filename }}</strong>.
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Report:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $reportName }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Schedule:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ ucfirst($frequency) }}</strong></td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;"><span style="color: #6b7280; font-size: 14px;">Generated:</span></td>
            <td width="50%" style="padding: 8px 0; text-align: right;"><strong style="color: #111827; font-size: 14px;">{{ $generatedAt }}</strong></td>
        </tr>
    </table>

    <p style="margin: 0; color: #6b7280; font-size: 13px; line-height: 1.6;">
        This report was scheduled by a member of your organization. Ask them to change or stop the schedule if you no longer need it.
    </p>
@endsection
