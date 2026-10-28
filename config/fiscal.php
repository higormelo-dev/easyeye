<?php

/*
 * NFS-e do SaaS via Focus NFe (https://doc.focusnfe.com.br). Só a dona do SaaS emite: o prestador é global (Manager →
 * Nota fiscal) e o tomador é a clínica que pagou uma fatura. Os tokens e os dados do prestador NÃO moram aqui:
 * tokens em `nfse_credentials` (cifrados) e dados fiscais em `system_settings` (grupo `nfse`).
 */
return [
    // Hosts da Focus por ambiente. Sem env de propósito: o token viaja como HTTP Basic para este host, então trocá-lo
    // (ou apontar para um proxy) é uma mudança de código revisada, nunca uma variável de ambiente.
    'base_urls' => [
        'producao'    => 'https://api.focusnfe.com.br',
        'homologacao' => 'https://homologacao.focusnfe.com.br',
    ],

    'timeout'         => 30,
    'connect_timeout' => 10,

    // Fila dos jobs fiscais (envio, webhook, cancelamento por estorno). Único env desta área, opcional.
    'queue' => env('FISCAL_QUEUE', 'default'),

    // Pasta (no disco padrão) dos XMLs: nfse/{ambiente}/{Y}/{m}/{ref}.xml. Guarda de 5 anos — backup do volume.
    'storage_path' => 'nfse',

    // fiscal:nfse-sync — fallback do webhook (a Focus retenta por até 48 h e depois só a consulta direta resolve).
    //   after_minutes: nota em processamento há mais que isso é consultada na Focus;
    //   stale_pending_minutes: pending_send há mais que isso (job perdido) é reenviado com a mesma ref;
    //   limit: notas por varredura.
    'sync' => [
        'after_minutes'         => 10,
        'stale_pending_minutes' => 15,
        'limit'                 => 100,
    ],

    // fiscal:nfse-reissue — faturas pagas sem nota. window_days: só olha pagamentos recentes (emissão automática vale da
    // entrada em produção pra frente); limit: faturas por execução.
    'reissue' => [
        'window_days' => 90,
        'limit'       => 50,
    ],

    // Webhook da Focus (POST /api/fiscal/focusnfe/webhook/{token}): o segredo vai no cabeçalho abaixo, escolhido por nós
    // ao registrar o hook (authorization_header).
    'webhook' => [
        'header'                        => 'X-FocusNfe-Secret',
        'rate_limit_per_minute'         => 120,
        'unknown_rate_limit_per_minute' => 60,
    ],

    // Hosts aceitos nos links que a Focus devolve (espelho, PDF): padrões com `*`, https, sem porta. Fora disso o link é
    // descartado e logado (viram href no Manager e na clínica). `*.nfse.gov.br` = consulta pública do padrão nacional.
    'allowed_url_hosts' => ['focusnfe.com.br', '*.focusnfe.com.br', 'focusnfe.s3.*.amazonaws.com', 'nfse.gov.br', '*.nfse.gov.br'],

    // Códigos que podem ir ao log de cobrança (NfseLogText::code): os documentados da Focus e os nossos internos. Além deles só
    // passa o padrão de prefeitura ^[A-Z]{1,3}[0-9]{1,4}$ (L99, V999); o resto vira `unknown`.
    'log_codes' => [
        'requisicao_invalida', 'erro_validacao', 'nfe_autorizada', 'nfe_nao_autorizada', 'nao_encontrado', 'erro_autorizacao',
        'erro_cancelamento', 'permissao_negada', 'auth', 'rate_limited', 'http', 'nfse_nao_autorizada',
        // internos (constantes do EasyEye, nunca vindos da Focus)
        'credencial_ausente', 'justificativa_invalida', 'sem_cancelamento', 'send_failed', 'inesperado',
    ],

    // Tetos de texto. A discriminação do municipal varia por prefeitura (a Focus não publica o limite): padrão 2000; o nacional
    // é fixo em 1000 (DPS, descricao_servico).
    'limits' => [
        'discriminacao' => 2000,
    ],

    // Obrigatoriedades do município (GET /v2/municipios/{ibge}) mudam pouco: cache por ambiente.
    'municipality' => [
        'cache_hours' => 24,
    ],
];
