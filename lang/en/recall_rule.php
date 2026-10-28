<?php

/*
 * SHARED display texts for a return-protocol rule: used by the Manager, the Clinical
 * Reviewer page and the clinic adoption screen — the same component (RecallRuleCard)
 * shows the rule in all three places, so what the Reviewer approves is what the clinic
 * reads. Plural with "a|b" (picked by the :count value).
 */

return [
    'entry' => [
        'title'            => 'When a patient enters this protocol',
        'strong'           => 'ICD codes that activate the condition',
        'strong_hint'      => 'The patient enters as an active condition when one of these ICD codes is in a signed record.',
        'weak'             => 'ICD codes that only suggest',
        'weak_hint'        => 'Only creates a suggestion for the doctor to confirm; it creates no return and no invitation.',
        'exclude'          => 'ICD codes that do not enter',
        'exclude_hint'     => 'The most specific code wins: a longer prefix in another list decides.',
        'medications'      => 'Medications',
        'medications_hint' => 'Confirmed use of the medication activates the condition.',
        'none'             => 'None',
    ],

    'tasks' => [
        'title'            => 'What to follow up and how often',
        'interval_default' => 'Default: :value :unit',
        'range'            => 'The clinic may use :min to :max :unit',
        'lead'             => 'Invitation :count day before the due date|Invitation :count days before the due date',
        'lead_zero'        => 'Invitation only on the due date',
        'grace'            => 'Grace of :count day after the due date|Grace of :count days after the due date',
        'grace_zero'       => 'No grace period',
        'lost'             => 'Lost to follow-up after :count day overdue|Lost to follow-up after :count days overdue',
        'starts_after'     => 'Starts :count month after the condition began|Starts :count months after the condition began',
        'required'         => 'Required',
        'optional'         => 'Optional (the clinic chooses)',
        'recurs'           => 'Repeats',
        'once'             => 'Only once',
        'empty'            => 'No tasks.',
    ],

    'doctor_order' => [
        'title'  => 'Return requested by the doctor',
        'cap'    => 'The return the doctor requests in the record does not exceed the validated maximum interval; above it, the system uses the maximum and flags it.',
        'no_cap' => 'The return the doctor requests in the record is used as is, even above the validated maximum interval.',
    ],

    'rationale' => ['title' => 'Rationale'],

    'change_note' => ['title' => 'What changed in this version'],

    'references' => [
        'title'        => 'References',
        'none'         => 'No references.',
        'year_unknown' => 'n.d.',
    ],

    'medication_names' => [
        'hydroxychloroquine' => 'Hydroxychloroquine',
        'chloroquine'        => 'Chloroquine',
    ],

    'yes'         => 'Yes',
    'no'          => 'No',
    'empty_value' => '—',

    'diff' => [
        'title'    => 'What changed from the previous version',
        'none'     => 'No difference in the rules (only the presentation text may have changed).',
        'first'    => 'First version: there is no previous version to compare.',
        'added'    => 'Added',
        'removed'  => 'Removed',
        'changed'  => 'Changed',
        'from_to'  => 'from :from to :to',
        'sections' => [
            'cid.strong'   => 'ICD codes that activate the condition',
            'cid.weak'     => 'ICD codes that only suggest',
            'cid.exclude'  => 'ICD codes that do not enter',
            'medication'   => 'Medication',
            'task'         => 'Task',
            'doctor_order' => 'Return requested by the doctor',
            'reference'    => 'Reference',
            'rationale'    => 'Rationale',
            'change_note'  => 'What changed in this version',
        ],
        'fields' => [
            'kind'                => 'type',
            'exam_key'            => 'exam',
            'interval.unit'       => 'interval unit',
            'interval.default'    => 'default interval',
            'interval.min'        => 'minimum interval',
            'interval.max'        => 'maximum interval',
            'lead_days'           => 'invitation days before the due date',
            'grace_days'          => 'grace days',
            'lost_after_days'     => 'days until lost to follow-up',
            'starts_after_months' => 'starts after (months)',
            'recurs'              => 'repeats',
            'required'            => 'required',
            'cap_to_max'          => 'cap at the validated maximum',
            'year'                => 'year',
            'evidence'            => 'evidence type',
            'note'                => 'note',
        ],
    ],

    'examples' => [
        'title'      => 'Date examples',
        'intro'      => 'If the last visit was on :date, the system calculates:',
        'intro_hint' => 'This is the same calculation the system makes for a patient; it is only here to check that the deadlines make sense.',
        'task'       => 'Task',
        'due'        => 'Due on',
        'invite'     => 'Invitation from',
        'grace'      => 'Grace until',
        'lost'       => 'Lost to follow-up after',
        'starts'     => 'Starts on',
        'date_label' => 'Date of the last visit',
    ],
];
