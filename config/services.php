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

    'mydramalist' => [
        'calendar_url' => env(
            'MYDRAMALIST_CALENDAR_URL',
            'https://mydramalist.com/episode-calendar?view=small&scope=all&tz=Asia%2FSaigon',
        ),
        'check_interval' => (int) env('MYDRAMALIST_CHECK_SECONDS', 900),
    ],

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
        'base_url' => env('TMDB_BASE_URL', 'https://api.tmdb.org/3'),
        'image_url' => env('TMDB_IMAGE_URL', 'https://image.tmdb.org/t/p/w500'),
    ],

    'tvmaze' => [
        'base_url' => env('TVMAZE_BASE_URL', 'https://api.tvmaze.com'),
        'check_interval' => (int) env('TVMAZE_CHECK_SECONDS', 1800),
    ],

];
