<?php

declare(strict_types=1);

/*
 * Textos dos componentes compartilhados das telas financeiras (PeriodFilter,
 * KpiCard, MoneyInput). Os controllers enviam como `t.shared`.
 */
return [
    'period' => [
        'label'         => 'Período',
        'from'          => 'De',
        'to'            => 'Até',
        'invalid_range' => 'A data inicial deve ser anterior ou igual à final.',
        'invalid_date'  => 'Informe uma data válida.',
        'after_max'     => 'A data não pode ser posterior a :date.',
        'presets'       => [
            'today'      => 'Hoje',
            'yesterday'  => 'Ontem',
            'last7'      => 'Últimos 7 dias',
            'month'      => 'Mês atual',
            'last_month' => 'Mês anterior',
            'year'       => 'Ano atual',
            'custom'     => 'Personalizado',
        ],
    ],
];
