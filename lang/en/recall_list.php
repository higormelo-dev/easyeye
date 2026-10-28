<?php

/*
 * "Returns" screen (Phase 6): who needs to come back to the clinic. The shared vocabulary (due-date states,
 * lifecycle, reasons) is in `recall.php`; this file has the screen's own texts. Plurals use "a|b" (picked by
 * :count). No diagnosis for non-doctors: the screen says "what is missing".
 */

return [
    'title'    => 'Returns',
    'subtitle' => 'Patients who need to come back: contact, schedule or dismiss.',
    'settings' => 'Settings',

    'tabs' => [
        'label'      => 'Returns list views',
        'to_contact' => 'To contact',
        'scheduled'  => 'Scheduled',
        'lost'       => 'Lost to follow-up',
        'snoozed'    => 'Snoozed',
        'history'    => 'History',
        'needs_info' => 'Needs information',
        'suspended'  => 'Rule suspended',
    ],

    'kpi' => [
        'breakdown' => ':soon due soon · :overdue overdue',
    ],

    'filters' => [
        'search'              => 'Search patient',
        'search_placeholder'  => 'Patient name or code',
        'source'              => 'Source',
        'source_all'          => 'All',
        'source_doctor_order' => 'Follow-up requested by the doctor',
        'source_protocol'     => 'Condition follow-up',
        'source_course'       => 'Treatment injections',
        'clear'               => 'Clear filters',
    ],

    'columns' => [
        'patient' => 'Patient',
        'task'    => 'What is missing',
        'due'     => 'Due',
        'contact' => 'Contact',
        'actions' => 'Actions',
    ],

    'due' => [
        'today'      => 'Due today',
        'in'         => 'Due in :count day|Due in :count days',
        'overdue_by' => 'Overdue by :count day|Overdue by :count days',
        'on'         => 'On :date',
    ],

    'row' => [
        'age'        => ':age years old',
        'last_done'  => 'Last record: :date',
        'never_done' => 'No record in this follow-up',
        'capped'     => 'Due date limited to the validated maximum for the condition',
        'condition'  => 'Condition: :name',
        'eye'        => [
            'right' => 'Right eye',
            'left'  => 'Left eye',
            'both'  => 'Both eyes',
        ],
        'contact_never' => 'No contact yet',
        'contact_count' => ':count contact|:count contacts',
        'contact_last'  => ':outcome · :date',
        'scheduled_for' => 'Scheduled for :date',
        'snoozed_until' => 'Snoozed until :date',
        'dismissed'     => 'Dismissed: :reason',
        'closed'        => 'Closed: :reason',
        'needs_info'    => 'The follow-up start date is missing. The doctor fills it in on the patient record.',
        'suspended'     => 'EasyEye suspended the rule for this follow-up. No actions while the suspension lasts.',
        'block_warning' => 'Do not call/message: :label',
        'whatsapp'      => 'Has WhatsApp',
        'invite'        => [
            'queued'        => 'WhatsApp invitation queued',
            'sent'          => 'WhatsApp invitation sent on :date',
            'delivered'     => 'Invitation delivered on :date',
            'read'          => 'Invitation read on :date',
            'failed'        => 'The WhatsApp invitation was not delivered',
            'cancelled'     => 'Invitation cancelled before it was sent',
            'origin_auto'   => 'automatic',
            'origin_manual' => 'sent by the team',
            'requested'     => 'Asked to schedule',
            'asked_book'    => 'Asked to schedule on :date',
            'asked_done'    => 'Said they already scheduled on :date — check the schedule',
            'no_consent'    => 'No WhatsApp authorisation — phone call only',
            'opted_out'     => 'Replied STOP — phone call only',
        ],
    ],

    'actions' => [
        'contact'        => 'Contact',
        'schedule'       => 'Schedule',
        'snooze'         => 'Snooze',
        'dismiss'        => 'Dismiss',
        'done_elsewhere' => 'Done elsewhere',
        'reopen'         => 'Reopen',
        'more'           => 'More actions',
        'invite'         => 'Send invitation',
        'patient'        => 'View patient record',
    ],

    'modal' => [
        'cancel' => 'Cancel',
        'close'  => 'Close',
        'for'    => 'Patient: :name',

        'contact' => [
            'title'     => 'Record contact',
            'outcome'   => 'How did the contact go?',
            'note'      => 'Note (optional)',
            'note_hint' => 'Up to :max characters. Do not write diagnoses or health data here.',
            'submit'    => 'Record contact',
        ],
        'snooze' => [
            'title'      => 'Snooze the follow-up',
            'until'      => 'Snooze until',
            'until_hint' => 'From tomorrow up to :days days. The follow-up returns to the list on that date by itself.',
            'reason'     => 'Reason (optional)',
            'submit'     => 'Snooze',
        ],
        'dismiss' => [
            'title'       => 'Dismiss this follow-up',
            'warning'     => 'The follow-up leaves the list until the patient comes back to the clinic. Use it when the patient does not need to, or will not, come back.',
            'reason'      => 'Reason',
            'reason_hint' => 'Required. Up to :max characters.',
            'submit'      => 'Dismiss',
        ],
        'done_elsewhere' => [
            'title'     => 'Done at another provider',
            'text'      => 'The patient already did this at another clinic. The date is used to calculate the next due date.',
            'date'      => 'Date it was done',
            'date_hint' => 'It cannot be in the future.',
            'submit'    => 'Record',
        ],
        'reopen' => [
            'title'  => 'Reopen the follow-up',
            'text'   => 'The follow-up returns to the "To contact" list.',
            'submit' => 'Reopen',
        ],
        'invite' => [
            'title'         => 'Invite by WhatsApp',
            'submit'        => 'Send invitation',
            'loading'       => 'Checking whether the invitation can be sent…',
            'load_error'    => 'Could not check the invitation right now. Close and try again.',
            'to'            => 'To: :phone',
            'covers'        => 'The invitation covers :count follow-up of this patient.|The invitation covers :count follow-ups of this patient.',
            'message_title' => 'Message the patient will receive',
            'buttons'       => 'Message buttons',
            'neutral'       => 'The text does not state the reason for the follow-up or the exam.',
            'daily'         => 'Today: :used of :cap clinic invitations.',
            'daily_open'    => 'Today: :used clinic invitations.',
            'last'          => 'Last invitation: :date.',
            'next'          => 'A new invitation only from :date.',
            'blocked'       => 'This invitation cannot be sent now:',
            'blocked_hint'  => 'Record the phone contact with the "Contact" action.',
            'sent_note'     => 'The patient can answer with the buttons; the answer appears in this list.',
        ],
    ],

    'empty' => [
        'to_contact_title' => 'All up to date',
        'to_contact'       => 'No patient needs to be contacted right now.',
        'filtered_title'   => 'Nothing found',
        'filtered'         => 'No follow-ups match these filters.',
        'none_title'       => 'No follow-ups yet',
        'none'             => 'Follow-ups appear when the doctor requests a return during a visit or when a follow-up protocol is activated for the clinic.',
        'doctor_order_off' => 'The follow-up requested by the doctor is turned off in this clinic\'s settings.',
        'other_views'      => 'Also check the other views above.',
    ],

    'suspended_banner'  => 'These follow-ups are on hold because EasyEye suspended the follow-up rule. They come back by themselves when the rule is resumed.',
    'needs_info_banner' => 'These patients need a follow-up start date for the return to be calculated.',

    'loading' => 'Updating…',

    'done' => [
        'contacted'      => 'Contact recorded.',
        'snoozed'        => 'Follow-up snoozed.',
        'dismissed'      => 'Follow-up dismissed.',
        'reopened'       => 'Follow-up reopened.',
        'done_elsewhere' => 'Recorded as done at another provider.',
        'invited'        => 'Invitation sent to the WhatsApp queue.',
    ],

    'errors' => [
        'not_found'       => 'This follow-up no longer exists or does not belong to this clinic.',
        'not_workable'    => 'This follow-up changed: someone else already handled it. The list was refreshed.',
        'rule_suspended'  => 'The rule for this follow-up is suspended by EasyEye.',
        'not_reopenable'  => 'Only snoozed or dismissed follow-ups can be reopened.',
        'invalid_date'    => 'The date is not valid for this action.',
        'reason_required' => 'Please give the reason.',
        'link_mismatch'   => 'This appointment does not belong to the same patient as this follow-up.',
        'not_for_course'  => 'A treatment injection cannot be marked as done elsewhere: the doctor records the injection and decides the new interval.',
    ],
];
