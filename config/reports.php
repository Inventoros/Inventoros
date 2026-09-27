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

];
