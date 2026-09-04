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

    'whatsapp' => [
        'api_url' => env('WA_API_URL', 'https://waghub.mekayastudio.com/api/v1/messages'),
        'api_key' => env('WA_API_KEY'),
        'connect_timeout' => (int) env('WA_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('WA_API_TIMEOUT', 15),
        'otp_expires_in' => (int) env('WA_OTP_EXPIRES_IN', 60),
        'otp_message' => env('WA_OTP_MESSAGE', 'Kode OTP {app_name} Anda: {otp}. Berlaku {expires_in}. Jangan bagikan kode ini kepada siapa pun.'),
    ],

];
