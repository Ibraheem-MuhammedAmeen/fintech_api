<?php

/*
 * Settings for this fintech API.
 * Read them anywhere with config('fintech.demo_funding.enabled') etc.
 */
return [

    'demo_funding' => [
        // OFF unless you set DEMO_FUNDING_ENABLED=true in .env.
        // Works in every environment (production too), because the money
        // is dummy money that can never be withdrawn or sent out of the app.
        'enabled' => (bool) env('DEMO_FUNDING_ENABLED', false),

        // Limits per request, in kobo (100 kobo = ₦1).
        'min_kobo' => 100,          // ₦1
        'max_kobo' => 100_000_000,  // ₦1,000,000
    ],

];