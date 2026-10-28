<?php

declare(strict_types=1);

/**
 * Labels for the surgical module enums (App\Enums\Surgery\*). Key =
 * snake_case enum name → case value.
 */
return [
    'surgical_case_status' => [
        'draft'                  => 'Draft',
        'awaiting_authorization' => 'Awaiting authorization',
        'authorized'             => 'Authorized',
        'partially_authorized'   => 'Partially authorized',
        'denied'                 => 'Denied',
        'scheduled'              => 'Scheduled',
        'performed'              => 'Performed',
        'discharged'             => 'Discharged',
        'cancelled'              => 'Cancelled',
        'suspended'              => 'Suspended',
    ],
    'surgery_eye' => [
        'od' => 'Right eye (OD)',
        'oe' => 'Left eye (OS)',
        'ao' => 'Both eyes (OU)',
    ],
    'surgery_eye_short' => [
        'od' => 'OD',
        'oe' => 'OS',
        'ao' => 'OU',
    ],
    'anesthesia_type' => [
        'topical'            => 'Topical',
        'topical_sedation'   => 'Topical with sedation',
        'peribulbar'         => 'Peribulbar',
        'retrobulbar'        => 'Retrobulbar',
        'sub_tenon'          => 'Sub-Tenon',
        'local_infiltration' => 'Local infiltration',
        'general'            => 'General',
    ],
    'surgery_regime' => [
        'ambulatory'   => 'Outpatient',
        'day_hospital' => 'Day hospital',
        'inpatient'    => 'Inpatient',
    ],
    'surgery_urgency' => [
        'elective' => 'Elective',
        'urgent'   => 'Urgent',
    ],
    'surgery_facility_type' => [
        'own_clinic'      => 'Own clinic',
        'hospital'        => 'Hospital',
        'surgical_center' => 'Surgical center',
    ],
    'authorization_status' => [
        'not_requested'        => 'Not requested',
        'pending'              => 'Awaiting answer',
        'authorized'           => 'Authorized',
        'partially_authorized' => 'Partially authorized',
        'denied'               => 'Denied',
        'expired'              => 'Expired',
        'waived'               => 'Waived',
    ],
    'authorization_event_type' => [
        'requested'            => 'Request sent',
        'submitted'            => 'Sent to the insurer',
        'authorized'           => 'Authorized',
        'partially_authorized' => 'Partially authorized',
        'denied'               => 'Denied',
        'appealed'             => 'Appeal sent',
        'expired'              => 'Authorization expired',
        'waived'               => 'Authorization waived',
        'cancelled'            => 'Request withdrawn',
        'note'                 => 'Note',
    ],
    'surgery_team_role' => [
        'surgeon'           => 'Surgeon',
        'assistant'         => 'Assistant',
        'anesthesiologist'  => 'Anesthesiologist',
        'scrub_nurse'       => 'Scrub nurse',
        'circulating_nurse' => 'Circulating nurse',
        'technician'        => 'Technician',
    ],
    'surgery_document_type' => [
        'consent'          => 'Informed consent',
        'pre_op_guidance'  => 'Pre-operative instructions',
        'implant_record'   => 'Implant record',
        'post_op_guidance' => 'Post-operative instructions',
        'discharge'        => 'Surgical discharge',
    ],
];
