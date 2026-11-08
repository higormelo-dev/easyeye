<?php

use App\Support\Sentry\SentryDataScrubber;

// Só o que difere de vendor/sentry/sentry-laravel/config/sentry.php: o pacote
// mescla as chaves de topo (mergeConfigFrom), então DSN, sample rate etc. seguem
// vindo do arquivo do pacote e das variáveis SENTRY_*.
//
// LGPD (arts. 6º, III e 33): o Sentry processa fora do Brasil e diagnosticar um
// erro não exige dado pessoal nem de saúde.
//  - `data_collection` (controle nativo do SDK, vale para erros, transações,
//    spans e breadcrumbs HTTP): sem IP/usuário, cookies, corpo, query string,
//    valores de SQL, argumentos das funções da pilha, payload de fila ou de IA;
//    cabeçalhos só os técnicos da lista.
//  - SentryDataScrubber: o que o SDK não filtra — mensagem de exceção do banco
//    (QueryException traz os valores do SQL e o DETAIL do PostgreSQL) e logs da
//    aplicação como breadcrumb (texto livre).
return [
    'max_request_body_size' => env('SENTRY_MAX_REQUEST_BODY_SIZE', 'never'),

    'data_collection' => [
        'user_info'    => false,
        'cookies'      => ['mode' => 'off'],
        'http_headers' => [
            'request'  => ['mode' => 'allowList', 'terms' => ['user-agent', 'accept', 'accept-language', 'content-type', 'content-length']],
            'response' => ['mode' => 'allowList', 'terms' => ['content-type', 'content-length']],
        ],
        'http_bodies'           => [],
        'url_query_params'      => ['mode' => 'off'],
        'gen_ai'                => ['inputs' => false, 'outputs' => false],
        'database_query_data'   => false,
        'queues'                => false,
        'stack_frame_variables' => false,
    ],

    'before_send' => [SentryDataScrubber::class, 'beforeSend'],

    'before_send_transaction' => [SentryDataScrubber::class, 'beforeSend'],

    'before_breadcrumb' => [SentryDataScrubber::class, 'beforeBreadcrumb'],
];
