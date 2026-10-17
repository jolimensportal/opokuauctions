<?php

/**
 * Exchange rates.
 *
 * These are the operator's number, not something fetched at random per request.
 * The buyer's rate is locked at deposit (see FxService) so a rate change here
 * never moves a price a buyer has already committed to; it only affects new
 * quotes.
 *
 * Rates are USD-based: the platform settles with the forwarder in USD, so the
 * pair stored against a lot is usd -> the buyer's currency.
 *
 * TODO(ops): replace with a live feed. FxService::currentRate() is the only
 * method that reads this file, so a provider drops in behind it without
 * touching a single call site.
 */
return [

    // Currencies shown side by side in the storefront.
    'display' => ['ghs'],

    // Currencies a buyer can hold a wallet in.
    'supported' => ['usd', 'ghs', 'cad'],

    'rates' => [
        // USD base.
        'usd.ghs' => env('FX_USD_GHS', 15.05),
        'usd.cad' => env('FX_USD_CAD', 1.36),
        'ghs.usd' => env('FX_GHS_USD', 0.0664),
        'cad.usd' => env('FX_CAD_USD', 0.7352),
    ],

    // How long a locked quote stays valid before a buyer must be re-quoted.
    'lock_seconds' => env('FX_LOCK_SECONDS', 3600),

    'fetched_at' => env('FX_FETCHED_AT'),
];
