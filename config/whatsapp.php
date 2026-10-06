<?php

/*
 * WhatsApp oficial (API da Meta) via Gupshup — ver
 * docs/integracoes/whatsapp-gupshup.md (passo a passo, lista de templates e
 * links da documentação oficial usada em cada ponto).
 */

$languages = array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_TEMPLATE_LANGUAGES', 'pt_BR')))));

return [
    // gupshup = envia de verdade pela Partner API da Gupshup
    // mock    = simulação (dev/teste): nada sai, só log — também quando vazio
    'driver' => env('WHATSAPP_DRIVER', 'mock') ?: 'mock',

    // Driver mock: o cadastro exige o código de verificação (que só aparece
    // no log) apenas em APP_ENV=local ou com esta chave = true (testes
    // automatizados — phpunit.xml). Homologação (APP_ENV=testing) e produção
    // com mock: o gate libera — ninguém receberia o código.
    'mock_requires_code' => (bool) env('WHATSAPP_MOCK_REQUIRES_CODE', false),

    // Mensagem presa em `sending` há mais que isso (worker caiu/travou no
    // meio da chamada): whatsapp:sweep-stuck (a cada 10 min) marca failed
    // unknown_delivery + alerta. Bem acima do $timeout dos jobs (150 s).
    'sending_stuck_minutes' => max(5, (int) env('WHATSAPP_SENDING_STUCK_MINUTES', 15)),

    'gupshup' => [
        'base_url' => env('GUPSHUP_BASE_URL', 'https://partner.gupshup.io'),

        // Credenciais do PARCEIRO EasyEye (só no .env, nunca no banco).
        //  - universal: Universal Token gerado no portal de parceiros (até 60
        //    dias, rotacionar antes de vencer); o sistema gera um token de app
        //    de curta duração (UAT) por app. Recomendado pela Gupshup — o
        //    token de app antigo para de funcionar em 01/04/2027.
        //    https://partner-docs.gupshup.io/docs/partner-authentication-guide-universal-tokens-ut-overview
        //  - partner_token: e-mail + client secret do parceiro → token de
        //    parceiro (24h) → token do app (longo).
        //    https://partner-docs.gupshup.io/reference/post_partner-account-login
        //    https://partner-docs.gupshup.io/reference/get_partner-app-appid-token
        // Vazio = escolhe sozinho: universal quando há GUPSHUP_UNIVERSAL_TOKEN.
        'auth_mode'       => env('GUPSHUP_AUTH_MODE', ''),
        'universal_token' => env('GUPSHUP_UNIVERSAL_TOKEN'),
        'partner_email'   => env('GUPSHUP_PARTNER_EMAIL'),
        'partner_secret'  => env('GUPSHUP_PARTNER_SECRET'),

        // Validade do Universal Token (opcional, data ISO — ex.:
        // 2026-12-01). Sem ela, o sistema lê o "exp" do próprio token (JWT).
        // O manager avisa quando faltam N dias (rotacionar em até 60 dias).
        'universal_token_expires_at' => env('GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT'),
        'universal_token_warn_days'  => (int) env('GUPSHUP_UNIVERSAL_TOKEN_WARN_DAYS', 10),

        // Validade pedida para o token de app de curta duração (UAT), em
        // horas. A Gupshup aceita expiry entre agora+1h e agora+24h e no
        // máximo 3 UATs ativos por app; com 1h o pedido chega "abaixo do
        // mínimo" (relógio/latência) — por isso o piso é 2.
        // https://partner-docs.gupshup.io/reference/mintuniversalapptoken
        'app_token_hours' => max(2, min(24, (int) env('GUPSHUP_APP_TOKEN_HOURS', 6))),

        // Quanto um worker espera (s) pelo outro que está gerando o token do
        // mesmo app (Cache::lock) antes de devolver erro transitório.
        'token_lock_wait_seconds' => (int) env('GUPSHUP_TOKEN_LOCK_WAIT', 10),

        // Validade do lock de geração do token (s, mínimo 120): cobre o pior
        // caso (listar + revogar UATs + gerar). Os jobs de envio têm
        // $timeout de 150 s — o retry_after da fila precisa ser maior.
        'token_lock_seconds' => max(120, (int) env('GUPSHUP_TOKEN_LOCK_SECONDS', 120)),

        // Eventos assinados no webhook (subscription v3).
        // https://partner-docs.gupshup.io/reference/setsubscription-api-v3
        'subscription_modes' => env('GUPSHUP_SUBSCRIPTION_MODES', 'MESSAGE,SENT,DELIVERED,READ,FAILED'),
        'subscription_tag'   => env('GUPSHUP_SUBSCRIPTION_TAG', 'easyeye-v3'),
    ],

    // Webhook: limite por token da URL (cada configuração tem o seu) — o IP
    // não serve de chave atrás de proxy (X-Forwarded-For é forjável). Folgado:
    // cada mensagem enviada gera até 3 status (sent, delivered, read).
    // Token que não existe: um limite GLOBAL (chave fixa) — tokens
    // aleatórios não ganham um balde novo cada.
    'webhook' => [
        'rate_limit_per_minute'         => (int) env('WHATSAPP_WEBHOOK_RATE_LIMIT', 1200),
        'unknown_rate_limit_per_minute' => (int) env('WHATSAPP_WEBHOOK_UNKNOWN_RATE_LIMIT', 60),
    ],

    'http' => [
        'timeout_seconds'         => (int) env('WHATSAPP_HTTP_TIMEOUT', 15),
        'connect_timeout_seconds' => (int) env('WHATSAPP_HTTP_CONNECT_TIMEOUT', 5),
    ],

    // Fila dos envios. Fila diferente de "default" precisa entrar no worker
    // (queue:work --queue=whatsapp,default).
    'queue' => env('WHATSAPP_QUEUE', 'default') ?: 'default',

    // Idiomas com templates APROVADOS na Meta. Destinatário em inglês recebe
    // o template "en" só se "en" estiver aqui; senão vai em pt_BR.
    'template_languages' => $languages !== [] ? $languages : ['pt_BR'],

    /*
     * Templates aprovados na Meta (chave lógica → nome na Meta). O corpo de
     * cada um está em docs/integracoes/whatsapp-gupshup.md (§6) e, para o
     * histórico do sistema, em lang/{pt_BR,en}/whatsapp.php (templates.*).
     *
     * body:    ordem das variáveis {{1}}, {{2}}... do corpo
     * buttons: na ordem cadastrada na Meta —
     *          quick_reply (payload por envio), url (sufixo dinâmico no fim
     *          da URL), otp (botão "copiar código" do template de autenticação)
     */
    'templates' => [
        'appointment_confirmation' => [
            'name'     => 'easyeye_confirmacao_consulta',
            'category' => 'UTILITY',
            // Rodapé "responda SAIR" (opt-out) cadastrado no template.
            'footer'  => true,
            'body'    => ['first_name', 'clinic', 'when', 'doctor'],
            'buttons' => [
                ['type' => 'quick_reply', 'action' => 'confirm'],
                ['type' => 'quick_reply', 'action' => 'cancel'],
            ],
        ],
        'satisfaction_survey' => [
            'name'     => 'easyeye_pesquisa_satisfacao',
            'category' => 'UTILITY',
            // Rodapé "responda SAIR" (opt-out) cadastrado no template.
            'footer'  => true,
            'body'    => ['first_name', 'clinic'],
            'buttons' => [
                ['type' => 'quick_reply', 'action' => 'score', 'score' => 5],
                ['type' => 'quick_reply', 'action' => 'score', 'score' => 4],
                ['type' => 'quick_reply', 'action' => 'score', 'score' => 3],
                ['type' => 'quick_reply', 'action' => 'score', 'score' => 2],
                ['type' => 'quick_reply', 'action' => 'score', 'score' => 1],
            ],
        ],
        'verification_code' => [
            'name'     => 'easyeye_codigo_verificacao',
            'category' => 'AUTHENTICATION',
            'body'     => ['code'],
            'buttons'  => [['type' => 'otp']],
        ],
        'connection_test' => [
            'name'     => 'easyeye_teste_conexao',
            'category' => 'UTILITY',
            'body'     => ['app'],
            'buttons'  => [],
        ],
        // Avisos do SaaS à clínica (régua de cobrança, fim do teste, cobrança
        // enviada pelo manager) — botão URL "Abrir o EasyEye" com sufixo.
        'saas_dunning_reminder' => [
            'name'     => 'easyeye_cobranca_lembrete',
            'category' => 'UTILITY',
            'body'     => ['entity', 'amount', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_dunning_reminder_card' => [
            'name'     => 'easyeye_cobranca_lembrete_cartao',
            'category' => 'UTILITY',
            'body'     => ['entity', 'amount', 'last4', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_dunning_overdue' => [
            'name'     => 'easyeye_cobranca_vencida',
            'category' => 'UTILITY',
            'body'     => ['amount', 'entity', 'date', 'limited_date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_dunning_limited' => [
            'name'     => 'easyeye_cobranca_acesso_limitado',
            'category' => 'UTILITY',
            'body'     => ['amount', 'entity', 'blocked_date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_dunning_terminated' => [
            'name'     => 'easyeye_assinatura_encerrada',
            'category' => 'UTILITY',
            'body'     => ['entity'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_dunning_first_charge_overdue' => [
            'name'     => 'easyeye_primeira_cobranca_vencida',
            'category' => 'UTILITY',
            'body'     => ['entity', 'amount', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_dunning_first_charge_terminated' => [
            'name'     => 'easyeye_contratacao_cancelada',
            'category' => 'UTILITY',
            'body'     => ['entity'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_trial_three_days' => [
            'name'     => 'easyeye_teste_termina_em_dias',
            'category' => 'UTILITY',
            'body'     => ['entity', 'days', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_trial_one_day' => [
            'name'     => 'easyeye_teste_termina_amanha',
            'category' => 'UTILITY',
            'body'     => ['entity', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_trial_today' => [
            'name'     => 'easyeye_teste_termina_hoje',
            'category' => 'UTILITY',
            'body'     => ['entity', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_charge' => [
            'name'     => 'easyeye_cobranca_enviada',
            'category' => 'UTILITY',
            'body'     => ['entity', 'plan', 'amount', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
        'saas_charge_plan_change' => [
            'name'     => 'easyeye_cobranca_troca_plano',
            'category' => 'UTILITY',
            'body'     => ['entity', 'plan', 'amount', 'date'],
            'buttons'  => [['type' => 'url']],
        ],
    ],

    // Palavras que o paciente responde para parar / voltar a receber
    // (comparação sem acento e sem diferença de maiúsculas).
    'opt_out_keywords' => ['sair', 'parar', 'stop', 'descadastrar', 'unsubscribe'],
    'opt_in_keywords'  => ['voltar', 'ativar', 'start'],

    // Confirmação: janela padrão de disparo (h antes da consulta) quando a
    // clínica não configurou outro valor em whatsapp_settings.
    'confirmation' => [
        'default_hours_before' => (int) env('WHATSAPP_CONFIRM_HOURS_BEFORE', 24),
        // Resposta do paciente só vale por N dias após o envio.
        'reply_valid_days' => 7,
    ],

    // Pesquisa de satisfação: atraso padrão após o atendimento.
    'survey' => [
        'default_delay_hours' => (int) env('WHATSAPP_SURVEY_DELAY_HOURS', 2),
        'reply_valid_days'    => 14,
        // Não enviar pesquisa de atendimentos mais antigos que isso (evita
        // spam retroativo ao ligar a feature numa clínica com histórico).
        'max_age_days' => 3,
    ],
];
