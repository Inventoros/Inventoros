{!! $brandName !!}

@if(($kind ?? 'requested') === 'requested')
Approval needed: {!! $item['title'] ?? 'request' !!}

{!! $actor !!} asked for approval of {!! $item['title'] ?? 'a request' !!}.
@else
{!! ucfirst($item['title'] ?? 'request') !!} {!! $kind !!}

{!! $actor !!} {!! $kind !!} your {!! $item['title'] ?? 'request' !!}.
@endif

{!! $item['reference'] ?? '' !!}
{!! $item['summary'] ?? '' !!}
@if(! empty($notes))

Notes: {!! $notes !!}
@endif

{!! ($kind ?? 'requested') === 'requested' ? 'Review request' : 'View details' !!}: {!! $notification_url ?? '' !!}
@include('emails.text.partials.footer')
