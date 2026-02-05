<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Notification' }}</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f3f4f6; padding: 40px 0;">
        <tr>
            <td align="center">
                <!-- Email Container -->
                <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600; letter-spacing: -0.5px;">
                                {{ config('app.name', 'Inventoros') }}
                            </h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td role="presentation" style="padding: 40px 30px;">
                            {{-- HOOK: Before content --}}
                            {!! apply_filters('email_before_content', '', $emailType ?? '', $data ?? []) !!}

                            @yield('content')

                            {{-- HOOK: After content --}}
                            {!! apply_filters('email_after_content', '', $emailType ?? '', $data ?? []) !!}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td role="presentation" style="padding: 30px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 14px; line-height: 1.5; text-align: center;">
                                You're receiving this because you have email notifications enabled.
                            </p>
                            <p style="margin: 0; text-align: center;">
                                @if(Route::has('settings.index'))
                                    <a href="{{ route('settings.index') }}" style="color: #667eea; text-decoration: none; font-size: 14px;">
                                        Manage Email Preferences
                                    </a>
                                @else
                                    <span style="color: #6b7280; font-size: 14px;">Manage Email Preferences</span>
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Footer Text -->
                <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 600px; width: 100%; margin-top: 20px;">
                    <tr>
                        <td style="text-align: center; color: #9ca3af; font-size: 12px;">
                            © {{ date('Y') }} Inventoros. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
