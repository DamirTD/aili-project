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

    'groq_ai' => [
        'api_key' => env('GROQ_AI'),
        'model' => env('GROQ_AI_MODEL', 'openai/gpt-oss-120b'),
        'vision_model' => env('GROQ_AI_VISION_MODEL'),
        'base_url' => env('GROQ_AI_BASE_URL', 'https://api.groq.com/openai/v1'),
        'verify_ssl' => env('GROQ_AI_VERIFY_SSL', true),
        'ca_bundle' => env('GROQ_AI_CA_BUNDLE'),
    ],

];
