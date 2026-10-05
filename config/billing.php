<?php

return [
    'default_gateway' => env('BILLING_DEFAULT_GATEWAY', 'asaas'),

    // Bloqueia o painel da clínica sem assinatura com acesso (trial vencido,
    // período encerrado, cancelada). Chave de emergência: false libera todas
    // (ex.: ambiente de homologação com clínicas de teste vencidas).
    'enforce_subscription_access' => (bool) env('BILLING_ENFORCE_SUBSCRIPTION_ACCESS', true),

    // Cobrança automática: vencimento da 1ª cobrança (dias após a
    // contratação) e com quantos dias de antecedência a renovação local
    // (gateways sem recorrência própria) emite a cobrança do próximo ciclo —
    // nunca menos que dunning.reminder_days_before + 1 (a cobrança, com o
    // link, sai antes do lembrete; ver DunningSchedule::renewalLeadDays).
    'first_charge_due_days' => (int) env('BILLING_FIRST_CHARGE_DUE_DAYS', 3),
    'renewal_lead_days'     => (int) env('BILLING_RENEWAL_LEAD_DAYS', 5),

    // Régua de cobrança do cliente que já pagou (comando billing:dunning),
    // em dias corridos a partir do vencimento: lembrete antes do vencimento;
    // até soft_block_after_days de atraso o acesso segue com aviso; daí até
    // hard_block_after_days o acesso fica limitado (IA e financeiro
    // bloqueados); no hard_block_after_days a assinatura é encerrada e a
    // cobrança para no gateway. Contratação que nunca pagou não tem régua:
    // o acesso acaba no vencimento e ela é encerrada no hard_block_after_days.
    // enabled: chave de emergência da régua — false para tudo (avisos,
    // encerramento e cancelamento no gateway); `billing:dunning --dry-run`
    // mostra o que ela faria sem gravar, enviar nem cancelar.
    'dunning' => [
        'enabled'               => (bool) env('BILLING_DUNNING_ENABLED', true),
        'reminder_days_before'  => (int) env('BILLING_DUNNING_REMINDER_DAYS_BEFORE', 5),
        'soft_block_after_days' => (int) env('BILLING_DUNNING_SOFT_BLOCK_AFTER_DAYS', 3),
        'hard_block_after_days' => (int) env('BILLING_DUNNING_HARD_BLOCK_AFTER_DAYS', 7),
    ],

    // Fim do teste grátis (comando billing:trial-notices): e-mail + WhatsApp
    // aos contatos de cobrança 3 dias antes, 1 dia antes e no dia do fim do
    // trial. Chave própria (não depende da régua): false para os avisos.
    'trial_notices' => [
        'enabled' => (bool) env('BILLING_TRIAL_NOTICES_ENABLED', true),
    ],

    // Avisos do SaaS para a clínica (régua, fim do trial, cobrança enviada
    // pelo manager) também pelo WhatsApp, pela instância GLOBAL do SaaS
    // (whatsapp_settings com entity_id nulo — a mesma do código do cadastro),
    // só para o telefone verificado do contato de cobrança. Fora da janela
    // (horário comercial, no fuso do app — a mesma das confirmações aos
    // pacientes) a mensagem espera o próximo início da janela.
    'notices' => [
        'whatsapp_enabled' => (bool) env('BILLING_NOTICES_WHATSAPP_ENABLED', true),
        'whatsapp_window'  => [
            'start' => env('BILLING_NOTICES_WHATSAPP_START', '08:00'),
            'end'   => env('BILLING_NOTICES_WHATSAPP_END', '20:00'),
        ],
        // "Enviar cobrança à clínica" (manager): no máximo um envio por
        // fatura a cada N minutos.
        'charge_notice_cooldown_minutes' => (int) env('BILLING_CHARGE_NOTICE_COOLDOWN_MINUTES', 10),
    ],

    // Checkout transparente (a clínica paga sem sair do EasyEye — Pix, boleto
    // e cartão tokenizado pelo SDK JS oficial do gateway). Cartão parcelado
    // só nos ciclos de installment_cycles, até max_installments vezes, SEM
    // juros para o cliente (o EasyEye absorve a taxa). O manager sobrescreve
    // max_installments (Planos → checkout_max_installments em system_settings).
    // min_installment_amount: parcela mínima (R$) — abaixo disso a opção some.
    // idempotency_minutes: por quanto tempo a mesma chave de idempotência do
    // front devolve o mesmo resultado (clique duplo, reenvio).
    'checkout' => [
        'max_installments'       => (int) env('BILLING_CHECKOUT_MAX_INSTALLMENTS', 12),
        'installment_cycles'     => ['yearly'],
        'min_installment_amount' => (float) env('BILLING_CHECKOUT_MIN_INSTALLMENT_AMOUNT', 5),
        'idempotency_minutes'    => (int) env('BILLING_CHECKOUT_IDEMPOTENCY_MINUTES', 30),
        // Espera pela trava da clínica (uma operação de pagamento por vez)
        // antes de responder "busy" (409).
        'lock_wait_seconds' => (int) env('BILLING_CHECKOUT_LOCK_WAIT_SECONDS', 10),
        // D5 (acesso até o fim do dia do 1º vencimento sem pagar) vale uma
        // vez: com contratação não paga da clínica nesta janela (dias), a
        // nova só libera acesso depois de paga.
        'first_charge_grace_cooldown_days' => (int) env('BILLING_CHECKOUT_FIRST_CHARGE_GRACE_COOLDOWN_DAYS', 30),
        // Upgrade: diferença proporcional abaixo disto não é cobrada na hora
        // — a mudança fica para o fim do período pago (como o downgrade).
        'min_proration_amount' => (float) env('BILLING_CHECKOUT_MIN_PRORATION_AMOUNT', 5),
        // Antifraude (card testing): recusas de cartão por IP, por clínica e
        // no total (cadastro e painel). Estourou: cartão indisponível por um
        // tempo (Pix/boleto seguem). No cadastro, depois da 1ª recusa o
        // cartão exige o e-mail confirmado.
        'fraud' => [
            'declines_per_ip_hour'        => (int) env('BILLING_CHECKOUT_DECLINES_PER_IP_HOUR', 3),
            'declines_per_ip_day'         => (int) env('BILLING_CHECKOUT_DECLINES_PER_IP_DAY', 6),
            'declines_per_entity_day'     => (int) env('BILLING_CHECKOUT_DECLINES_PER_ENTITY_DAY', 5),
            'signup_declines_global_hour' => (int) env('BILLING_CHECKOUT_SIGNUP_DECLINES_GLOBAL_HOUR', 20),
            'panel_declines_global_hour'  => (int) env('BILLING_CHECKOUT_PANEL_DECLINES_GLOBAL_HOUR', 60),
            'signup_unverified_declines'  => (int) env('BILLING_CHECKOUT_SIGNUP_UNVERIFIED_DECLINES', 1),
            'register_per_ip_hour'        => (int) env('BILLING_REGISTER_PER_IP_HOUR', 10),
            'register_per_ip_day'         => (int) env('BILLING_REGISTER_PER_IP_DAY', 30),
        ],
    ],

    // Content-Security-Policy das telas de pagamento (Minha assinatura, tela
    // de IA, /subscription/expired e o cadastro): enforce | report-only | off.
    'csp' => [
        'mode' => env('BILLING_CHECKOUT_CSP', 'enforce'),
    ],

    // Cloudflare Turnstile no cadastro (/register): ligado só com as duas
    // chaves (https://developers.cloudflare.com/turnstile/get-started/).
    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    'webhooks' => [
        'queue' => env('BILLING_WEBHOOK_QUEUE', 'default'),
        // billing:prune-webhook-events apaga os processados há mais que isso
        // (os com falha ou não processados ficam).
        'retention_days' => (int) env('BILLING_WEBHOOK_RETENTION_DAYS', 90),
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('BILLING_CIRCUIT_BREAKER_THRESHOLD', 5),
        'cooldown_seconds'  => (int) env('BILLING_CIRCUIT_BREAKER_COOLDOWN', 300),
    ],

    'retry' => [
        'payment_attempts' => [
            'max_attempts'    => (int) env('BILLING_PAYMENT_RETRY_MAX_ATTEMPTS', 3),
            'backoff_seconds' => (int) env('BILLING_PAYMENT_RETRY_BACKOFF_SECONDS', 120),
        ],
    ],

    'gateways' => [
        // Checkout Integrado (link de pagamento): sem token — o lojista é a
        // InfiniteTag (handle, sem "$"). Sem recorrência nem cadastro de
        // cliente na API: cada ciclo vira um link (renovação local).
        // https://www.infinitepay.io/checkout-documentacao
        'infinitepay' => [
            'checkout_base_url' => env('INFINITEPAY_CHECKOUT_BASE_URL', 'https://api.checkout.infinitepay.io'),
            'handle'            => env('INFINITEPAY_HANDLE'),
            'redirect_url'      => env('INFINITEPAY_REDIRECT_URL'),
            // Vazio = rota billing.webhooks (/api/billing/webhooks/infinitepay).
            'webhook_url' => env('INFINITEPAY_WEBHOOK_URL'),
            'endpoints'   => [
                'links'         => '/links',
                'payment_check' => '/payment_check',
            ],
        ],
        'asaas' => [
            'base_url'       => env('ASAAS_BASE_URL', 'https://api.asaas.com'),
            'secret'         => env('ASAAS_SECRET'),
            'webhook_secret' => env('ASAAS_WEBHOOK_SECRET'),
            // UNDEFINED = o cliente escolhe boleto, Pix ou cartão na fatura.
            // BOLETO, PIX ou CREDIT_CARD forçam uma forma só.
            'billing_type' => env('ASAAS_BILLING_TYPE', 'UNDEFINED'),
            'endpoints'    => [
                'customers'             => '/v3/customers',
                'subscriptions'         => '/v3/subscriptions',
                'subscription_cancel'   => '/v3/subscriptions/{id}',
                'subscription_show'     => '/v3/subscriptions/{id}',
                'subscription_payments' => '/v3/subscriptions/{id}/payments',
                'charges'               => '/v3/payments',
                'payments'              => '/v3/payments/{id}',
                'payment_pix_qr_code'   => '/v3/payments/{id}/pixQrCode',
                'payment_boleto_line'   => '/v3/payments/{id}/identificationField',
            ],
        ],
        'mercadopago' => [
            'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
            'secret'   => env('MERCADOPAGO_SECRET'),
            // Public key (APP_USR-… / TEST-…) do MercadoPago.js — pode ir ao navegador.
            'public_key'     => env('MERCADOPAGO_PUBLIC_KEY'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'endpoints'      => [
                'customers'           => '/v1/customers',
                'customer_search'     => '/v1/customers/search',
                'subscription_show'   => '/preapproval/{id}',
                'subscription_cancel' => '/preapproval/{id}',
                'charges'             => '/v1/payments',
                'payments'            => '/v1/payments/{id}',
                'payment_refunds'     => '/v1/payments/{id}/refunds',
            ],
        ],
        'pagarme' => [
            'base_url' => env('PAGARME_BASE_URL', 'https://api.pagar.me/core/v5'),
            'secret'   => env('PAGARME_SECRET'),
            // Chave pública (pk_…) do tokenizecard.js — pode ir ao navegador.
            'public_key' => env('PAGARME_PUBLIC_KEY'),
            // Pagar.me autentica o webhook com Basic Auth: "usuario:senha".
            'webhook_secret' => env('PAGARME_WEBHOOK_SECRET'),
            // pix ou boleto (boleto exige endereço completo da empresa).
            'payment_method' => env('PAGARME_PAYMENT_METHOD', 'pix'),
            'endpoints'      => [
                'customers'           => '/customers',
                'charges'             => '/orders',
                'payments'            => '/charges/{id}',
                'subscription_cancel' => '/subscriptions/{id}',
            ],
        ],
        'stripe_br' => [
            'base_url' => env('STRIPE_BR_BASE_URL', 'https://api.stripe.com'),
            'secret'   => env('STRIPE_BR_SECRET'),
            // Publishable key (pk_…) do Stripe.js — pode ir ao navegador.
            'public_key'     => env('STRIPE_BR_PUBLISHABLE_KEY'),
            'webhook_secret' => env('STRIPE_BR_WEBHOOK_SECRET'),
            'api_version'    => env('STRIPE_BR_API_VERSION', '2025-03-31.basil'),
            'endpoints'      => [
                'customers'           => '/v1/customers',
                'customer_search'     => '/v1/customers/search',
                'subscription_cancel' => '/v1/subscriptions/{id}',
                'invoices'            => '/v1/invoices',
                'invoice'             => '/v1/invoices/{id}',
                'invoice_finalize'    => '/v1/invoices/{id}/finalize',
                'invoice_items'       => '/v1/invoiceitems',
                'invoice_payments'    => '/v1/invoice_payments',
                'payment_intent'      => '/v1/payment_intents/{id}',
            ],
        ],
        'pagbank' => [
            'base_url' => env('PAGBANK_BASE_URL', 'https://api.pagseguro.com'),
            'secret'   => env('PAGBANK_SECRET'),
            // Chave pública do PagSeguro.encryptCard. Vazia = obtida pela API
            // (POST /public-keys, type card) e guardada em cache.
            'public_key'     => env('PAGBANK_PUBLIC_KEY'),
            'webhook_secret' => env('PAGBANK_WEBHOOK_SECRET'),
            // Vazio = rota billing.webhooks; só https (o PagBank exige SSL).
            'notification_url' => env('PAGBANK_NOTIFICATION_URL'),
            'endpoints'        => [
                'orders'     => '/orders',
                'order_show' => '/orders/{id}',
                'payments'   => '/charges/{id}',
            ],
        ],
    ],
];
