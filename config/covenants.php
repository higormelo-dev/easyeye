<?php

/*
|--------------------------------------------------------------------------
| Catálogo global de convênios (operadoras de planos de saúde — ANS)
|--------------------------------------------------------------------------
|
| Fonte: dados abertos da ANS (Cadastro de Operadoras — CADOP), atualizados
| diariamente. A lista de ativas traz cadastro e modalidade; a de canceladas
| traz data e motivo do descredenciamento. Ver AnsOperatorImportService.
*/

return [
    'ans' => [
        'active_url'    => env('ANS_OPERATORS_ACTIVE_URL', 'https://dadosabertos.ans.gov.br/FTP/PDA/operadoras_de_plano_de_saude_ativas/Relatorio_cadop.csv'),
        'cancelled_url' => env('ANS_OPERATORS_CANCELLED_URL', 'https://dadosabertos.ans.gov.br/FTP/PDA/operadoras_de_plano_de_saude_canceladas/Relatorio_cadop_canceladas.csv'),

        'timeout_seconds' => (int) env('ANS_OPERATORS_TIMEOUT', 60),

        // Teto do arquivo baixado/enviado (a lista de canceladas tem ~1 MB).
        'max_bytes' => 20 * 1024 * 1024,

        // Planos (produtos registrados na ANS): ~75 MB, ~166 mil produtos.
        // Baixado direto para o disco e lido em streaming — nunca em memória.
        'plans_url'             => env('ANS_PLANS_URL', 'https://dadosabertos.ans.gov.br/FTP/PDA/caracteristicas_produtos_saude_suplementar-008/pda-008-caracteristicas_produtos_saude_suplementar.csv'),
        'plans_timeout_seconds' => (int) env('ANS_PLANS_TIMEOUT', 600),
        'plans_max_bytes'       => 300 * 1024 * 1024,
        'plans_enabled'         => (bool) env('ANS_PLANS_SYNC_ENABLED', true),

        // Atualização automática semanal (só lê dados públicos da ANS).
        'sync_enabled' => (bool) env('ANS_OPERATORS_SYNC_ENABLED', false),

        // Modalidades da ANS (texto exato do CADOP). Odontológicas ficam de
        // fora por padrão — não pagam consulta oftalmológica.
        'modalities' => [
            'Cooperativa Médica',
            'Medicina de Grupo',
            'Autogestão',
            'Filantropia',
            'Seguradora Especializada em Saúde',
            'Administradora de Benefícios',
            'Odontologia de Grupo',
            'Cooperativa odontológica',
        ],

        'default_modalities' => [
            'Cooperativa Médica',
            'Medicina de Grupo',
            'Autogestão',
            'Filantropia',
            'Seguradora Especializada em Saúde',
            'Administradora de Benefícios',
        ],
    ],
];
