{!! $brandName !!}

You're invited to our customer portal

Hello {!! $contact->name !!},

{!! $brandName !!} has given you access to its customer portal. There you can follow your orders, download invoices and request returns.

Set your password: {!! $acceptUrl !!}

This link expires in {!! $expiresInDays !!} days and can be used once. If you weren't expecting this invitation, you can ignore this email.
@include('emails.text.partials.footer')
