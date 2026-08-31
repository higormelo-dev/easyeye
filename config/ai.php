<?php

return [
    'default_mode' => env('AI_DEFAULT_MODE', 'validated'),

    'enable_consensus' => filter_var(env('AI_ENABLE_CONSENSUS', true), FILTER_VALIDATE_BOOL),

    // Define se usa providers reais (API externa) ou fakes (teste/local sem chave).
    'provider_runtime' => env('AI_PROVIDER_RUNTIME', 'fake'),

    // Força resolução IPv4 nas chamadas aos provedores LLM. Necessário em
    // hospedagens com IPv6 anunciado mas sem rota de saída (ex.: PaaS de
    // homologação): o cURL tenta o AAAA primeiro e pendura até o timeout.
    'http_force_ipv4' => filter_var(env('AI_HTTP_FORCE_IPV4', false), FILTER_VALIDATE_BOOL),

    // User-Agent enviado pelos providers reais. Identifica EasyEye no destino
    // (logs do fornecedor, rate-limit dashboards, abuse reports). Inclui URL
    // de contato para que o time deles consiga abrir contato em incidentes.
    'user_agent' => env('AI_USER_AGENT', 'EasyEye/1.0 (+https://easyeye.com.br)'),

    'providers' => [
        'primary'     => env('AI_PRIMARY_PROVIDER', 'openai'),
        'reviewer'    => env('AI_REVIEWER_PROVIDER', 'anthropic'),
        'adjudicator' => env('AI_ADJUDICATOR_PROVIDER', 'gemini'),

        'openai' => [
            'base_url'                => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model'                   => env('AI_OPENAI_MODEL', 'gpt-5-mini'),
            'timeout_seconds'         => (int) env('AI_OPENAI_TIMEOUT_SECONDS', 20),
            'connect_timeout_seconds' => (int) env('AI_OPENAI_CONNECT_TIMEOUT_SECONDS', 5),
            'retry_times'             => (int) env('AI_OPENAI_RETRY_TIMES', 1),
            'retry_sleep_ms'          => (int) env('AI_OPENAI_RETRY_SLEEP_MS', 250),
        ],

        'anthropic' => [
            'base_url'                => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'model'                   => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
            'default_max_tokens'      => (int) env('AI_ANTHROPIC_DEFAULT_MAX_TOKENS', 800),
            'timeout_seconds'         => (int) env('AI_ANTHROPIC_TIMEOUT_SECONDS', 20),
            'connect_timeout_seconds' => (int) env('AI_ANTHROPIC_CONNECT_TIMEOUT_SECONDS', 5),
            'retry_times'             => (int) env('AI_ANTHROPIC_RETRY_TIMES', 1),
            'retry_sleep_ms'          => (int) env('AI_ANTHROPIC_RETRY_SLEEP_MS', 250),
        ],

        'gemini' => [
            'base_url'                => env('AI_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'model'                   => env('AI_GEMINI_MODEL', 'gemini-3.6-flash'),
            'timeout_seconds'         => (int) env('AI_GEMINI_TIMEOUT_SECONDS', 20),
            'connect_timeout_seconds' => (int) env('AI_GEMINI_CONNECT_TIMEOUT_SECONDS', 5),
            'retry_times'             => (int) env('AI_GEMINI_RETRY_TIMES', 1),
            'retry_sleep_ms'          => (int) env('AI_GEMINI_RETRY_SLEEP_MS', 250),
        ],
    ],

    'circuit_breaker' => [
        // Número de falhas consecutivas antes de abrir o circuito.
        'threshold' => (int) env('AI_BREAKER_THRESHOLD', 5),
        // Tempo (segundos) que o circuito fica aberto antes de tentar de novo.
        'cooldown_seconds' => (int) env('AI_BREAKER_COOLDOWN_SECONDS', 120),
    ],

    'rate_limits' => [
        // Per (user_id + entity_id). estimate é leve, store dispara job, approve/reject
        // são pontuais. show/index herdam padrão Laravel.
        'estimate_per_minute' => (int) env('AI_RATE_ESTIMATE_PER_MIN', 60),
        'store_per_minute'    => (int) env('AI_RATE_STORE_PER_MIN', 10),
        'decision_per_minute' => (int) env('AI_RATE_DECISION_PER_MIN', 30),
    ],

    'jobs' => [
        'queue'           => env('AI_QUEUE', 'default'),
        'tries'           => (int) env('AI_JOB_TRIES', 3),
        'backoff_seconds' => (int) env('AI_JOB_BACKOFF_SECONDS', 45),
        'timeout_seconds' => (int) env('AI_JOB_TIMEOUT_SECONDS', 180),
    ],

    'pricing' => [
        // Quanto 1 crédito representa em USD.
        'usd_per_credit' => (float) env('AI_USD_PER_CREDIT', 0.01),

        // Multiplicador para absorver custo indireto, risco de variação e margem do produto.
        'margin_multiplier' => (float) env('AI_CREDIT_MARGIN_MULTIPLIER', 2.0),

        // Piso global por execução (mesmo para prompts curtos).
        'minimum_credits_default' => (int) env('AI_MIN_CREDITS_DEFAULT', 1),

        // Pisos específicos por workflow.
        'minimum_credits_by_workflow' => [
            'exam_assistant'     => (int) env('AI_MIN_CREDITS_EXAM_ASSISTANT', 3),
            'report_drafting'    => (int) env('AI_MIN_CREDITS_REPORT_DRAFTING', 2),
            'consensus_review'   => (int) env('AI_MIN_CREDITS_CONSENSUS_REVIEW', 5),
            'eye_image_analysis' => (int) env('AI_MIN_CREDITS_EYE_IMAGE', 4),
        ],
    ],

    // Análise de imagem ocular (módulo Eye Image).
    'eye_image' => [
        // Máximo de imagens por execução (limita custo e tamanho do payload inline).
        'max_images' => (int) env('AI_EYE_IMAGE_MAX_IMAGES', 4),
        // Maior dimensão (px) após downscale antes de enviar ao modelo.
        'max_dimension' => (int) env('AI_EYE_IMAGE_MAX_DIMENSION', 1568),
        // Tamanho máximo do arquivo de origem no S3 (MB) — acima disso, ignora.
        'max_source_mb' => (int) env('AI_EYE_IMAGE_MAX_SOURCE_MB', 25),
        // Tokens de entrada estimados por imagem (sobretaxa na estimativa de crédito).
        'tokens_per_image' => (int) env('AI_EYE_IMAGE_TOKENS_PER_IMAGE', 300),
    ],

    'credit_purchases' => [
        'currency' => env('AI_CREDIT_PURCHASE_CURRENCY', 'BRL'),

        // Em produção, créditos comprados só devem entrar na carteira após
        // confirmação financeira via gateway/webhook. Útil ativar em local/testes.
        'auto_credit_without_gateway' => filter_var(
            env('AI_CREDIT_PURCHASES_AUTO_CREDIT', false),
            FILTER_VALIDATE_BOOL,
        ),

        'packages' => [
            [
                'code'        => 'starter',
                'credits'     => 25,
                'price_cents' => 6990,
                'featured'    => false,
            ],
            [
                'code'        => 'operational',
                'credits'     => 100,
                'price_cents' => 24990,
                'featured'    => true,
            ],
            [
                'code'        => 'scale',
                'credits'     => 300,
                'price_cents' => 59990,
                'featured'    => false,
            ],
        ],
    ],
];
