<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Strict tracked-stock mode
    |--------------------------------------------------------------------------
    |
    | When false (the default), fulfilment allocates serials/batches on a
    | best-effort basis: a serial- or batch-tracked product is only consumed
    | when it has enough tracked records, and an order for an under-populated
    | product still succeeds untouched. This is the safe transitional mode.
    |
    | When true, tracked records become authoritative: an order for a serial-
    | or batch-tracked product REQUIRES enough available serials / batch
    | quantity, and creation is rejected (InsufficientStockException) if the
    | records cannot cover it — even when products.stock could. Turn this on
    | once your serials/batches are fully populated.
    |
    */

    'strict_tracked_stock' => env('INVENTORY_STRICT_TRACKED_STOCK', false),

    // Bound ledger/event memory for one atomic reset. Zero-balance rows do
    // not consume this budget; operators may raise it for their worker size.
    'stock_reset_max_records' => (int) env('INVENTOROS_STOCK_RESET_MAX_RECORDS', 5000),
];
