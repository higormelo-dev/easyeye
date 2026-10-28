<?php

/*
 * Patient recall vocabulary (Phase 6): due-date states, condition status, follow-up lifecycle and reasons.
 * The Returns screen texts go in the following groups (`page`, `settings`...).
 */

return [
    'states' => [
        'upcoming' => 'On track',
        'due_soon' => 'Due soon',
        'due'      => 'Due',
        'overdue'  => 'Overdue',
        'lost'     => 'Lost to follow-up',
    ],

    'condition_status' => [
        'suggested'  => 'Suggested',
        'active'     => 'Active',
        'discharged' => 'Discharged',
        'rejected'   => 'Dismissed',
    ],

    'condition_origin' => [
        'cid'        => 'Diagnosis (ICD)',
        'medication' => 'Medication',
        'manual'     => 'Manual entry',
    ],

    'lifecycle' => [
        'open'       => 'Open',
        'snoozed'    => 'Snoozed',
        'dismissed'  => 'Dismissed',
        'closed'     => 'Closed',
        'needs_info' => 'Needs information',
    ],

    'closed_reason' => [
        'completed'             => 'Completed',
        'condition_ended'       => 'Condition ended',
        'protocol_deactivated'  => 'Protocol deactivated',
        'task_disabled'         => 'Task turned off',
        'doctor_order_ended'    => 'No follow-up requested',
        'doctor_order_disabled' => 'Doctor-requested follow-up turned off',
        'done_elsewhere'        => 'Done at another provider',
        'patient_removed'       => 'Patient removed',
        'course_ended'          => 'Treatment ended or paused',
    ],

    'task' => [
        'doctor_order' => 'Follow-up requested by the doctor',
        'course'       => 'Next treatment injection',
        'consultation' => 'Follow-up visit',
        'other'        => 'Follow-up',
        'exam'         => 'Exam: :exam',
    ],

    'contact_outcome' => [
        'reached'       => 'Spoke with the patient',
        'will_schedule' => 'Will schedule',
        'no_answer'     => 'No answer',
        'wrong_number'  => 'Wrong or disconnected number',
        'declined'      => 'Does not want to come back',
    ],

    'block_reason' => [
        'deceased'       => 'Deceased',
        'do_not_contact' => 'Asked not to be contacted',
        'moved'          => 'Moved away',
        'no_valid_phone' => 'No valid phone',
        'other'          => 'Other reason',
    ],

    'events' => [
        'calculated'       => 'Follow-up calculated',
        'cycle_completed'  => 'Patient came back: new cycle',
        'dates_changed'    => 'Dates recalculated',
        'closed'           => 'Closed',
        'reopened'         => 'Reopened',
        'suspended'        => 'Rule suspended by EasyEye',
        'resumed'          => 'Rule resumed',
        'contacted'        => 'Contact recorded',
        'snoozed'          => 'Snoozed',
        'dismissed'        => 'Dismissed',
        'done_elsewhere'   => 'Done at another provider',
        'scheduled'        => 'Scheduled',
        'invite_queued'    => 'WhatsApp invitation queued',
        'invite_responded' => 'Patient answered the invitation',
    ],
];
