<?php

declare(strict_types=1);

/*
 * Shared financial screen components (PeriodFilter, KpiCard, MoneyInput).
 * Controllers send these as `t.shared`.
 */
return [
    'period' => [
        'label'         => 'Period',
        'from'          => 'From',
        'to'            => 'To',
        'invalid_range' => 'The start date must be on or before the end date.',
        'invalid_date'  => 'Please enter a valid date.',
        'after_max'     => 'The date cannot be after :date.',
        'presets'       => [
            'today'      => 'Today',
            'yesterday'  => 'Yesterday',
            'last7'      => 'Last 7 days',
            'month'      => 'This month',
            'last_month' => 'Last month',
            'year'       => 'This year',
            'custom'     => 'Custom',
        ],
    ],
];
