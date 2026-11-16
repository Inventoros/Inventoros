<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | The provider a new organization starts with. Each organization can pick
    | another one in Settings > Exchange rates. "manual" never contacts an
    | external service.
    |
    */
    'default_provider' => env('EXCHANGE_RATES_DEFAULT_PROVIDER', 'frankfurter'),

    /*
    |--------------------------------------------------------------------------
    | Freshness
    |--------------------------------------------------------------------------
    |
    | stale_after_days: the default age (in days, between the rate's effective
    | date and the date it is used for) beyond which a stored rate is shown as
    | stale and is no longer applied without someone confirming it.
    | Organizations can change it. ECB reference rates are not published at
    | weekends or on TARGET holidays, so a few days is normal.
    |
    | refresh_after_minutes: how long the newest rate a provider returned for
    | today is trusted before asking the provider again.
    |
    | failure_cooldown_seconds: after a provider request fails, lookups use the
    | stored rates for this long instead of retrying on every page load.
    |
    */
    'stale_after_days' => (int) env('EXCHANGE_RATES_STALE_AFTER_DAYS', 4),
    'refresh_after_minutes' => (int) env('EXCHANGE_RATES_REFRESH_AFTER_MINUTES', 360),
    'failure_cooldown_seconds' => (int) env('EXCHANGE_RATES_FAILURE_COOLDOWN', 300),

    /*
    |--------------------------------------------------------------------------
    | Synchronization
    |--------------------------------------------------------------------------
    |
    | Frequencies an organization can choose, in minutes. The scheduler runs
    | exchange-rates:sync hourly and queues the organizations that are due.
    | A failed sync is retried with these back-off delays (minutes) before
    | waiting for the next regular run.
    |
    */
    'frequencies' => [
        'hourly' => 60,
        'every_six_hours' => 360,
        'daily' => 1440,
        'weekly' => 10080,
    ],
    'default_frequency' => 'daily',
    'retry_backoff_minutes' => [15, 60, 240],

    /*
    |--------------------------------------------------------------------------
    | Frankfurter
    |--------------------------------------------------------------------------
    |
    | Public European Central Bank reference rates, no API key. Change the URL
    | to point at a self-hosted Frankfurter instance.
    |
    */
    'frankfurter' => [
        'url' => env('FRANKFURTER_URL', 'https://api.frankfurter.dev/v1'),
        'timeout' => (int) env('FRANKFURTER_TIMEOUT', 8),
        'connect_timeout' => (int) env('FRANKFURTER_CONNECT_TIMEOUT', 4),
    ],
];
