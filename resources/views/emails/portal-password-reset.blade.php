@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        Reset your password
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Hello {{ $contact->name }},
    </p>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        We received a request to reset the password for your {{ $brandName }} customer portal account.
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $resetUrl }}" style="display: inline-block; padding: 14px 32px; background-color: #667eea; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    Reset password
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5;">
        This link expires in {{ $expiresInMinutes }} minutes. If you didn't ask to reset your password, you can ignore this email.
    </p>
@endsection
