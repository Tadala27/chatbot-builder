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
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'max_tokens' => env('OPENAI_MAX_TOKENS', 4000),
        'temperature' => env('OPENAI_TEMPERATURE', 0.3),
        'timeout' => env('OPENAI_TIMEOUT', 120),
        'max_retries' => env('OPENAI_MAX_RETRIES', 3),
    ],
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
    ],
    'meta' => [
        'api_version' => env('META_API_VERSION', 'v21.0'),
        'tech_provider_token' => env('META_TECH_PROVIDER_TOKEN'),
        'business_id' => env('META_BUSINESS_ID'),
        'tech_provider_waba_id' => env('META_TECH_PROVIDER_WABA_ID'),
        'app_secret' => env('META_APP_SECRET'), // used by WebhookController signature verification
    ],

    // 'meta' => [
    //     'app_id' => env('META_APP_ID'),
    //     'app_secret' => env('META_APP_SECRET'),
    //     'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
    //     'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    //     'app_secret' => env('WHATSAPP_APP_SECRET'),
    // ],
];
