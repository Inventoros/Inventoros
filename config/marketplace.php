<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Marketplace URL
    |--------------------------------------------------------------------------
    |
    | Where Settings > Plugins > Marketplace fetches the plugin catalog and
    | downloads plugin ZIPs from. The app only ever talks to this one host,
    | over HTTPS, at /api/v1/marketplace, and never follows redirects, so the
    | value cannot be used to reach internal addresses. It must be a bare
    | https origin: no path, query, credentials or port tricks.
    |
    */

    'url' => (string) env('INVENTOROS_MARKETPLACE_URL', 'https://inventoros.com'),

    /*
    |--------------------------------------------------------------------------
    | Marketplace signing public key
    |--------------------------------------------------------------------------
    |
    | Every plugin ZIP the marketplace serves carries a detached Ed25519
    | signature (base64, 64 bytes) over a statement binding the plugin's
    | slug, version and the ZIP's sha256 (see PackageSignature in
    | app/Services/Marketplace). The app verifies it against this base64
    | 32-byte public key before extracting anything, which is why marketplace installs
    | work even when manual ZIP uploads are off, and why an older signed
    | package cannot be replayed as an update.
    |
    | The matching secret key lives only in the marketplace's environment
    | (MARKETPLACE_SIGNING_SECRET_KEY on inventoros.com). The key is minted
    | with `php artisan marketplace:keygen` on the site.
    |
    | MAINTAINERS: once the production key exists, replace the '' default
    | below with its PUBLIC key and commit it (public keys are safe to
    | publish). Until then marketplace installs are refused unless
    | INVENTOROS_MARKETPLACE_PUBLIC_KEY is set. The app never trusts the key
    | the marketplace advertises at /api/v1/marketplace/public-key.
    |
    */

    'public_key' => (string) env('INVENTOROS_MARKETPLACE_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Catalog cache and download limits
    |--------------------------------------------------------------------------
    */

    'cache_seconds' => (int) env('INVENTOROS_MARKETPLACE_CACHE_SECONDS', 300),

    'timeout' => (int) env('INVENTOROS_MARKETPLACE_TIMEOUT', 20),

    'max_download_bytes' => (int) env('INVENTOROS_MARKETPLACE_MAX_DOWNLOAD_BYTES', 50 * 1024 * 1024),

];
