<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    |
    | List of supported currencies with their symbols and names.
    |
    */
    'supported' => [
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar'],
        'JPY' => ['symbol' => '¥', 'name' => 'Japanese Yen'],
        'CNY' => ['symbol' => '¥', 'name' => 'Chinese Yuan'],
        'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee'],
        'MXN' => ['symbol' => '$', 'name' => 'Mexican Peso'],
        'BRL' => ['symbol' => 'R$', 'name' => 'Brazilian Real'],
        'CHF' => ['symbol' => 'Fr', 'name' => 'Swiss Franc'],
        'SEK' => ['symbol' => 'kr', 'name' => 'Swedish Krona'],
        'NOK' => ['symbol' => 'kr', 'name' => 'Norwegian Krone'],
        'DKK' => ['symbol' => 'kr', 'name' => 'Danish Krone'],
        'ZAR' => ['symbol' => 'R', 'name' => 'South African Rand'],
        'NZD' => ['symbol' => 'NZ$', 'name' => 'New Zealand Dollar'],
        'SGD' => ['symbol' => 'S$', 'name' => 'Singapore Dollar'],
        'HKD' => ['symbol' => 'HK$', 'name' => 'Hong Kong Dollar'],
        'KRW' => ['symbol' => '₩', 'name' => 'South Korean Won'],
        'PLN' => ['symbol' => 'zł', 'name' => 'Polish Zloty'],
        'THB' => ['symbol' => '฿', 'name' => 'Thai Baht'],
        'IDR' => ['symbol' => 'Rp', 'name' => 'Indonesian Rupiah'],
        'MYR' => ['symbol' => 'RM', 'name' => 'Malaysian Ringgit'],
        'PHP' => ['symbol' => '₱', 'name' => 'Philippine Peso'],
        'AED' => ['symbol' => 'د.إ', 'name' => 'UAE Dirham'],
        'SAR' => ['symbol' => '﷼', 'name' => 'Saudi Riyal'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency code used throughout the application.
    |
    */
    'default' => env('DEFAULT_CURRENCY', 'USD'),
];
