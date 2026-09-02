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

    /*
    | Kredensial VClaim BPJS dan SATUSEHAT. Kosong sampai faskes terdaftar
    | dan kredensial diterbitkan — selama itu App\Modules\Integration\
    | Providers\IntegrationServiceProvider otomatis memakai adapter palsu
    | (FakeBpjsClient / FakeSatusehatClient), bukan gagal boot.
    */
    'bpjs' => [
        'base_url' => env('BPJS_VCLAIM_BASE_URL'),
        'cons_id' => env('BPJS_CONS_ID'),
        'secret_key' => env('BPJS_SECRET_KEY'),
        'user_key' => env('BPJS_USER_KEY'),
        'ppk_code' => env('BPJS_PPK_CODE'),
    ],

    'satusehat' => [
        'base_url' => env('SATUSEHAT_BASE_URL'),
        'auth_url' => env('SATUSEHAT_AUTH_URL'),
        'client_id' => env('SATUSEHAT_CLIENT_ID'),
        'client_secret' => env('SATUSEHAT_CLIENT_SECRET'),
        'organization_id' => env('SATUSEHAT_ORG_ID'),
    ],

];
