<?php

// E-mail ao time do SaaS quando a configuração de produção tem algo simulado ou desligado (ops:check-production-config).
return [
    'config' => [
        'subject'  => '[Alerta de configuração] :count item(ns) simulado(s) ou desligado(s) em produção',
        'greeting' => 'Olá, :name!',
        'intro'    => 'A configuração de produção está com estes itens simulados ou desligados:',
        'hint'     => 'Cada item vem do .env do servidor (docs/internal/runbooks/alertas-config-producao.md). Depois de corrigir, rode php artisan config:cache. O aviso de cada item se repete no máximo uma vez por semana.',
    ],

    'findings' => [
        'turnstile_off'      => 'Captcha do cadastro desligado: TURNSTILE_SITE_KEY e TURNSTILE_SECRET_KEY ausentes, então o /register aprova sem captcha.',
        'tiss_simulated'     => 'TISS em modo simulação (TISS_TRANSPORT_DRIVER diferente de http): nenhum lote chega à operadora e, em produção, o envio fica bloqueado.',
        'whatsapp_simulated' => 'WhatsApp em simulação (WHATSAPP_DRIVER diferente de gupshup): nenhuma mensagem sai de verdade.',
        'whatsapp_sandbox'   => 'WhatsApp em sandbox (GUPSHUP_SANDBOX=true): o teste de conexão usa o modelo do sandbox.',
        'mail_not_sending'   => 'E-mail só no log (MAIL_MAILER=log ou array): nenhum e-mail sai, nem este alerta.',
        'ai_fake'            => 'IA em modo de mentira (AI_PROVIDER_RUNTIME diferente de real): as respostas não vêm de um provedor.',
    ],
];
