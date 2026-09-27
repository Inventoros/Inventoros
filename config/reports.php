<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report row cap
    |--------------------------------------------------------------------------
    |
    | Hard cap on the rows any report query returns, so a report over a large
    | organization cannot load an unbounded result set into memory or time
    | out. Default: 10000.
    |
    */

    'max_rows' => (int) env('REPORTS_MAX_ROWS', 10000),

    /*
    |--------------------------------------------------------------------------
    | PDF row cap
    |--------------------------------------------------------------------------
    |
    | PDF exports print at most this many rows (with a note saying so); CSV
    | and Excel exports carry the full max_rows-bounded set. Default: 1000.
    |
    */

    'pdf_max_rows' => (int) env('REPORTS_PDF_MAX_ROWS', 1000),

];
