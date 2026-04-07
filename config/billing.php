<?php

return [
    'default_gateway' => env('BILLING_DEFAULT_GATEWAY', 'asaas'),

    'webhooks' => [
        'queue' => env('BILLING_WEBHOOK_QUEUE', 'default'),
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('BILLING_CIRCUIT_BREAKER_THRESHOLD', 5),
        'cooldown_seconds'  => (int) env('BILLING_CIRCUIT_BREAKER_COOLDOWN', 300),
    ],

    'retry' => [
        'payment_attempts' => [
            'max_attempts' => (int) env('BILLING_PAYMENT_RETRY_MAX_ATTEMPTS', 3),
            'backoff_seconds' => (int) env('BILLING_PAYMENT_RETRY_BACKOFF_SECONDS', 120),
        ],
    ],

    'gateways' => [
        'infinitepay' => [
            'base_url' => env('INFINITEPAY_BASE_URL', 'https://api.infinitepay.io'),
            'secret' => env('INFINITEPAY_SECRET'),
            'webhook_secret' => env('INFINITEPAY_WEBHOOK_SECRET'),
            'endpoints' => [
                'customers' => '/v1/customers',
                'subscriptions' => '/v1/subscriptions',
                'subscription_cancel' => '/v1/subscriptions/{id}/cancel',
                'charges' => '/v1/charges',
                'payments' => '/v1/payments/{id}',
            ],
        ],
        'asaas' => [
            'base_url' => env('ASAAS_BASE_URL', 'https://api.asaas.com'),
            'secret' => env('ASAAS_SECRET'),
            'webhook_secret' => env('ASAAS_WEBHOOK_SECRET'),
            'endpoints' => [
                'customers' => '/v3/customers',
                'subscriptions' => '/v3/subscriptions',
                'subscription_cancel' => '/v3/subscriptions/{id}',
                'charges' => '/v3/payments',
                'payments' => '/v3/payments/{id}',
            ],
        ],
        'mercadopago' => [
            'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
            'secret' => env('MERCADOPAGO_SECRET'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'endpoints' => [
                'customers' => '/v1/customers',
                'subscriptions' => '/preapproval',
                'subscription_cancel' => '/preapproval/{id}',
                'charges' => '/v1/payments',
                'payments' => '/v1/payments/{id}',
            ],
        ],
        'pagarme' => [
            'base_url' => env('PAGARME_BASE_URL', 'https://api.pagar.me/core/v5'),
            'secret' => env('PAGARME_SECRET'),
            'webhook_secret' => env('PAGARME_WEBHOOK_SECRET'),
            'endpoints' => [
                'customers' => '/customers',
                'subscriptions' => '/subscriptions',
                'subscription_cancel' => '/subscriptions/{id}/cancel',
                'charges' => '/orders',
                'payments' => '/orders/{id}',
            ],
        ],
        'stripe_br' => [
            'base_url' => env('STRIPE_BR_BASE_URL', 'https://api.stripe.com'),
            'secret' => env('STRIPE_BR_SECRET'),
            'webhook_secret' => env('STRIPE_BR_WEBHOOK_SECRET'),
            'endpoints' => [
                'customers' => '/v1/customers',
                'subscriptions' => '/v1/subscriptions',
                'subscription_cancel' => '/v1/subscriptions/{id}',
                'charges' => '/v1/payment_intents',
                'payments' => '/v1/payment_intents/{id}',
            ],
        ],
        'pagbank' => [
            'base_url' => env('PAGBANK_BASE_URL', 'https://api.pagseguro.com'),
            'secret' => env('PAGBANK_SECRET'),
            'webhook_secret' => env('PAGBANK_WEBHOOK_SECRET'),
            'endpoints' => [
                'customers' => '/customers',
                'subscriptions' => '/pre-approvals',
                'subscription_cancel' => '/pre-approvals/{id}/cancel',
                'charges' => '/charges',
                'payments' => '/charges/{id}',
            ],
        ],
    ],
];
