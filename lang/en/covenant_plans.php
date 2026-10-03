<?php

declare(strict_types=1);

// Health plans (products registered at ANS) — shared by the manager, the
// clinic settings and the patient registration.
return [
    'plan'               => 'Plan',
    'plans'              => 'Plans',
    'name_with_code'     => ':name (ANS reg. :code)',
    'name_with_old_code' => ':name (ANS code :code)',

    // Product status at ANS
    'status_active'      => 'Active',
    'status_suspended'   => 'Sales suspended',
    'status_cancelled'   => 'Cancelled at ANS',
    'status_transferred' => 'Transferred to another operator',
    'status_inactive'    => 'Inactive',

    // Plan validity (ANS VIGENCIA_PLANO)
    'regulation_A' => 'Before Law 9,656/98 (not regulated)',
    'regulation_P' => 'Regulated (Law 9,656/98)',

    // Fields
    'field_name'              => 'Plan name',
    'field_ans_code'          => 'ANS product registry',
    'field_ans_code_hint'     => 'Number printed on the member card (optional).',
    'field_covenant'          => 'Insurer',
    'field_contracting'       => 'Contract type',
    'field_segmentation'      => 'Segmentation',
    'field_coverage_area'     => 'Coverage area',
    'field_accommodation'     => 'Accommodation',
    'field_moderating_factor' => 'Co-payment / deductible',
    'field_regulation'        => 'Validity',
    'field_status'            => 'ANS status',
    'field_status_at'         => 'Status since',
    'field_registered_at'     => 'Registered at ANS on',
    'field_active'            => 'Available for selection',

    'saved'           => 'Plan saved.',
    'deleted'         => 'Plan deleted.',
    'name_taken'      => 'A plan with this name already exists for this insurer.',
    'ans_readonly'    => 'ANS plans are updated by the sync and cannot be changed.',
    'in_use'          => 'This plan is used in patient records. Deactivate it instead of deleting.',
    'covenant_locked' => 'The plan insurer cannot be changed. Register a new plan under the other insurer.',
    'settings_title'  => 'Insurer plans',
    'settings_tab'    => 'Plans',
    'invalid'         => 'Plan not found.',
    'wrong_covenant'  => 'This plan does not belong to the selected insurer.',
    'unavailable'     => 'This plan is no longer available (cancelled at ANS or deactivated). Choose another one.',
    'particular'      => 'Self-pay (Particular) has no plans.',
];
