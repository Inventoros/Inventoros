@extends('emails.layout')

@section('content')
    @php
        $kind = $kind ?? 'requested';
        $title = ucfirst($item['title'] ?? 'request');
        $heading = match ($kind) {
            'approved' => $title.' approved',
            'rejected' => $title.' rejected',
            default => 'Approval needed: '.($item['title'] ?? 'request'),
        };
        $accent = match ($kind) {
            'approved' => '#10b981',
            'rejected' => '#ef4444',
            default => '#f59e0b',
        };
    @endphp

    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        {{ $heading }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        @if($kind === 'requested')
            {{ $actor }} asked for approval of {{ $item['title'] ?? 'a request' }}.
        @else
            {{ $actor }} {{ $kind }} your {{ $item['title'] ?? 'request' }}.
        @endif
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="border-left: 4px solid {{ $accent }}; background-color: #f9fafb; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <strong style="color: #111827; font-size: 15px; display: block; margin-bottom: 6px;">{{ $item['reference'] ?? '' }}</strong>
                <span style="color: #374151; font-size: 14px;">{{ $item['summary'] ?? '' }}</span>
                @if(! empty($notes))
                    <p style="margin: 12px 0 0 0; color: #374151; font-size: 14px; line-height: 1.5;">
                        <strong>Notes:</strong> {{ $notes }}
                    </p>
                @endif
            </td>
        </tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $notification_url ?? '#' }}" style="display: inline-block; padding: 14px 32px; background-color: #667eea; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    {{ $kind === 'requested' ? 'Review request' : 'View details' }}
                </a>
            </td>
        </tr>
    </table>
@endsection
