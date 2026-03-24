<?php

declare(strict_types=1);

return [

    // ── Titles and headers ────────────────────────────────────────────────────
    'title'              => 'Waiting Room',
    'title_admin'        => 'Waiting Room — Administration',
    'updated_at'         => 'Updated at :time',
    'refresh'            => 'Refresh',

    // ── Filters ───────────────────────────────────────────────────────────────
    'filter_date'        => 'Date',
    'filter_doctor'      => 'Doctor',
    'filter_doctor_all'  => 'All doctors',
    'filter_status'      => 'Status',
    'filter_status_all'  => 'All statuses',

    // ── KPIs ─────────────────────────────────────────────────────────────────
    'kpi_total'          => 'Total',
    'kpi_waiting'        => 'Waiting',
    'kpi_in_progress'    => 'In progress',
    'kpi_done'           => 'Completed',
    'kpi_absent'         => 'No-shows / Cancelled',

    // ── Table columns ─────────────────────────────────────────────────────────
    'col_time'           => 'Time',
    'col_patient'        => 'Patient',
    'col_doctor'         => 'Doctor',
    'col_type'           => 'Type',
    'col_status'         => 'Status',
    'col_wait'           => 'Wait',
    'col_actions'        => 'Actions',

    // ── Operational actions (admin) ───────────────────────────────────────────
    'action_checkin'     => 'Checked in',
    'action_confirm'     => 'Confirm',
    'action_start'       => 'Start appointment',
    'action_noshow'      => 'Mark no-show',
    'action_cancel'      => 'Cancel',
    'action_reschedule'  => 'Reschedule',
    'action_view_card'   => 'View record',

    // ── Doctor actions ────────────────────────────────────────────────────────
    'action_call'        => 'Call in',
    'action_finish'      => 'Finish',

    // ── "View record" offcanvas ───────────────────────────────────────────────
    'card_title'         => 'Patient Record',
    'card_code'          => 'Code',
    'card_schedule_code' => 'Appointment',
    'card_birthdate'     => 'Date of birth',
    'card_age'           => 'Age',
    'card_covenant'      => 'Insurance',
    'card_visit_type'    => 'Visit type',
    'card_doctor'        => 'Doctor',
    'card_phone'         => 'Phone',
    'card_notes'         => 'Notes',
    'card_arrived_at'    => 'Arrived at',
    'card_view_patient'  => 'View full record',
    'card_no_patient'    => 'No linked patient record',

    // ── Modals / confirmations ────────────────────────────────────────────────
    'cancel_reason'          => 'Cancellation reason (optional)',
    'cancel_confirm'         => 'Cancel appointment',
    'cancel_confirm_message' => 'Are you sure you want to cancel this appointment?',
    'noshow_confirm'         => 'Mark as no-show',
    'noshow_confirm_message' => 'Mark this patient as no-show?',

    // ── Empty states ──────────────────────────────────────────────────────────
    'empty_queue'        => 'No patients waiting at the moment.',
    'empty_day'          => 'No appointments found for this day.',
    'empty_filtered'     => 'No results for the selected filters.',

    // ── Statuses ──────────────────────────────────────────────────────────────
    'status' => [
        'scheduled'   => 'Scheduled',
        'confirmed'   => 'Confirmed',
        'waiting'     => 'Waiting',
        'dilating'    => 'Dilating',
        'exam'        => 'In exam',
        'in_progress' => 'In progress',
        'attended'    => 'Attended',
        'noshow'      => 'No-show',
        'cancelled'   => 'Cancelled',
    ],

];
