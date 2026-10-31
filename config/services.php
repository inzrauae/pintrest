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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'pinterest' => [
        'client_id' => env('PINTEREST_CLIENT_ID'),
        'client_secret' => env('PINTEREST_CLIENT_SECRET'),
        'redirect_uri' => env('PINTEREST_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/pinterest/callback'),
        'authorize_url' => env('PINTEREST_AUTHORIZE_URL', 'https://www.pinterest.com/oauth/'),
        'api_url' => env('PINTEREST_API_URL', 'https://api.pinterest.com/v5'),
        'sandbox_api_url' => env('PINTEREST_SANDBOX_API_URL', 'https://api-sandbox.pinterest.com/v5'),
        'scopes' => env('PINTEREST_SCOPES', 'boards:read,boards:write,pins:read,pins:write,user_accounts:read'),
        'timeout' => (int) env('PINTEREST_TIMEOUT', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1.5'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
    ],

];
