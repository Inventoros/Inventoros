@extends('emails.layout')

@section('content')
    @php
        $alertType = $type ?? null;
        $isWarning = $alertType === \App\Services\UserActivityAlertService::TYPE_REPEATED_FAILED_LOGINS;
        $heading = match ($alertType) {
            \App\Services\UserActivityAlertService::TYPE_USER_CREATED => 'A new user was added',
            \App\Services\UserActivityAlertService::TYPE_PROMOTED_TO_ADMIN => 'A user was promoted to admin',
            \App\Services\UserActivityAlertService::TYPE_REPEATED_FAILED_LOGINS => 'Repeated failed sign-ins',
            default => 'Account activity',
        };
    @endphp

    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        {{ $heading }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Hi {{ $recipient_name ?? 'there' }},
        @if($alertType === \App\Services\UserActivityAlertService::TYPE_USER_CREATED)
            {{ $actor_name ?? 'An administrator' }} added a new user to your organization.
        @elseif($alertType === \App\Services\UserActivityAlertService::TYPE_PROMOTED_TO_ADMIN)
            {{ $actor_name ?? 'An administrator' }} gave a user full admin access to your organization.
        @elseif($isWarning)
            there were {{ $failed_count ?? 0 }} failed sign-in attempts for one account in the last {{ $window_minutes ?? 15 }} minutes.
            If this was not the account owner, review the account and consider resetting its password.
        @endif
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $isWarning ? '#fef3c7' : '#eff6ff' }}; border-left: 4px solid {{ $isWarning ? '#f59e0b' : '#3b82f6' }}; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <strong style="color: #111827; font-size: 16px; display: block; margin-bottom: 6px;">{{ $subject_name ?? 'Unknown user' }}</strong>
                <span style="color: #374151; font-size: 14px; display: block;">{{ $subject_email ?? '' }}</span>
                @if(!empty($subject_role))
                    <span style="color: #6b7280; font-size: 13px; display: block; margin-top: 6px;">Role: {{ ucfirst($subject_role) }}</span>
                @endif
                @if(!empty($ip_address))
                    <span style="color: #6b7280; font-size: 13px; display: block; margin-top: 6px;">IP address: {{ $ip_address }}</span>
                @endif
            </td>
        </tr>
    </table>

    @if(!empty($url))
        <p style="margin: 20px 0;">
            <a href="{{ $url }}" style="display: inline-block; padding: 10px 18px; background-color: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600;">Review security activity</a>
        </p>
    @endif

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 13px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        You receive these alerts because user activity alerts are turned on in your notification preferences.
    </p>
@endsection
