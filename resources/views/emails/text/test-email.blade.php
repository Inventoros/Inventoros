{!! $brandName !!}

Test Email Successful

Your email configuration is working correctly. Your organization's email settings are properly configured and ready to send notifications.

Organization: {!! $organization ?? $brandName !!}
Tested by: {!! $tested_by ?? 'Unknown' !!}
Test time: {!! now()->format('M d, Y h:i A') !!}
@include('emails.text.partials.footer')
