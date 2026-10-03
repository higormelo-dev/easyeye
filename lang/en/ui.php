<?php

declare(strict_types=1);

/*
 * Shared panel UI component texts (modals, menus). Sent on every page as
 * `t_ui` (HandleInertiaRequests).
 */
return [
    'close'   => 'Close',
    'loading' => 'Loading...',

    // Progresso em tempo real (WebSocket/Reverb) — composables/useImportProgress.js
    'realtime_offline' => 'Real-time connection unavailable — trying to reconnect…',

    // ICD-10 diagnosis search (Components/Panel/Cid10Picker.vue)
    'cid10' => [
        'placeholder'    => 'Search by code or diagnosis (e.g. H40.1, glaucoma)…',
        'search_label'   => 'Search diagnosis (ICD-10)',
        'suggestions'    => 'Diagnosis suggestions',
        'most_used'      => 'Most used',
        'custom'         => 'Custom',
        'create'         => "Add new diagnosis: ':term'",
        'primary'        => 'Primary diagnosis',
        'mark_primary'   => 'Mark as primary diagnosis',
        'primary_toggle' => 'Primary diagnosis: :item',
        'remove'         => 'Remove :item',
        'searching'      => 'Searching…',
        'results_one'    => ':count result',
        'results_other'  => ':count results',
        'no_results'     => 'No diagnosis found.',
    ],

    // Cadastro de paciente (componente compartilhado Pacientes/Agenda).
    'patient_form' => [
        'occupation'             => 'Occupation',
        'occupation_placeholder' => 'E.g.: teacher, driver, retired',
    ],
];
