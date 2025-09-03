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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'crystallize_pim' => [
        'api_url' => env('CRYSTALLIZE_PIM_API_URL'),
        'access_token_id' => env('CRYSTALLIZE_PIM_ACCESS_TOKEN_ID'),
        'access_token_secret' => env('CRYSTALLIZE_PIM_ACCESS_TOKEN_SECRET'),
        'tenant_id' => env('CRYSTALLIZE_PIM_TENANT_ID'),
    ],

    'crystallize_discovery' => [
        'api_url' => env('CRYSTALLIZE_DISCOVERY_API_URL'),
        'access_token' => env('CRYSTALLIZE_DISCOVERY_ACCESS_TOKEN'),
    ],

];
