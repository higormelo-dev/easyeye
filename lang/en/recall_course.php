<?php

/*
 * Intravitreal injection course (anti-VEGF) — Phase 6, ADR 0002 item 10. Texts of the patient card, the request and the
 * execution (lot, authorisation, decided interval). Plurals use "a|b" (picked by :count).
 */

return [
    'status' => [
        'active'       => 'In progress',
        'paused'       => 'Paused',
        'completed'    => 'Completed',
        'discontinued' => 'Discontinued',
        'lost'         => 'Lost to follow-up',
    ],

    'regimen' => [
        'loading_tae' => 'Loading, then treat-and-extend',
        'prn'         => 'As needed (PRN)',
        'fixed'       => 'Fixed interval',
    ],

    'application_status' => [
        'planned'   => 'Planned',
        'performed' => 'Performed',
        'cancelled' => 'Cancelled',
        'missed'    => 'Missed',
    ],

    'authorization' => [
        'not_required' => 'No authorisation',
        'pending'      => 'To request',
        'requested'    => 'Requested',
        'approved'     => 'Approved',
        'denied'       => 'Denied',
        'expired'      => 'Expired',
    ],

    'drugs' => [
        'aflibercept'  => 'Aflibercept',
        'ranibizumab'  => 'Ranibizumab',
        'bevacizumab'  => 'Bevacizumab',
        'faricimab'    => 'Faricimab',
        'brolucizumab' => 'Brolucizumab',
        'other'        => 'Other drug',
    ],

    'panel' => [
        'title'            => 'Intravitreal injection treatment',
        'eye'              => ['right' => 'RE', 'left' => 'LE'],
        'applications'     => ':count injection|:count injections',
        'interval'         => 'decided interval: :weeks weeks',
        'next'             => 'Next injection: :date',
        'in_days'          => 'in :count day|in :count days',
        'overdue_days'     => 'overdue by :count day|overdue by :count days',
        'today'            => 'today',
        'no_next'          => 'No next injection planned.',
        'has_schedule'     => 'Scheduled',
        'ended'            => 'Ended on :date. :reason',
        'last_lot'         => 'Last: lot :lot (expiry :expiry) · authorisation: :auth',
        'schedule'         => 'Schedule the next one',
        'schedule_when'    => 'Date and time of the next injection',
        'schedule_hint'    => 'It joins the open series of the treatment, with the same rule as a regular appointment (doctor, interval, block, room).',
        'schedule_confirm' => 'Schedule',
        'close'            => 'End course',
        'close_status'     => 'How does the course end?',
        'close_reason'     => 'Reason',
        'close_confirm'    => 'End course',
        'back'             => 'Back',
    ],

    'request' => [
        'title'        => 'Intravitreal injection course',
        'hint'         => 'Each eye has its own course: the system opens the one for the chosen eye (or continues the existing one) and keeps the lot, authorisation and the interval decided at each injection.',
        'eye_required' => 'Choose RE or LE: the course is for one eye only.',
        'drug'         => 'Drug',
        'drug_other'   => 'Drug name',
        'regimen'      => 'Regimen',
        'regimen_hint' => 'The regimen only guides the system: at each injection the doctor decides the interval to the next one.',
        'indication'   => 'Indication (optional)',
    ],

    'exec' => [
        'title'            => 'Intravitreal injection',
        'title_n'          => 'Intravitreal injection no. :n',
        'stock_lot'        => 'Stock lot',
        'stock_lot_none'   => 'Type the lot',
        'lot'              => 'Lot',
        'expiry'           => 'Lot expiry',
        'auth_required'    => 'The insurer requires authorisation for this injection',
        'auth_status'      => 'Authorisation status',
        'auth_number'      => 'Number',
        'auth_until'       => 'Valid until',
        'auth_override'    => 'Inject anyway (authorisation not approved)',
        'auth_notes'       => 'Justification',
        'end'              => 'End the course at this injection?',
        'end_none'         => 'No, it continues',
        'end_completed'    => 'Yes, completed',
        'end_discontinued' => 'Yes, discontinued',
        'end_reason'       => 'Reason for ending',
        'interval'         => 'Interval to the next one (weeks)',
        'interval_hint'    => 'From :min to :max weeks. The doctor decides at each visit.',
        'schedule'         => 'Schedule the next one now (optional)',
        'schedule_hint'    => 'If the time does not fit, the injection stays recorded and the next one stays planned, unscheduled.',
    ],

    'done' => [
        'closed'          => 'Course ended.',
        'scheduled'       => 'Next injection scheduled.',
        'executed'        => 'Injection recorded. Next injection planned for :date.',
        'executed_closed' => 'Injection recorded and course ended.',
        'schedule_failed' => 'The injection was recorded, but the next one could not be scheduled: :reason',
    ],

    'errors' => [
        'generic'               => 'Could not complete. Please try again.',
        'eye_required'          => 'Choose RE or LE: the course is for one eye only.',
        'drug_required'         => 'Enter the drug of the course.',
        'not_planned'           => 'This injection was already recorded or is not planned.',
        'eye_mismatch'          => 'The procedure eye is not the course eye.',
        'lot_required'          => 'Enter the lot and the expiry of the medication.',
        'lot_expired'           => 'The lot is expired: do not inject this medication.',
        'lot_not_found'         => 'Stock lot not found (or without expiry).',
        'authorization_missing' => 'The insurer authorisation is not approved: confirm that you will inject anyway and justify.',
        'interval_required'     => 'Decide the interval to the next injection (or end the course).',
        'interval_range'        => 'The interval goes from 2 to 16 weeks.',
        'end_reason_required'   => 'Enter the reason for ending the course.',
        'course_not_found'      => 'Course not found in this clinic.',
        'course_closed'         => 'This course has already ended.',
        'no_reference_schedule' => 'There is no reference appointment for the series: schedule it in the Agenda.',
        'already_scheduled'     => 'The next injection is already scheduled.',
        'other_doctor_calendar' => 'The reference appointment is in another doctor\'s calendar: schedule it in the Agenda, in your own calendar.',
    ],
];
