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
    'disk' => env('IMPORT_DISK', 'local'),
];
