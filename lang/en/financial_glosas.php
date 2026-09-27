<?php

declare(strict_types=1);

/**
 * Financial › Denial reconciliation screen (panel/financial/tiss/glosas) and
 * TissGlosaStatus/TissAppealStatus enum labels (label() reads from here).
 */
return [
    'title'                => 'Denial Reconciliation',
    'subtitle'             => 'Deadlines, appeals and recovered amounts of health-plan denials.',
    'breadcrumb_financial' => 'Financial',
    'total_label'          => 'Pending:',

    // Header
    'btn_import_return' => 'Import TISS return',

    // Filters
    'filters_label'           => 'Denial filters',
    'search_placeholder'      => 'Search claim no., code or reason',
    'search_clear'            => 'Clear search',
    'filter_status'           => 'Status',
    'filter_status_all'       => 'All statuses',
    'filter_operator'         => 'Health plan/operator',
    'filter_operator_all'     => 'All health plans',
    'filter_clear'            => 'Clear filters',
    'status_recovered_option' => 'Recovered (full or partial)',
    'period_any'              => 'Pending from any date',
    'filtering'               => 'Filtering...',
    'period_hint'             => 'The period (date the denial was identified) applies to the Resolved and All tabs and to "Recovered".',

    // Tabs
    'tabs_label'   => 'Denial status',
    'tab_pending'  => 'Pending',
    'tab_resolved' => 'Resolved',
    'tab_all'      => 'All',

    // Cards (queue cards ignore the period; all follow the filtered health plan)
    'kpis_label'         => 'Denial indicators',
    'total_glosa'        => 'Total denied',
    'glosa_count'        => ':count denial(s)',
    'open_amount'        => 'Open',
    'open_hint'          => 'Open denials (no appeal yet), from any date. Click to filter the queue.',
    'appealed'           => 'Appealed',
    'appealed_hint'      => 'Denials with an appeal opened or awaiting the operator response, from any date. Click to filter the queue.',
    'overdue_title'      => 'Overdue',
    'overdue_hint'       => 'Open denials whose appeal deadline has passed. Click to filter the queue.',
    'recovered'          => 'Recovered',
    'recovered_hint'     => 'Sum of the amounts accepted on appeals of denials identified in the period. Click to see the recovered ones.',
    'recovered_of_total' => 'of :total denied in the period',
    'due_soon_title'     => 'Due in :days days',
    'due_soon_hint'      => 'Open denials whose appeal deadline is today or within the next :days days. Click to filter the queue.',

    // By health plan
    'by_covenant'  => 'Summary by health plan',
    'col_covenant' => 'Health plan',
    'col_count'    => 'Denials',
    'col_total'    => 'Total denied',
    'col_open'     => 'Open',

    // List
    'list_title_pending'  => 'Pending denials queue',
    'list_title_resolved' => 'Denials resolved in the period',
    'list_title_all'      => 'Denials in the period',
    'empty'               => 'No denials found in the selected period.',
    'empty_pending'       => 'No pending denials.',
    'empty_filtered'      => 'No denials match these filters.',
    'empty_hint'          => 'Denials come from an imported TISS return or when a claim is denied in Billing.',
    'col_date'            => 'Identified on',
    'col_guide'           => 'Claim',
    'col_reason'          => 'Reason',
    'col_deadline'        => 'Deadline',
    'col_status'          => 'Status',
    'col_appeal'          => 'Appeal',
    'col_value'           => 'Amount',
    'col_actions'         => 'Actions',
    'no_covenant'         => 'No health plan',
    'claim_code_label'    => 'Billing :code',
    'no_guide'            => 'No claim',

    // List pagination (TablePagination)
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'denials',
    'pagination_label'    => 'Denials pagination',
    'pagination_previous' => 'Previous page',
    'pagination_next'     => 'Next page',
    'pagination_status'   => 'Page :page of :pages',

    // Deadline (badge with icon + text, not color only)
    'deadline_overdue_days' => 'Overdue by :days days',
    'deadline_overdue_one'  => 'Was due yesterday',
    'deadline_overdue'      => 'Overdue',
    'deadline_today'        => 'Due today',
    'deadline_tomorrow'     => 'Due tomorrow',
    'deadline_in_days'      => 'Due in :days days',
    'deadline_none'         => 'No deadline',

    // Appeal in the row
    'appeal_response_until' => 'Response by :date',
    'appeals_previous'      => '+:count previous appeal(s)',
    'no_appeal'             => 'No appeal',

    // Actions
    'appeal_btn'         => 'Appeal',
    'submit_appeal_btn'  => 'Mark as sent',
    'resolve_appeal_btn' => 'Record decision',
    'details_btn'        => 'Details',
    'details_label'      => 'See details and history of denial :code',
    'more_actions'       => 'More actions for denial :code',
    'processing'         => 'Processing...',
    'close'              => 'Close',
    'cancel_btn'         => 'Cancel',
    'action_error'       => 'The action could not be completed. Please try again.',

    // Details panel
    'detail_title'             => 'Denial :code',
    'detail_loading'           => 'Loading the denial details...',
    'detail_missing'           => 'Denial not found or you have no access to it.',
    'detail_summary'           => 'Summary',
    'detail_status'            => 'Status',
    'detail_identified'        => 'Identified on',
    'detail_deadline'          => 'Appeal deadline',
    'detail_amount'            => 'Denied amount',
    'detail_recovered'         => 'Recovered',
    'detail_resolved_at'       => 'Resolved on',
    'detail_resolution_notes'  => 'Decision notes',
    'detail_guide'             => 'Claim',
    'guide_provider_number'    => 'Claim no. (provider)',
    'guide_operator_number'    => 'Operator no.',
    'guide_claim_code'         => 'Billing claim',
    'guide_attendance'         => 'Appointment',
    'guide_patient'            => 'Patient',
    'guide_total'              => 'Claim amount',
    'detail_reason'            => 'Denial reason',
    'detail_appeals'           => 'Appeals',
    'no_appeals'               => 'No appeal opened for this denial.',
    'appeal_opened_at'         => 'Opened on',
    'appeal_submitted_at'      => 'Sent on',
    'appeal_response_deadline' => 'Response by',
    'appeal_requested'         => 'Requested',
    'appeal_accepted'          => 'Accepted',
    'appeal_reason'            => 'Justification',
    'appeal_result_notes'      => 'Operator decision',
    'detail_timeline'          => 'Timeline',
    'timeline_identified'      => 'Denial identified',
    'timeline_glosa'           => 'Denial: :status',
    'timeline_glosa_change'    => 'Denial: :from → :to',
    'timeline_appeal'          => 'Appeal :number: :status',
    'timeline_appeal_change'   => 'Appeal :number: :from → :to',

    // Context in the modals
    'modal_glosa_label'    => 'Denial reason',
    'modal_value_label'    => 'Denied amount',
    'modal_guide_label'    => 'Claim',
    'modal_covenant_label' => 'Health plan',
    'modal_deadline_label' => 'Appeal deadline',
    'modal_appeal_label'   => 'Appeal',
    'modal_requested'      => 'Requested amount',

    // Modal: open appeal
    'appeal_title'              => 'Denial Appeal',
    'justification_label'       => 'Appeal justification',
    'justification_placeholder' => 'Describe why this denial is improper and the documents that support the appeal...',
    'min_chars_audit_hint'      => 'At least :min characters. It will be recorded in the audit log.',
    'char_counter'              => ':count/:max',
    'appeal_number_hint'        => 'An appeal number will be generated automatically (format REC-YYYYMM-NNNNN).',
    'submit_appeal'             => 'Open appeal',

    // Modal: mark as sent
    'submit_confirm_title'       => 'Mark appeal as sent',
    'submit_confirm_intro'       => 'Confirm that the appeal below has already been sent to the health plan (portal, e-mail or paper protocol).',
    'submit_confirm_consequence' => 'The system does not send the appeal electronically: this action only records it as sent today. The health plan response deadline (:days days) starts counting and the action cannot be undone.',
    'submit_confirm_btn'         => 'Confirm sending',

    // Modal: decision
    'resolve_title'         => 'Appeal Decision',
    'decision_label'        => 'Health plan decision',
    'decision_accepted'     => 'Accepted (full or partial)',
    'decision_rejected'     => 'Rejected',
    'accepted_amount_label' => 'Amount accepted by the health plan',
    'accepted_amount_help'  => 'Greater than zero and up to :max (denied amount).',
    'result_notes_label'    => 'Decision notes',
    'resolve_submit_btn'    => 'Confirm decision',
    'resolve_preview'       => 'The denial will become: :status',

    // Server messages
    'reason_required'           => 'Enter the appeal justification.',
    'reason_min'                => 'The justification must have at least :min characters.',
    'reason_max'                => 'The justification may have at most :max characters.',
    'decision_required'         => 'Select the health plan decision.',
    'accepted_amount_required'  => 'Enter the amount accepted by the health plan.',
    'accepted_amount_numeric'   => 'Enter a valid accepted amount.',
    'accepted_amount_decimals'  => 'The accepted amount may have at most 2 decimal places (cents).',
    'accepted_amount_min'       => 'The accepted amount must be greater than zero.',
    'accepted_amount_max'       => 'The accepted amount cannot exceed the denied amount (:max).',
    'appeal_success'            => 'Appeal :number opened successfully.',
    'cannot_appeal'             => 'This denial cannot be appealed in its current state.',
    'appeal_number_unavailable' => 'Could not generate the appeal number right now. Nothing was saved — please try again in a moment.',
    'appeal_submitted'          => 'Appeal :number marked as sent to the health plan.',
    'cannot_submit_appeal'      => 'This appeal cannot be sent in its current state.',
    'appeal_resolved'           => 'Appeal :number resolved successfully.',
    'cannot_resolve_appeal'     => 'This appeal cannot be resolved in its current state.',

    // Enums (App\Domains\Tiss\Enums)
    'glosa_status' => [
        'open'             => 'Open',
        'appealed'         => 'Appealed',
        'partial_reversed' => 'Partially reversed',
        'reversed'         => 'Reversed',
        'maintained'       => 'Upheld',
        'cancelled'        => 'Cancelled',
    ],
    'appeal_status' => [
        'opened'      => 'Opened',
        'submitted'   => 'Submitted',
        'in_analysis' => 'Under review',
        'accepted'    => 'Accepted',
        'rejected'    => 'Rejected',
        'cancelled'   => 'Cancelled',
    ],
];
