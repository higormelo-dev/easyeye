<?php

declare(strict_types=1);

/**
 * Surgical module (Phase 4) / M5 — indicators. Delivered to the screens as
 * `t.indicators.*` (App\Support\Surgery\SurgeryLang).
 */
return [
    'title'           => 'Surgical indicators',
    'subtitle'        => 'Authorization, lead times, surgeries and refractive outcome.',
    'scope_clinic'    => 'Whole clinic',
    'scope_doctor'    => 'Your cases only',
    'only_aggregates' => 'Aggregated numbers only: no patient is shown.',

    'filters' => [
        'from'     => 'From',
        'to'       => 'To',
        'apply'    => 'Apply',
        'last_30'  => '30 days',
        'last_90'  => '90 days',
        'last_365' => '12 months',
    ],
    'export'       => 'Export CSV',
    'empty'        => 'No data in the period.',
    'days'         => ':value days',
    'small_sample' => 'Small sample (n < :n): read with caution.',

    'authorization' => [
        'title'         => 'Authorization',
        'requested'     => 'Requests sent',
        'authorized'    => 'Authorized',
        'partial'       => 'Partial',
        'waived'        => 'Waived',
        'denied'        => 'Denied',
        'rate'          => 'Authorization rate (%)',
        'rate_hint'     => '(authorized + partial + waived) ÷ answered',
        'reasons'       => 'Top denial reasons',
        'other_reasons' => 'Other',
        'reason'        => 'Denial: :reason',
    ],
    'times' => [
        'title'                    => 'Lead times (days)',
        'request_to_authorization' => 'Request → authorization',
        'authorization_to_surgery' => 'Authorization → surgery',
        'request_to_surgery'       => 'Request → surgery',
        'mean'                     => 'Mean',
        'median'                   => 'Median',
        'cases'                    => 'Cases',
    ],
    'cancellations' => [
        'title'     => 'Cancellations and suspensions',
        'cancelled' => 'Cancellations',
        'suspended' => 'Suspensions',
    ],
    'surgeries' => [
        'title'       => 'Surgeries performed',
        'total'       => 'Total in the period',
        'by_surgeon'  => 'By surgeon',
        'by_facility' => 'By location',
        'by_month'    => 'By month',
        'count'       => 'Surgeries',
    ],
    'complications' => [
        'title'         => 'Findings and complications by milestone',
        'milestone'     => 'Milestone',
        'evaluations'   => 'Evaluations',
        'with_findings' => 'With findings',
        'severe'        => 'Severe',
    ],
    'refractive' => [
        'title'      => 'Refractive error (achieved − predicted)',
        'subtitle'   => 'Spherical equivalent of the latest finalized evaluation, per eye with a planned IOL.',
        'surgeon'    => 'Surgeon',
        'n'          => 'Eyes (n)',
        'me'         => 'Mean error (D)',
        'mae'        => 'Mean absolute error (D)',
        'within_050' => 'Within ±0.50 D (%)',
        'within_100' => 'Within ±1.00 D (%)',
        'overall'    => 'Overall',
        'no_data'    => 'No eye with a predicted IOL and post-op refraction in the period.',
    ],
    'chart_alt' => 'Bar chart: :title. The same values are in the table beside it.',

    'card' => [
        'title'     => 'Surgeries',
        'surgeries' => 'Performed (90 days)',
        'rate'      => 'Authorization rate',
        'open'      => 'Awaiting authorization',
        'details'   => 'View indicators',
    ],

    'csv' => [
        'section'       => 'Section',
        'indicator'     => 'Indicator',
        'value'         => 'Value',
        'mean'          => 'mean',
        'median'        => 'median',
        'with_findings' => 'with findings',
        'severe'        => 'severe',
        'authorization' => 'Authorization',
        'times'         => 'Lead times',
        'cancellations' => 'Cancellations',
        'surgeries'     => 'Surgeries',
        'complications' => 'Complications',
        'refractive'    => 'Refractive error',
    ],
];
