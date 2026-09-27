
--
@if(! empty($transactional))
This email was sent by {!! $brandName ?? config('app.name', 'Inventoros') !!}.@if(! empty($brandEmail)) Questions? Reply to this email or contact {!! $brandEmail !!}.@endif

@else
You're receiving this because you have email notifications enabled.
@if(Route::has('settings.index'))
Manage email preferences: {!! route('settings.index') !!}
@endif
@endif
(c) {!! date('Y') !!} {!! $brandName ?? config('app.name', 'Inventoros') !!}
