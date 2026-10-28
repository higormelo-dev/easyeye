<?php

/*
 * Clinic follow-up settings (Phase 6): activate validated protocols, the doctor-requested follow-up and the exam
 * mapping. The rule itself (ICD codes, intervals, references) is shown by the shared `RecallRuleCard` component with
 * the `recall_rule` texts. Plurals use "a|b" (picked by :count).
 */

return [
    'title'    => 'Configure follow-ups',
    'subtitle' => 'Which follow-up protocols the clinic uses, and how.',
    'back'     => 'Back to follow-ups',

    'doctor_only' => 'Only a doctor with an active registration can activate or deactivate a protocol. You can see the rule and who validated it.',

    'general' => [
        'title'   => 'Follow-up requested by the doctor',
        'text'    => 'When the doctor requests a return during a visit (in days), the patient enters the follow-up list on the right date. It does not depend on a protocol.',
        'enabled' => 'Use the follow-up requested by the doctor',
        'save'    => 'Save',
    ],

    'invites' => [
        'title'              => 'WhatsApp invitation',
        'text'               => 'The clinic can invite by WhatsApp the patient whose follow-up is due soon or overdue. Only patients who authorised follow-up reminders in their record receive it; the message does not state the reason for the follow-up, and the patient can stop by replying STOP.',
        'enabled'            => 'Send follow-up invitations by WhatsApp',
        'auto_note'          => 'Automatic sending happens only for protocols with "automatic contact" on and for the doctor-requested follow-up. Patients the front desk invites from the Follow-ups list do not depend on this.',
        'unavailable'        => 'Cannot be turned on yet: :reason',
        'window_start'       => 'Send from',
        'window_end'         => 'Send until',
        'weekdays'           => 'Days of the week',
        'weekday'            => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
        'min_days'           => 'Days between invitations to the same patient',
        'min_days_hint'      => 'At least :min days, so the patient is not bothered and the WhatsApp number is not burned.',
        'max_per_cycle'      => 'Automatic invitations per follow-up',
        'max_per_cycle_hint' => 'How many times the robot invites the same follow-up before leaving it for phone calls.',
        'daily_cap'          => 'Invitations per day limit',
        'daily_cap_hint'     => 'Sending starts slowly and grows over the first weeks (today limit: :today).',
        'daily_cap_open'     => 'no warm-up limit',
        'sent_today'         => ':count invitations today.',
        'max_overdue'        => 'Stop inviting after (days overdue)',
        'max_overdue_hint'   => 'A follow-up overdue by more than this goes to active phone outreach.',
        'save'               => 'Save invitations',
    ],

    'protocols' => [
        'title' => 'Follow-up protocols',
        'intro' => 'Each protocol is a follow-up rule for one condition (glaucoma, AMD…), validated by an external ophthalmologist and released by EasyEye. The clinic chooses which to use and adjusts the intervals WITHIN the validated range.',
        'empty' => 'No protocol has been released yet. When EasyEye releases the first one, it will appear here.',

        'status' => [
            'available'        => 'Available',
            'active'           => 'Active',
            'update_available' => 'New version available',
            'paused'           => 'On hold',
            'unavailable'      => 'Unavailable',
        ],

        'version'                => 'Version :version',
        'validated_by'           => 'Validated by :name (:crm · :rqe)',
        'validated_by_anonymous' => 'Validated by an external reviewing ophthalmologist',
        'validated_on'           => 'Validated on :date',
        'accepted_on'            => 'Professional registration checked with the CFM on :date',
        'hash'                   => 'Version code: :hash',
        'review_due'             => 'Revalidation due by :date',
        'review_overdue'         => 'The revalidation of this rule expired on :date. EasyEye was notified; the rule remains valid until it decides.',
        'show_rule'              => 'View the rule',
        'hide_rule'              => 'Hide the rule',
        'active_since'           => 'Active since :date, by :name',
        'active_since_unknown'   => 'Active since :date',
        'unavailable'            => 'No released version of this protocol.',

        'paused' => [
            'suspended'  => 'EasyEye suspended this rule. No new follow-ups are calculated and the existing ones stay on hold until it comes back.',
            'superseded' => 'This version is no longer in use.',
            'retired'    => 'EasyEye retired this rule. Choose another protocol or wait for a new version.',
            'integrity'  => 'The integrity check of this version failed. EasyEye was notified; nothing new is calculated until it is checked.',
            'hash'       => 'The content of this version is not what the clinic adopted. Nothing new is calculated until it is checked.',
            'draft'      => 'This version is not in use.',
            'in_review'  => 'This version is not in use.',
            'validated'  => 'This version is not in use.',
            'declined'   => 'This version is not in use.',
            'withdrawn'  => 'This version is not in use.',
        ],

        'update' => [
            'title'       => 'A new version has been released',
            'text'        => 'Version :version was validated and released. The clinic stays on version :current until you switch. See what changed first.',
            'text_paused' => 'Version :version was validated and released. Until you switch from version :current, the returns of this protocol stay on hold. See what changed first.',
        ],

        'form' => [
            'title_activate'  => 'Activate this protocol',
            'title_adjust'    => 'Adjust the parameters',
            'title_upgrade'   => 'Switch to version :version',
            'tasks'           => 'Tasks and intervals',
            'enabled'         => 'On',
            'required'        => 'Required',
            'optional'        => 'Optional',
            'interval'        => 'Interval',
            'range'           => ':min to :max :unit (default :default)',
            'units'           => ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'],
            'acknowledge'     => 'I have read the rule and its validation and I am responsible for activating it in this clinic.',
            'submit_activate' => 'Activate protocol',
            'submit_adjust'   => 'Save adjustments',
            'submit_upgrade'  => 'Switch to version :version',
        ],

        'deactivate' => [
            'button'      => 'Deactivate',
            'title'       => 'Deactivate this protocol',
            'text'        => 'The follow-ups of this protocol leave the list. The patients\' conditions stay registered and come back if the protocol is activated again.',
            'reason'      => 'Reason',
            'reason_hint' => 'Required, at least :min characters.',
            'submit'      => 'Deactivate',
            'cancel'      => 'Cancel',
        ],
    ],

    'exams' => [
        'title'       => 'Exams',
        'text'        => 'Tell which of the clinic\'s exams count as each protocol exam. An exam done and recorded at the clinic postpones the next follow-up of that exam.',
        'none'        => 'No exam associated',
        'unavailable' => 'deactivated',
        'suggested'   => 'Suggestion: :name',
        'add'         => 'Add',
        'add_label'   => 'Add an exam to ":exam"',
        'remove'      => 'Remove :name',
        'select'      => 'Choose an exam…',
        'save'        => 'Save exams',
    ],

    'done' => [
        'general'     => 'Settings saved.',
        'activated'   => 'Protocol activated. Patients\' follow-ups are calculated over the next few minutes.',
        'deactivated' => 'Protocol deactivated.',
        'mappings'    => 'Exams saved. Follow-ups are recalculated over the next few minutes.',
    ],

    'errors' => [
        'protocol_unavailable'             => 'This protocol is not available.',
        'version_mismatch'                 => 'The version does not belong to this protocol.',
        'version_not_released'             => 'Only the released version can be activated.',
        'no_validation_evidence'           => 'This version does not have the complete validation proof. Contact EasyEye.',
        'not_acknowledged'                 => 'Confirm that you read the rule and its validation.',
        'not_adopted'                      => 'This protocol is not active in the clinic.',
        'reason_required'                  => 'Please give the reason.',
        'invalid_parameters'               => 'Some parameters are outside what was validated.',
        'interval_out_of_range'            => 'Outside the validated range.',
        'interval_not_integer'             => 'Use a whole number.',
        'required_task_cannot_be_disabled' => 'This task is required.',
        'enabled_not_boolean'              => 'Invalid value.',
        'unknown_task'                     => 'Unknown task.',
        'unknown_exam_key'                 => 'Unknown exam: :key.',
        'invalid_mapping'                  => 'Some exam cannot be used by the clinic.',
    ],
];
