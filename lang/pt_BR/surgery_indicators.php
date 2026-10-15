<?php

declare(strict_types=1);

/**
 * Módulo cirúrgico (Fase 4) / M5 — indicadores. Entregue às telas como
 * `t.indicators.*` (App\Support\Surgery\SurgeryLang).
 */
return [
    'title'           => 'Indicadores cirúrgicos',
    'subtitle'        => 'Autorização, prazos, cirurgias e resultado refracional.',
    'scope_clinic'    => 'Clínica inteira',
    'scope_doctor'    => 'Somente os seus casos',
    'only_aggregates' => 'Apenas números agregados: nenhum paciente é exibido.',

    'filters' => [
        'from'     => 'De',
        'to'       => 'Até',
        'apply'    => 'Aplicar',
        'last_30'  => '30 dias',
        'last_90'  => '90 dias',
        'last_365' => '12 meses',
    ],
    'export'       => 'Exportar CSV',
    'empty'        => 'Sem dados no período.',
    'days'         => ':value dias',
    'small_sample' => 'Amostra pequena (n < :n): leia com cautela.',

    'authorization' => [
        'title'         => 'Autorização',
        'requested'     => 'Pedidos enviados',
        'authorized'    => 'Autorizadas',
        'partial'       => 'Parciais',
        'waived'        => 'Dispensadas',
        'denied'        => 'Negadas',
        'rate'          => 'Taxa de autorização (%)',
        'rate_hint'     => '(autorizadas + parciais + dispensadas) ÷ respondidas',
        'reasons'       => 'Principais motivos de negativa',
        'other_reasons' => 'Outros',
        'reason'        => 'Negativa: :reason',
    ],
    'times' => [
        'title'                    => 'Prazos (dias)',
        'request_to_authorization' => 'Pedido → autorização',
        'authorization_to_surgery' => 'Autorização → cirurgia',
        'request_to_surgery'       => 'Pedido → cirurgia',
        'mean'                     => 'Média',
        'median'                   => 'Mediana',
        'cases'                    => 'Casos',
    ],
    'cancellations' => [
        'title'     => 'Cancelamentos e suspensões',
        'cancelled' => 'Cancelamentos',
        'suspended' => 'Suspensões',
    ],
    'surgeries' => [
        'title'       => 'Cirurgias realizadas',
        'total'       => 'Total no período',
        'by_surgeon'  => 'Por cirurgião',
        'by_facility' => 'Por local',
        'by_month'    => 'Por mês',
        'count'       => 'Cirurgias',
    ],
    'complications' => [
        'title'         => 'Achados e complicações por marco',
        'milestone'     => 'Marco',
        'evaluations'   => 'Avaliações',
        'with_findings' => 'Com achados',
        'severe'        => 'Graves',
    ],
    'refractive' => [
        'title'      => 'Erro refracional (obtido − previsto)',
        'subtitle'   => 'Equivalente esférico da avaliação finalizada mais tardia, por olho com LIO planejada.',
        'surgeon'    => 'Cirurgião',
        'n'          => 'Olhos (n)',
        'me'         => 'Erro médio (D)',
        'mae'        => 'Erro absoluto médio (D)',
        'within_050' => 'Dentro de ±0,50 D (%)',
        'within_100' => 'Dentro de ±1,00 D (%)',
        'overall'    => 'Geral',
        'no_data'    => 'Nenhum olho com LIO prevista e refração pós-operatória no período.',
    ],
    'chart_alt' => 'Gráfico de barras: :title. Os mesmos valores estão na tabela ao lado.',

    'card' => [
        'title'     => 'Cirurgias',
        'surgeries' => 'Realizadas (90 dias)',
        'rate'      => 'Taxa de autorização',
        'open'      => 'Aguardando autorização',
        'details'   => 'Ver indicadores',
    ],

    'csv' => [
        'section'       => 'Seção',
        'indicator'     => 'Indicador',
        'value'         => 'Valor',
        'mean'          => 'média',
        'median'        => 'mediana',
        'with_findings' => 'com achados',
        'severe'        => 'graves',
        'authorization' => 'Autorização',
        'times'         => 'Prazos',
        'cancellations' => 'Cancelamentos',
        'surgeries'     => 'Cirurgias',
        'complications' => 'Complicações',
        'refractive'    => 'Erro refracional',
    ],
];
