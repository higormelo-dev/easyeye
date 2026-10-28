<?php

/*
 * Patient chronic conditions in the registration drawer (Phase 6): what the system detected, what the doctor decides
 * and the follow-ups each condition generates. Doctors only. Plural with "a|b" (picks by the :count).
 */

return [
    'title'   => 'Chronic conditions',
    'empty'   => 'No condition recorded for this patient.',
    'history' => 'History (:count)',

    'eye' => [
        'both'  => 'Both eyes (OU)',
        'right' => 'Right eye (OD)',
        'left'  => 'Left eye (OS)',
    ],

    'started_on'      => 'Follow-up started: :date',
    'started_unknown' => 'Follow-up start not informed',

    'status_hint' => [
        'suggested' => 'Suggested by the system: it only generates returns after you confirm it.',
    ],

    'decided' => [
        'rejected'   => 'Dismissed on :date',
        'discharged' => 'Discharged on :date',
    ],

    'discharge_reason' => 'Reason: :reason',

    'evidence' => [
        'cid_signed'             => 'ICD :code in a signed record (since :date)',
        'cid_unsigned'           => 'ICD :code in a record not signed yet (since :date)',
        'medication'             => 'Medication in use: :name (since :date)',
        'medication_unconfirmed' => 'Medication reported, not confirmed yet: :name (since :date)',
        'manual'                 => 'Recorded by the doctor',
        'unknown_date'           => 'unknown date',
    ],

    'medications' => [
        'hydroxychloroquine' => 'hydroxychloroquine',
        'chloroquine'        => 'chloroquine',
    ],

    'next' => [
        'title'   => 'Next returns',
        'due'     => 'due on :date',
        'missing' => 'the follow-up start date is missing',
    ],

    'protocol_inactive' => 'The protocol is not active in the clinic: this condition generates no returns.',

    'actions' => [
        'confirm'   => 'Confirm',
        'reject'    => 'Dismiss',
        'discharge' => 'Discharge',
        'edit'      => 'Edit',
        'add'       => 'Record condition',
        'save'      => 'Save',
        'cancel'    => 'Cancel',
        'saving'    => 'Saving…',
    ],

    'form' => [
        'add_title'       => 'Record condition',
        'edit_title'      => 'Edit condition',
        'protocol'        => 'Protocol',
        'protocol_choose' => 'Choose…',
        'eye'             => 'Eye',
        'started_on'      => 'Follow-up start',
        'started_hint'    => 'Optional. Without the date, some returns wait for this information.',
        'no_protocols'    => 'The clinic has not activated any protocol yet. Activate one in Returns › Configure returns.',
        'edit_note'       => 'When you correct it, the condition becomes yours: the system no longer changes it.',
    ],

    'discharge' => [
        'title'       => 'Discharge',
        'text'        => 'The returns of this condition end. The patient stays registered.',
        'reason'      => 'Reason for discharge',
        'reason_hint' => 'Required, at least :min characters.',
        'submit'      => 'Discharge',
    ],

    'done' => [
        'added'      => 'Condition recorded.',
        'saved'      => 'Condition saved.',
        'confirmed'  => 'Condition confirmed.',
        'rejected'   => 'Condition dismissed.',
        'discharged' => 'Discharge recorded.',
    ],

    'errors' => [
        'not_found'           => 'Condition or patient not found.',
        'protocol_not_active' => 'The clinic does not use this protocol (or it is on hold). Activate it in Configure returns.',
        'already_open'        => 'This patient already has this condition open for this eye.',
        'not_open'            => 'This condition is no longer open.',
        'not_suggested'       => 'This condition has already been decided.',
        'not_active'          => 'Only an active condition can be discharged.',
        'invalid_date'        => 'Invalid start date (it cannot be in the future).',
        'reason_required'     => 'Enter the reason for the discharge.',
        'generic'             => 'Could not save. Try again.',
    ],
];
