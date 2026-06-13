<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Documentação da API de Integradores (Swagger UI)
    |--------------------------------------------------------------------------
    |
    | A rota /docs/api é protegida por senha (formulário + sessão). Quando a
    | senha NÃO estiver configurada, as rotas respondem 404 — a documentação
    | nunca fica acessível por omissão de configuração.
    |
    */

    'api' => [
        'password' => env('DOCS_API_PASSWORD'),

        // Caminho da spec OpenAPI servida pelo Swagger UI.
        'spec_path' => base_path('docs/api/integrators-openapi.yaml'),

        // Servers exibidos no Swagger UI. A lista final é montada por ambiente
        // em ApiDocsController::servers() — em deploy (homolog/produção) o
        // server de desenvolvimento local NUNCA aparece.
        'servers' => [
            'production' => [
                'url'         => env('DOCS_API_SERVER_PRODUCTION', 'https://easyeye.app'),
                'description' => 'Produção',
            ],
            'homologation' => [
                'url'         => env('DOCS_API_SERVER_HOMOLOGATION', 'https://teste.easyeye.app'),
                'description' => 'Homologação',
            ],
        ],
    ],
];
