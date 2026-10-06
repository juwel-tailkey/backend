<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'custom_model_report' => [
        'endpoint_url' => env('CUSTOM_MODEL_REPORT_URL', 'https://example.com/dummy-report-endpoint'),
        // While no real third party exists, calls are handled in-process by
        // CustomModelDummyService instead of an actual HTTP round trip (a
        // self-loopback call would deadlock a single-worker `artisan serve`
        // running the sync queue driver). Set to false once endpoint_url
        // points at a real service, or once jobs run via `queue:work`.
        'use_local_dummy' => env('CUSTOM_MODEL_USE_LOCAL_DUMMY', true),
    ],

];
