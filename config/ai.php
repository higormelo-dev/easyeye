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

        // ── Provedores "compatíveis com OpenAI" (OpenAiCompatibleProvider) ──
        // Ficam disponíveis no painel quando a chave (MISTRAL_API_KEY etc.)
        // está no .env. O modelo do .env é só o padrão: o painel escolhe entre
        // os modelos do catálogo sincronizado. Conexão/retry: mesmos padrões
        // dos demais (AI_<PROVEDOR>_CONNECT_TIMEOUT_SECONDS etc., opcionais).

        'mistral' => [
            'base_url'        => env('AI_MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'model'           => env('AI_MISTRAL_MODEL', 'mistral-small-latest'),
            'timeout_seconds' => (int) env('AI_MISTRAL_TIMEOUT_SECONDS', 30),
        ],

        'groq' => [
            'base_url'        => env('AI_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'model'           => env('AI_GROQ_MODEL', 'openai/gpt-oss-120b'),
            'timeout_seconds' => (int) env('AI_GROQ_TIMEOUT_SECONDS', 30),
        ],

        'xai' => [
            'base_url'        => env('AI_XAI_BASE_URL', 'https://api.x.ai/v1'),
            'model'           => env('AI_XAI_MODEL', 'grok-4.5'),
            'timeout_seconds' => (int) env('AI_XAI_TIMEOUT_SECONDS', 30),
        ],

        // Azure OpenAI (API v1, sem api-version). Sem endereço padrão: cada
        // recurso tem o seu — https://{recurso}.openai.azure.com/openai/v1.
        // "model" = NOME DO DEPLOYMENT criado no Azure (use o nome do modelo,
        // ex.: gpt-4o-mini, para a sincronização achar o preço).
        'azure_openai' => [
            'base_url'        => env('AI_AZURE_OPENAI_BASE_URL'),
            'model'           => env('AI_AZURE_OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout_seconds' => (int) env('AI_AZURE_OPENAI_TIMEOUT_SECONDS', 30),
            'auth'            => 'api-key',
            // A API lista modelos-base, não os deployments: os deployments
            // entram no catálogo à mão (a sincronização confere os preços).
            'catalog_listing' => false,
            // Onde o deployment processa os dados — declarado pelo dono do
            // SaaS conforme o que criou no Azure (o painel mostra e a LGPD
            // depende disto): eu = Standard regional em país da UE (ex.:
            // swedencentral — recomendado, coberto pela adequação da UE);
            // br = Provisioned (PTU) em brazilsouth; global = Global Standard
            // ou Data Zone (inferência em qualquer região).
            'data_region' => env('AI_AZURE_OPENAI_DATA_REGION', 'eu'),
        ],

        // Maritaca AI (Brasil). Modelos com sufixo "-br-sp": inferência e
        // logs 100% no Brasil (+30% no preço); sem sufixo podem processar nos
        // EUA/UE. Só texto: o EasyEye não envia imagem a ela (a Maritaca faria
        // OCR, possivelmente com terceiros fora do Brasil).
        'maritaca' => [
            'base_url'        => env('AI_MARITACA_BASE_URL', 'https://chat.maritaca.ai/api'),
            'model'           => env('AI_MARITACA_MODEL', 'sabia-4-br-sp'),
            'timeout_seconds' => (int) env('AI_MARITACA_TIMEOUT_SECONDS', 60),
            // GET /models usa "Authorization: Key ..." (docs.maritaca.ai/api/pt/list-models).
            'list_auth' => 'key',
            // Preço oficial em R$ por 1M tokens [entrada, saída] — fora do
            // catálogo LiteLLM; a sincronização converte para US$ pela cotação
            // do sistema (AiUsdBrlRate). Fonte: docs.maritaca.ai/pt/precos (03/10/2026).
            'prices_brl' => [
                'sabia-4'                => [5.00, 20.00],
                'sabia-4-thinking'       => [5.00, 40.00],
                'sabiazinho-4'           => [1.00, 4.00],
                'sabia-4-br-sp'          => [6.50, 26.00],
                'sabia-4-thinking-br-sp' => [6.50, 52.00],
                'sabiazinho-4-br-sp'     => [1.30, 5.20],
            ],
        ],
    ],

    // Sincronização do catálogo de modelos e preços (Manager → Provedores de IA):
    // modelos pela API oficial de cada provedor com chave + preços do catálogo
    // público LiteLLM (USD por token → USD por 1M). Preço editado à mão fica
    // travado. Só leitura (nenhuma chamada gasta tokens). AI_CATALOG_SYNC_ENABLED
    // liga a verificação diária automática; o botão "Sincronizar agora" funciona
    // sempre.
    'catalog_sync' => [
        'enabled'         => filter_var(env('AI_CATALOG_SYNC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'prices_url'      => env('AI_PRICES_CATALOG_URL', 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json'),
        'timeout_seconds' => (int) env('AI_CATALOG_SYNC_TIMEOUT_SECONDS', 60),
        // Teto do JSON de preços (~3 MB hoje): acima disso, recusa.
        'prices_max_bytes' => (int) env('AI_PRICES_CATALOG_MAX_BYTES', 30 * 1024 * 1024),
        // Mudança de preço maior que isso (vezes, pra cima ou pra baixo) não é
        // aplicada sozinha — vai para revisão (protege a cobrança de erro no catálogo).
        'max_price_change_factor' => (float) env('AI_CATALOG_MAX_PRICE_CHANGE_FACTOR', 10),
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

        // Pedido de pacote (checkout) pendente sem pagamento há mais de N dias:
        // descartado por ai:expire-credit-pack-orders (pedido e fatura
        // cancelados, cobranças canceladas no gateway quando possível).
        // Pagamento que chegar depois ainda credita (o cliente pagou).
        'pending_expiry_days' => (int) env('AI_CREDIT_PACK_PENDING_EXPIRY_DAYS', 7),

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
