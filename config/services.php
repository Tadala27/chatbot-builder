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
    'whatsapp' => [
        'waba_id' => env('META_WABA_ID'),
        'system_user_token' => env('META_ACCESS_TOKEN'),
        'api_version' => env('META_API_VERSION', 'v25.0'),
        'verify_token' => env('META_VERIFY_TOKEN'),
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'api_version' => env('META_API_VERSION', 'v25.0'), // v25.0+, NOT v18
        'embedded_signup_config_id' => env('META_ES_CONFIG_ID'), // from App Dashboard > Business Login > Configuration
    ],
];
