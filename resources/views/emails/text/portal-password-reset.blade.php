{!! $brandName !!}

Reset your password

Hello {!! $contact->name !!},

We received a request to reset the password for your {!! $brandName !!} customer portal account.

Reset password: {!! $resetUrl !!}

This link expires in {!! $expiresInMinutes !!} minutes. If you didn't ask to reset your password, you can ignore this email.
@include('emails.text.partials.footer')
