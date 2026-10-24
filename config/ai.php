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

    // Ditado por voz do laudo (Fase 5): o áudio vai ao backend e é transcrito por
    // um provedor da política LGPD (docs/internal/legal/ai-providers-lgpd.md) — NUNCA
    // pelo reconhecimento de voz do navegador, que manda o áudio ao Google
    // (transferência internacional de dado de saúde sem contrato). Desligado de
    // fábrica: o dono do SaaS liga depois de conferir o contrato do provedor
    // (retenção/ZDR). Só entra provedor que aceita dado de paciente, está ligado no
    // painel e — se processa fora do Brasil sem adequação — tem o mecanismo de
    // transferência registrado (SpeechTranscriptionService::resolve()).
    'transcription' => [
        'enabled' => filter_var(env('AI_TRANSCRIPTION_ENABLED', false), FILTER_VALIDATE_BOOL),
        // mistral (Voxtral, UE — adequação), azure_openai (Whisper, conforme a região
        // declarada), openai ou groq (EUA — exigem o mecanismo registrado).
        'provider' => env('AI_TRANSCRIPTION_PROVIDER', 'mistral'),
        // Modelo de transcrição (nome do deployment, no Azure).
        'model'           => env('AI_TRANSCRIPTION_MODEL', 'voxtral-mini-latest'),
        'language'        => env('AI_TRANSCRIPTION_LANGUAGE', 'pt'),
        'timeout_seconds' => (int) env('AI_TRANSCRIPTION_TIMEOUT_SECONDS', 60),
        // Teto por ditado: duração e tamanho (WAV 16 kHz mono ≈ 32 KB/s). O custo é por
        // minuto de áudio, então o teto de duração é também o teto de custo por chamada.
        'max_seconds' => (int) env('AI_TRANSCRIPTION_MAX_SECONDS', 120),
        'max_kb'      => (int) env('AI_TRANSCRIPTION_MAX_KB', 6144),
        // Ditados por minuto, por (usuário + clínica).
        'per_minute' => (int) env('AI_TRANSCRIPTION_PER_MINUTE', 10),
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
        // Preço da execução (centavos de BRL): custo_usd × câmbio × markup, arredondado HALF_UP, com piso. O câmbio vem do
        // histórico do manager (AiExchangeRateService); markup e pisos podem ser editados no manager (system_settings
        // > estes padrões) e NUNCA aparecem para a clínica. Sem reutilizar a env antiga AI_CREDIT_MARGIN_MULTIPLIER
        // (produção pode estar com 2.0 do modelo de créditos).
        'markup_multiplier' => (float) env('AI_MARKUP_MULTIPLIER', 5.0),

        // Piso global por execução, em centavos (R$ 0,10): chamada curta não corrói a margem.
        'minimum_charge_cents_default' => (int) env('AI_MIN_CHARGE_CENTS_DEFAULT', 10),

        // Pisos específicos por workflow, em centavos.
        'minimum_charge_cents_by_workflow' => [
            'exam_assistant'     => (int) env('AI_MIN_CHARGE_CENTS_EXAM_ASSISTANT', 10),
            'report_drafting'    => (int) env('AI_MIN_CHARGE_CENTS_REPORT_DRAFTING', 10),
            'consensus_review'   => (int) env('AI_MIN_CHARGE_CENTS_CONSENSUS_REVIEW', 10),
            'eye_image_analysis' => (int) env('AI_MIN_CHARGE_CENTS_EYE_IMAGE', 10),
        ],
    ],

    // Conversão ÚNICA do saldo em créditos (modelo antigo) para centavos de BRL (migration/comando ai:convert-legacy-credits).
    // São PARÂMETROS para o dono trocar antes do deploy — não números soltos no código.
    // Cotação automática do dólar (frankfurter.dev, taxa diária do BCE). Desligar só em testes/ambientes sem rede.
    'exchange_rate' => [
        'auto_fetch' => (bool) env('AI_EXCHANGE_RATE_AUTO_FETCH', true),
    ],

    'legacy_conversion' => [
        // Centavos por crédito comprado quando a clínica não tem nenhuma compra paga (decisão do dono: R$ 2,00/crédito).
        'default_credit_cents' => (int) env('AI_LEGACY_DEFAULT_CREDIT_CENTS', 200),

        // Centavos por crédito da cota mensal legada (1 crédito = R$ 1,00: plano de 30 créditos vira R$ 30,00).
        'quota_credit_cents' => (int) env('AI_LEGACY_QUOTA_CREDIT_CENTS', 100),

        // Markup e cotação do modelo antigo, só para estimar o `normalized_charge_cents` diagnóstico das chamadas já feitas.
        'legacy_markup'        => (float) env('AI_LEGACY_MARKUP', 2.0),
        'legacy_fallback_rate' => (float) env('AI_LEGACY_FALLBACK_RATE', 5.50),
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

        // Pacote personalizado: valor livre em reais inteiros, acima do Enterprise. Limites editáveis no manager
        // (AiPricingSettings); o servidor sempre revalida — o preço nunca vem do cliente.
        'custom_package' => [
            'min_cents'  => (int) env('AI_CUSTOM_PACKAGE_MIN_CENTS', 100000),
            'max_cents'  => (int) env('AI_CUSTOM_PACKAGE_MAX_CENTS', 1000000),
            'step_cents' => 100,
        ],

        // Pacotes fixos: R$ pagos = R$ de saldo (sem bônus). `value_cents` é o preço E o crédito na carteira.
        'packages' => [
            [
                'code'        => 'basic',
                'value_cents' => 10000,
                'featured'    => false,
                'custom'      => false,
            ],
            [
                'code'        => 'premium',
                'value_cents' => 49900,
                'featured'    => true,
                'custom'      => false,
            ],
            [
                'code'        => 'enterprise',
                'value_cents' => 99900,
                'featured'    => false,
                'custom'      => false,
            ],
            [
                'code'        => 'custom',
                'value_cents' => null,
                'featured'    => false,
                'custom'      => true,
            ],
        ],
    ],
];
