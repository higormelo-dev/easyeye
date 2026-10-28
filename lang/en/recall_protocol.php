<?php

/*
 * Return (recall) protocols — Phase 6. State/task/exam labels and the content
 * validation messages (ProtocolContentSchema). The clinical content itself
 * (rules, rationale, references) is NOT translated: it is the exact text the
 * Clinical Reviewer approved (ADR 0002).
 */

return [
    'states' => [
        'draft'      => 'Draft',
        'in_review'  => 'In review',
        'validated'  => 'Validated',
        'released'   => 'Released',
        'superseded' => 'Superseded',
        'suspended'  => 'Suspended',
        'declined'   => 'Declined by reviewer',
        'withdrawn'  => 'Withdrawn',
        'retired'    => 'Retired',
    ],

    'task_kinds' => [
        'consultation'     => 'Consultation',
        'exam'             => 'Exam',
        'treatment_course' => 'Treatment course',
    ],

    'exam_keys' => [
        'visual_field'       => 'Visual field',
        'oct_optic_nerve'    => 'Optic nerve OCT',
        'oct_macula'         => 'Macular OCT',
        'retinography'       => 'Fundus photography',
        'corneal_topography' => 'Corneal topography',
    ],

    'units' => [
        'days'   => 'days',
        'weeks'  => 'weeks',
        'months' => 'months',
    ],

    'units_one' => [
        'days'   => 'day',
        'weeks'  => 'week',
        'months' => 'month',
    ],

    'evidence' => [
        'high'   => 'Direct evidence',
        'medium' => 'Indirect evidence',
        'low'    => 'Opinion / consensus',
    ],

    'errors' => [
        'not_an_object'              => 'Expected an object.',
        'not_a_list'                 => 'Expected a list.',
        'unknown_field'              => 'Unknown field: :field.',
        'required'                   => 'Required field.',
        'must_be_string'             => 'Must be text.',
        'must_be_integer'            => 'Must be a whole number.',
        'must_be_boolean'            => 'Must be yes or no.',
        'invalid_utf8'               => 'Text with invalid characters.',
        'control_characters'         => 'Text with disallowed control characters.',
        'too_long'                   => 'At most :max characters.',
        'out_of_range'               => 'Must be between :min and :max.',
        'invalid_choice'             => 'Value not allowed: :value.',
        'invalid_key'                => 'Invalid key: use lowercase letters, numbers and _ (2 to 40 characters, starting with a letter).',
        'invalid_cid'                => 'Invalid ICD code: :value (use the code without a dot, e.g. H401).',
        'cid_conflict'               => 'ICD code :value is in more than one list (strong, weak, exclusion).',
        'invalid_medication'         => 'Unknown medication: :value.',
        'duplicate'                  => 'Repeated value: :value.',
        'too_many'                   => 'At most :max items.',
        'too_few'                    => 'Provide at least :min item.',
        'exam_key_required'          => 'Choose the exam for this task.',
        'exam_key_not_allowed'       => 'Only exam tasks have an exam.',
        'interval_order'             => 'The minimum must be less than or equal to the default, and the default less than or equal to the maximum.',
        'lost_not_after_grace'       => '"Lost to follow-up" must come after the grace period.',
        'no_required_task'           => 'At least one task must be required.',
        'no_entry_criteria'          => 'Provide at least one strong ICD code or one entry medication.',
        'change_note_required'       => 'Describe what changed from the previous version.',
        'schema_version_unsupported' => 'Unsupported schema version.',
    ],
];
