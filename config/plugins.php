<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin upload feature flag
    |--------------------------------------------------------------------------
    |
    | Uploading a plugin grants arbitrary PHP execution inside the running
    | application: the ZIP is extracted into /plugins and the manifest's
    | main_file is require_once'd at activation time. Anyone with the
    | manage_plugins permission can perform this — meaning an admin
    | account compromise becomes server RCE.
    |
    | This flag controls whether the upload endpoint is reachable at all.
    | When false (the default), POST /admin/plugins/upload returns an
    | error without writing anything. Operators who want plugin uploads
    | must set INVENTOROS_ALLOW_PLUGIN_UPLOADS=true and accept the
    | residual risk documented in SECURITY.md.
    |
    */

    'upload_enabled' => env('INVENTOROS_ALLOW_PLUGIN_UPLOADS', false),

    /*
    |--------------------------------------------------------------------------
    | ZIP extraction limits
    |--------------------------------------------------------------------------
    |
    | Bounds for plugin ZIP archives. Defense against zip bombs and ZIPs
    | that pad with thousands of entries to chew up the filesystem.
    |
    */

    'max_entry_count' => (int) env('INVENTOROS_PLUGIN_MAX_ENTRIES', 2000),

    'max_extracted_bytes' => (int) env('INVENTOROS_PLUGIN_MAX_BYTES', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Plugin signature verification
    |--------------------------------------------------------------------------
    |
    | Uploading a plugin is RCE-by-design (see above). Beyond the off-by-default
    | flag, an operator can require uploaded plugins to carry a detached Ed25519
    | signature verified against a trusted public key — so only plugins signed
    | by a key the operator controls (or an Inventoros-published registry key)
    | can be installed, even by a compromised admin session.
    |
    | This is OFF by default: there is no public Inventoros plugin registry yet,
    | and requiring signatures unconditionally would make the (already opt-in)
    | upload feature unusable for local plugin development. When `required` is
    | on it FAILS CLOSED — uploads without a valid signature are rejected. The
    | detached signature is produced with `php artisan update:sign <plugin.zip>`
    | (same Ed25519 scheme as release signing) and supplied in the `signature`
    | upload field.
    |
    */

    'signature' => [
        'required' => filter_var(
            env('INVENTOROS_PLUGIN_SIGNATURE_REQUIRED', false),
            FILTER_VALIDATE_BOOL
        ),

        'public_key' => (string) env('INVENTOROS_PLUGIN_PUBLIC_KEY', ''),
    ],

];
