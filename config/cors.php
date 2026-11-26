<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'https://taalam-staging.t3allem.com'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Date: الفرونت يحسب منه فرق ساعة الجهاز عن السيرفر (serverClock.js) كي لا يظهر زر
    // "انضم" متأخراً/مبكراً على جهاز ساعته غير مضبوطة — بدونه لا يقرأه JS عبر دومين آخر.
    'exposed_headers' => ['Date'],

    'max_age' => 0,

    // Bearer-token (Sanctum personal access token) auth, not cookie-based —
    // no need for credentialed CORS.
    'supports_credentials' => false,

];
