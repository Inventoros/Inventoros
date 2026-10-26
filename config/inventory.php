<?php

return [
    // Bound ledger/event memory for one atomic reset. Zero-balance rows do
    // not consume this budget; operators may raise it for their worker size.
    'stock_reset_max_records' => (int) env('INVENTOROS_STOCK_RESET_MAX_RECORDS', 5000),
];
