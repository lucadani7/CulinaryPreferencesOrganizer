<?php

return [
    // All prices are stored in this currency.
    'base' => 'EUR',

    // European Central Bank reference rates, served by Frankfurter (free, no API key).
    // GET {rates_url}?base=EUR returns [{"date","base","quote","rate"}, ...]
    'rates_url' => env('FX_RATES_URL', 'https://api.frankfurter.dev/v2/rates'),
    'source' => 'European Central Bank',
    'timeout' => (int) env('FX_TIMEOUT', 10),

    // Rates older than this are refreshed on the next request (the ECB publishes on working days, ~16:00 CET).
    'max_age_hours' => (int) env('FX_MAX_AGE_HOURS', 24),

    // false = only `php artisan fx:refresh` (scheduled daily) downloads rates; tests turn it off
    'auto_refresh' => (bool) env('FX_AUTO_REFRESH', true),

    // Countries outside the euro area whose currency the ECB publishes. A country that is not
    // listed (euro area members included) is shown in euro only.
    'countries' => [
        'RO' => 'RON', 'HU' => 'HUF', 'PL' => 'PLN', 'CZ' => 'CZK', 'SE' => 'SEK', 'DK' => 'DKK',
        'GB' => 'GBP', 'CH' => 'CHF', 'LI' => 'CHF', 'NO' => 'NOK', 'IS' => 'ISK', 'TR' => 'TRY',
        'US' => 'USD', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD', 'JP' => 'JPY', 'CN' => 'CNY',
        'HK' => 'HKD', 'KR' => 'KRW', 'IN' => 'INR', 'ID' => 'IDR', 'IL' => 'ILS', 'MY' => 'MYR',
        'PH' => 'PHP', 'SG' => 'SGD', 'TH' => 'THB', 'ZA' => 'ZAR', 'BR' => 'BRL', 'MX' => 'MXN',
    ],
];
