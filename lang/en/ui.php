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
        'placeholder'  => 'Search by code or diagnosis (e.g. H40.1, glaucoma)…',
        'search_label' => 'Search diagnosis (ICD-10)',
        'suggestions'  => 'Diagnosis suggestions',
        'most_used'    => 'Most used',
        'custom'       => 'Custom',
        // Code created in the manager (outside the official DATASUS table).
        'non_official'      => 'Outside the official table',
        'non_official_hint' => 'Code outside the official ICD-10 table (DATASUS): TISS claims may be rejected.',
        'create'            => "Add new diagnosis: ':term'",
        'primary'           => 'Primary diagnosis',
        'mark_primary'      => 'Mark as primary diagnosis',
        'primary_toggle'    => 'Primary diagnosis: :item',
        'remove'            => 'Remove :item',
        'searching'         => 'Searching…',
        'results_one'       => ':count result',
        'results_other'     => ':count results',
        'no_results'        => 'No diagnosis found.',
    ],

    // Cadastro de paciente (componente compartilhado Pacientes/Agenda).
    'patient_form' => [
        // Mobile replied STOP to the clinic/EasyEye WhatsApp.
        'wa_opted_out'      => 'Opted out of WhatsApp',
        'wa_opted_out_hint' => 'This mobile replied STOP: it gets no confirmation or survey via WhatsApp until it replies START.',
        // Authorisation for follow-up reminders by WhatsApp (Phase 6, LGPD).
        'care_reminders'                => 'Authorises follow-up reminders by WhatsApp',
        'care_reminders_hint'           => 'The reminder does not state the reason for the follow-up. The patient can stop at any time by replying STOP.',
        'care_reminders_needs_whatsapp' => 'Check that the mobile phone has WhatsApp to record this authorisation.',
        'care_reminders_badge'          => 'Follow-up reminders',
        'care_reminders_badge_hint'     => 'The patient authorised follow-up reminders by WhatsApp.',
        'occupation'                    => 'Occupation',
        'occupation_placeholder'        => 'E.g.: teacher, driver, retired',
        // Insurer plan (ANS product or clinic plan)
        'plan'                 => 'Plan',
        'plan_placeholder'     => 'Search by name or ANS registry',
        'plan_select_covenant' => 'Choose the insurer first',
        'plan_particular'      => 'Not applicable to self-pay',
        'plan_empty'           => 'No plans for this insurer',
        'plan_no_results'      => 'No plans found',
        'plan_none'            => 'No plans registered for this insurer. The clinic can add them in Settings › Insurers › Plans.',
        'plan_unavailable'     => 'This plan is no longer available (cancelled at ANS or deactivated). Choose another one when updating the record.',
        'plan_hint'            => 'Optional. The ANS product registry is printed on the member card.',
        // Patient's insurer deactivated after registration (kept, marked).
        'covenant_inactive_suffix' => '(inactive)',
        // Loading allergies/medications failed — switching tabs away and back retries (Codex round 18, P2).
        'safety_load_error' => 'Could not load allergies and medications. Switch tabs and come back to retry.',
        // Same name as another patient of the clinic (PatientDuplicateFinder).
        'homonym_title'   => 'Patient with the same name',
        'homonym_confirm' => 'I confirm this is a different patient',
    ],
    'patient_merge' => [
        'title'              => 'Merge records',
        'hint'               => 'The open record (:code) will be kept. Select the source record to transfer and delete. This cannot be undone. Review billing and previously submitted claims before confirming.',
        'duplicates'         => 'Likely duplicates',
        'homonyms'           => 'Same-name patients',
        'none'               => 'No candidate records found.',
        'confirm'            => 'Confirm merge',
        'cancel'             => 'Cancel',
        'error'              => 'Could not merge records. Please try again.',
        'loading'            => 'Loading candidates…',
        'retry'              => 'Try again',
        'birth_date_unknown' => 'Birth date not provided',
        'processing'         => 'Merging…',
        'direction'          => 'Transfer :source into :target. :source will be deleted; :target will be kept.',
        'merged_into'        => 'Merged into :code',
    ],
];
