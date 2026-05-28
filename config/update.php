<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed download URL prefixes
    |--------------------------------------------------------------------------
    |
    | UpdateService::update($downloadUrl) used to accept any URL and
    | HTTP-fetch it into the application directory at extraction time. Any
    | future caller that wired user input into that parameter would have
    | meant "give me a URL, get RCE." The download URL is now checked
    | against this allowlist; URLs that do not start with one of these
    | prefixes are rejected before any bytes are transferred.
    |
    | The default allows the project's own GitHub release downloads. To
    | install from a mirror or fork, override INVENTOROS_UPDATE_PREFIXES
    | with a comma-separated list of HTTPS prefixes.
    |
    */

    'download_url_prefixes' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            (string) env(
                'INVENTOROS_UPDATE_PREFIXES',
                'https://github.com/Inventoros/Inventoros/releases/download/,'
                .'https://github.com/Inventoros/Inventoros/archive/,'
                .'https://api.github.com/repos/Inventoros/Inventoros/zipball/,'
                .'https://api.github.com/repos/Inventoros/Inventoros/tarball/'
            )
        )
    ))),

    /*
    |--------------------------------------------------------------------------
    | Extraction limits
    |--------------------------------------------------------------------------
    |
    | Bounds for downloaded release archives and backup ZIPs. The defaults
    | accommodate a full Inventoros source-code archive. Set higher if you
    | bundle large vendored assets.
    |
    */

    'max_entry_count' => (int) env('INVENTOROS_UPDATE_MAX_ENTRIES', 50000),

    'max_extracted_bytes' => (int) env('INVENTOROS_UPDATE_MAX_BYTES', 300 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Release signature verification
    |--------------------------------------------------------------------------
    |
    | The updater downloads a release ZIP and replaces the running app's files
    | with its contents — so a tampered archive is RCE. TLS + the allowlist
    | above protect the transport and origin; this verifies the archive itself
    | against a detached Ed25519 signature.
    |
    | Each release ships `<asset>.sig` next to `<asset>.zip` containing the
    | base64 raw 64-byte signature of the archive bytes. The matching base64
    | 32-byte public key goes in INVENTOROS_UPDATE_PUBLIC_KEY; the secret key
    | lives only in the release-signing environment, never in the repo.
    |
    | `required` is true by default and FAILS CLOSED: if it is on but no public
    | key is configured, updates are refused rather than silently trusting an
    | unverified download. Operators who deliberately accept unsigned updates
    | (e.g. installing a private fork) set INVENTOROS_UPDATE_SIGNATURE_REQUIRED
    | to false.
    |
    | Tooling: `php artisan update:signing-keypair` mints a key;
    | `php artisan update:sign <file>` produces the `.sig`.
    |
    */

    'signature' => [
        'required' => filter_var(
            env('INVENTOROS_UPDATE_SIGNATURE_REQUIRED', true),
            FILTER_VALIDATE_BOOL
        ),

        'public_key' => (string) env('INVENTOROS_UPDATE_PUBLIC_KEY', ''),

        'asset_suffix' => (string) env('INVENTOROS_UPDATE_SIGNATURE_SUFFIX', '.sig'),
    ],

];
