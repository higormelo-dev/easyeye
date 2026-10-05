<?php

// Billing command output for the SaaS team (billing:dunning and
// billing:reconcile-legacy). Only subscription and company data — never
// patient data.
return [
    'trial_notices' => [
        'disabled'     => 'Trial ending notices are disabled (BILLING_TRIAL_NOTICES_ENABLED=false).',
        'done'         => 'Trial ending notices sent: :count',
        'dry_run_done' => 'Dry run: :count trial ending notice(s) would be sent. Nothing was saved or sent.',
    ],

    'dunning' => [
        'disabled'                    => 'Dunning is disabled (BILLING_DUNNING_ENABLED=false): no notices, terminations or gateway cancellations.',
        'done'                        => 'Steps completed: :count',
        'dry_run_done'                => 'Dry run: :count step(s) would be completed. Nothing was saved, sent or cancelled.',
        'col_subscription'            => 'Subscription',
        'col_entity'                  => 'Company',
        'col_step'                    => 'Step',
        'col_due_on'                  => 'Due date',
        'col_days_overdue'            => 'Days overdue',
        'col_action'                  => 'What it would do',
        'action_notify'               => 'Notify :count recipient(s)',
        'action_terminate'            => 'Terminate the subscription',
        'action_terminate_and_cancel' => 'Terminate the subscription and cancel the recurrence on :gateway',
    ],

    'reconcile' => [
        'nothing'         => 'No subscriptions awaiting reconciliation.',
        'dry_run'         => 'Dry run: nothing was saved. Check the table and run again with --apply to save.',
        'applied'         => 'Reconciled: :count. "Manual review" rows stay flagged (the current one with access granted) until reviewed.',
        'not_flagged'     => 'Subscription :id is not awaiting reconciliation.',
        'reason_required' => 'Provide the reason with --reason (at least 10 characters).',

        // Report notes: how dunning treats what was reconciled.
        'note_past_due'   => 'Past due: dunning counts from the reconciliation (D0 = the --apply day), not from the real due date: payment-not-found notice, limited access on D+3 and termination on D+7 (with the gateway recurrence cancelled). The panel notice shows right away; the e-mails go out with dunning enabled (BILLING_DUNNING_ENABLED=true) — enable it on the same day as --apply. The real due date is in the "Unpaid due date" column and in the history (original_due_date).',
        'note_never_paid' => 'Never-paid signup with a past-due charge: the deadline restarts as for a signup made on the --apply day ("Next due date" column). Until the end of that day access continues, with the payment notice in the panel; without payment, access ends and the signup is terminated on D+7.',
        'note_superseded' => 'Rows with "current: …" are not the company\'s current subscription: they never get dunning nor grant access. With the recurrence active on the gateway, check on the gateway which recurrence the customer actually pays BEFORE cancelling any: if it is the old one, create the new subscription from it in the manager; if it is a duplicate, use --close=ID --action=cancel.',
        'current_is'      => 'current: :id',

        // Closing the manual review (--close=ID --action=...).
        'action_required'     => 'Choose how to close the review with --action: "cancel" (cancels the subscription and stops the recurrence on the gateway) or "paid-until" with --until=YYYY-MM-DD (active, paid until the end of that day, then follows the new flow). Without it, nothing is changed.',
        'until_required'      => 'With --action=paid-until, provide --until=YYYY-MM-DD: the last day already paid (today or later).',
        'close_gateway_error' => 'Could not query the gateway for row :id: nothing was changed. Run it again.',
        'until_past'          => 'The --until date (:date) is in the past: the clinic would be blocked right away. Provide today or a future date.',
        'not_current'         => 'Subscription :id is not the company\'s current one (the current one is :current): it will not become valid again. Use --action=cancel.',
        'orphan_found'        => 'There is a recurrence on the gateway created with the reference of subscription :id (:ids). Cancel it in the gateway dashboard (or reconcile it manually) before closing the review.',
        'orphan_check_failed' => 'Could not check on the gateway whether a recurrence was created for subscription :id (:error). Run again.',
        'closed_cancel'       => 'Review closed: :id cancelled; the recurrence cancellation on the gateway was requested.',
        'closed_paid_until'   => 'Review closed: :id active, paid until :date; the new rules apply from now on.',

        'col_subscription' => 'Subscription',
        'col_entity'       => 'Company',
        'col_gateway'      => 'Gateway',
        'col_status'       => 'Current status',
        'col_outcome'      => 'Result',
        'col_reason'       => 'Reason',
        'col_terms'        => 'Cycle · amount',
        'col_next_due'     => 'Next due date',
        'col_unpaid_due'   => 'Unpaid due date',
        'col_days_overdue' => 'Days overdue',
        'col_last_payment' => 'Last payment',

        'outcome' => [
            'active'           => 'Active (up to date)',
            'past_due'         => 'Past due (dunning from today)',
            'awaiting_payment' => 'Awaiting 1st payment',
            'manual'           => 'Manual review',
            'error'            => 'Lookup error (run again)',
        ],

        'reason' => [
            'in_good_standing'           => 'Active recurrence, up to date',
            'reactivated'                => 'Expired by the old job; active recurrence, up to date',
            'overdue'                    => 'Charge past due without payment',
            'overdue_never_paid'         => 'Never-paid signup with a past-due charge; new deadline from today',
            'awaiting_first_payment'     => 'Signup not paid yet; 1st charge not due yet',
            'never_issued'               => 'Charge never issued on the gateway',
            'no_query_api'               => 'Gateway without recurrence lookup in the integration',
            'no_recurrence'              => 'No recurrence on the gateway (local renewal)',
            'recurrence_not_found'       => 'Recurrence does not exist on the gateway',
            'recurrence_inactive'        => 'Recurrence inactive or removed on the gateway',
            'unsupported_cycle'          => 'Recurrence cycle not sold by the product',
            'overdue_with_later_payment' => 'Past-due charge, but a later charge was paid',
            'no_next_due'                => 'Recurrence without a next due date',
            'gateway_error'              => 'Gateway lookup failed',
            'duplicated_recurrence'      => 'Duplicated recurrence: active on the gateway, but the company already has another current subscription',
            'orphan_recurrence'          => 'Orphan recurrence on the gateway (found by reference)',
        ],
    ],
];
