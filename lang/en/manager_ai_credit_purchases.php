<?php

return [
    'title'    => 'AI credit purchases',
    'subtitle' => 'Manage AI credit purchase requests from clinics — approve, cancel, mark failed or refund.',

    'kpi' => [
        'pending'          => 'Pending',
        'pending_help'     => 'Awaiting manual approval',
        'credited_30d'     => 'Credited (30 days)',
        'credited_30d_help' => 'Revenue realized in period',
        'credits_sold'     => 'credits sold',
        'conversion'       => 'Conversion rate (30d)',
        'conversion_help'  => 'Requests that turned into revenue',
        'abandonment'      => 'Cancelled/Failed (30d)',
        'top_consumers'    => 'Top 5 clinics (30 days)',
        'no_consumers'     => 'No consumption in period.',
    ],

    'filters' => [
        'status'    => 'Status',
        'entity'    => 'Clinic',
        'date_from' => 'From',
        'date_to'   => 'To',
        'q'         => 'Search by clinic name…',
        'clear'     => 'Clear filters',
        'all'       => 'All',
    ],

    'columns' => [
        'created_at'  => 'Requested at',
        'entity'      => 'Clinic',
        'package'     => 'Package',
        'credits'     => 'Credits',
        'amount'      => 'Amount',
        'requested_by' => 'Requester',
        'status'      => 'Status',
        'actions'     => 'Actions',
    ],

    'actions' => [
        'view'        => 'Details',
        'credit'      => 'Approve and credit',
        'cancel'      => 'Cancel request',
        'fail'        => 'Mark as gateway failure',
        'refund'      => 'Refund (reverse credits)',
        'credited'    => 'Credits approved and added to clinic wallet.',
        'cancelled'   => 'Request cancelled.',
        'marked_failed' => 'Request marked as payment failure.',
        'refunded'    => 'Credits reversed from clinic wallet.',
    ],

    'confirm' => [
        'credit_title'  => 'Approve and credit?',
        'credit_body'   => 'The :credits credits will be added to the clinic wallet immediately. This action is logged in the audit trail.',
        'cancel_title'  => 'Cancel pending request?',
        'cancel_body'   => 'The clinic will not be able to pay this request afterwards. Provide a reason for the audit trail.',
        'fail_title'    => 'Mark as payment failure?',
        'fail_body'     => 'Use when the gateway declined the charge (card denied, etc.). Different from cancelled for funnel metrics.',
        'refund_title'  => 'Refund and reverse credits?',
        'refund_body'   => 'The :credits credits will be DEBITED from the clinic wallet. If they already consumed part of it, the balance may go NEGATIVE — you will need to charge the debt or absorb it manually. This action is destructive and permanently recorded in the audit log.',
        'reason'        => 'Reason (required, logged in audit trail)',
        'reason_min'    => 'Describe the reason in at least 5 characters.',
    ],

    'detail' => [
        'tab_info'         => 'Information',
        'tab_timeline'     => 'Timeline',
        'tab_metadata'     => 'Metadata',
        'package_code'     => 'Package code',
        'idempotency_key'  => 'Idempotency key',
        'subscription'     => 'Linked subscription',
        'wallet_balance'   => 'Current wallet balance',
    ],

    'timeline' => [
        'created'    => 'Request created',
        'credited'   => 'Credited',
        'cancelled'  => 'Cancelled',
        'failed'     => 'Gateway failure',
        'refunded'   => 'Refunded',
    ],

    'errors' => [
        'ai_credit_purchase_not_creditable'    => 'Request is not in pending state — cannot be credited.',
        'ai_credit_purchase_not_cancellable'   => 'Request is not in pending state — cannot be cancelled.',
        'ai_credit_purchase_not_failable'      => 'Request is not in pending state — cannot be marked as failed.',
        'ai_credit_purchase_not_refundable'    => 'Request is not credited — nothing to refund.',
        'forbidden_role'                       => 'Your SaaS role does not have permission for this action.',
    ],

    'empty' => 'No AI credit purchase requests found for the applied filters.',
];
