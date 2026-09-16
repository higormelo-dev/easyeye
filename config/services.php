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

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'integrator_updates' => [
        // Chave pública ed25519 (hex, 32 bytes) que assina os builds do
        // EasyEye Integrator — mesmo valor de UPDATE_PUBLIC_KEY_HEX em
        // integrator/src/updater/mod.rs (repositório separado). É seguro
        // manter aqui como default: é a metade PÚBLICA do par, só serve pra
        // VERIFICAR assinatura, nunca pra assinar. A privada correspondente
        // nunca passa pelo SaaS (fica só na máquina do operador de release —
        // ver integrator/scripts/sign-update.sh).
        'public_key' => env(
            'INTEGRATOR_UPDATE_PUBLIC_KEY',
            'c62b4de90f8cacd4bf0f4d408a2dec22f80c181f19e0f64156124a00aedbe2b9',
        ),
    ],
];
