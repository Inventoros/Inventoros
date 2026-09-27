<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Low-stock alert cooldown
    |--------------------------------------------------------------------------
    |
    | After a low-stock notification fires for a product, repeat alerts for
    | the same product are suppressed for this many minutes, so a product that
    | stays low does not re-alert (and re-email every stock manager) on every
    | later stock adjustment. Default: 1440 (24 hours).
    |
    */

    'low_stock_cooldown_minutes' => (int) env('LOW_STOCK_ALERT_COOLDOWN_MINUTES', 1440),

];
