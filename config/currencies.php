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
        'AED' => ['symbol' => 'د.إ', 'name' => 'UAE Dirham'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar'],
        'BDT' => ['symbol' => '৳', 'name' => 'Bangladeshi Taka'],
        'BRL' => ['symbol' => 'R$', 'name' => 'Brazilian Real'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'CHF' => ['symbol' => 'Fr', 'name' => 'Swiss Franc'],
        'CNY' => ['symbol' => '¥', 'name' => 'Chinese Yuan'],
        'DKK' => ['symbol' => 'kr', 'name' => 'Danish Krone'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'HKD' => ['symbol' => 'HK$', 'name' => 'Hong Kong Dollar'],
        'IDR' => ['symbol' => 'Rp', 'name' => 'Indonesian Rupiah'],
        'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee'],
        'JPY' => ['symbol' => '¥', 'name' => 'Japanese Yen'],
        'KRW' => ['symbol' => '₩', 'name' => 'South Korean Won'],
        'MXN' => ['symbol' => '$', 'name' => 'Mexican Peso'],
        'MYR' => ['symbol' => 'RM', 'name' => 'Malaysian Ringgit'],
        'NOK' => ['symbol' => 'kr', 'name' => 'Norwegian Krone'],
        'NZD' => ['symbol' => 'NZ$', 'name' => 'New Zealand Dollar'],
        'PHP' => ['symbol' => '₱', 'name' => 'Philippine Peso'],
        'PLN' => ['symbol' => 'zł', 'name' => 'Polish Zloty'],
        'SAR' => ['symbol' => '﷼', 'name' => 'Saudi Riyal'],
        'SEK' => ['symbol' => 'kr', 'name' => 'Swedish Krona'],
        'SGD' => ['symbol' => 'S$', 'name' => 'Singapore Dollar'],
        'THB' => ['symbol' => '฿', 'name' => 'Thai Baht'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'ZAR' => ['symbol' => 'R', 'name' => 'South African Rand'],
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
