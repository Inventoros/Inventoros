<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="robots" content="noindex, nofollow, nosnippet">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="application-name" content="{{ config('app.name', 'Inventoros') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title data-inertia>{{ config('app.name', 'Inventoros') }}</title>

        @php
            $iconUrl = static function (string $path): string {
                $file = public_path($path);
                $version = is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : null;
                return asset($path).($version ? '?v='.$version : '');
            };
        @endphp
        <!-- Favicon & app icons -->
        <link rel="icon" href="{{ $iconUrl('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $iconUrl('images/brand/favicon-32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $iconUrl('images/brand/favicon-16.png') }}">
        <link rel="apple-touch-icon" href="{{ $iconUrl('apple-touch-icon.png') }}">
        <meta name="theme-color" content="#2563eb">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        {{-- Plugin pages (Plugin::slug/Page) are not in the app's Vite manifest. --}}
        @vite(str_starts_with($page['component'], 'Plugin::') ? ['resources/js/app.js'] : ['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
