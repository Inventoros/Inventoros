<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Synchronous size limit (KB)
    |--------------------------------------------------------------------------
    |
    | Import uploads at or below this size are processed inline and the result
    | stats are shown immediately (the historical behaviour). Larger uploads are
    | stored, processed by a queued job, and the user is notified with the
    | import stats when it finishes — so a big file can't time out the request.
    |
    */
    'sync_max_kb' => (int) env('IMPORT_SYNC_MAX_KB', 512),

    /*
    |--------------------------------------------------------------------------
    | Storage disk for queued import uploads
    |--------------------------------------------------------------------------
    */
    'disk' => env('IMPORT_DISK', env('INVENTOROS_UPLOADS_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | XLSX archive limits
    |--------------------------------------------------------------------------
    |
    | An XLSX file is a ZIP that the reader inflates in memory. Uploads are
    | checked before parsing: the number of parts, their total uncompressed
    | size, and the compression ratio of any part larger than ratio_min_bytes.
    | An empty value (as the Docker and Coolify templates pass by default)
    | keeps the default.
    |
    */
    'xlsx' => [
        'max_entries' => (int) (env('IMPORT_XLSX_MAX_ENTRIES') ?: 1000),
        'max_uncompressed_bytes' => (int) (env('IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES') ?: 256 * 1024 * 1024),
        'max_compression_ratio' => (float) (env('IMPORT_XLSX_MAX_COMPRESSION_RATIO') ?: 100),
        'ratio_min_bytes' => (int) (env('IMPORT_XLSX_RATIO_MIN_BYTES') ?: 1024 * 1024),
    ],
];
