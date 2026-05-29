<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Synchronous row limit
    |--------------------------------------------------------------------------
    |
    | Exports whose result set is at or below this many rows are streamed to
    | the browser synchronously (the historical behaviour). Anything larger is
    | handed to a queued job and the user is notified with a download link when
    | it is ready — so a large export can no longer time out the web request or
    | exhaust memory in the request lifecycle.
    |
    */
    'sync_row_limit' => (int) env('EXPORT_SYNC_ROW_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | Storage disk for generated exports
    |--------------------------------------------------------------------------
    */
    'disk' => env('EXPORT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    |
    | How long a generated export file is kept before a cleanup command may
    | prune it. Surfaced for the prune routine / UI; not enforced at write time.
    |
    */
    'retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
];
