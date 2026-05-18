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
                . 'https://github.com/Inventoros/Inventoros/archive/,'
                . 'https://api.github.com/repos/Inventoros/Inventoros/zipball/,'
                . 'https://api.github.com/repos/Inventoros/Inventoros/tarball/'
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

];
