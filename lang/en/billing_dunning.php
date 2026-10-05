<?php

// Dunning — e-mails to the clinic (billing:dunning command). Subscription
// data only (company, amount, dates, payment link) — never patient data.
return [
    'greeting'            => 'Hello, :name!',
    'salutation'          => 'The :app team',
    'pay_now'             => 'Pay now',
    'contact'             => 'Talk to our team',
    'manage_subscription' => 'Open My subscription',
    // Reminder before the due date.
    'paid_note' => 'If you have already paid, there is nothing else to do: confirmation can take up to 3 business days.',
    // Overdue, limited access and first charge overdue: the restriction does
    // not wait for the confirmation (which can take business days), but is
    // lifted automatically once it arrives.
    'paid_note_overdue' => 'If you have already paid, confirmation can take up to 3 business days and, until it arrives, the restrictions described above still apply. As soon as the payment is confirmed, access is restored automatically.',
    // Notice without the link to that due date's charge: no "Pay now" button.
    'no_link' => 'The payment link for this charge is not available in this e-mail. To pay, please talk to our team.',

    'reminder' => [
        'subject' => 'Your :app subscription is due on :date',
        'line'    => 'The next subscription charge for :entity, in the amount of :amount, is due on :date.',
        // Charge for the due date not issued yet (or no link in the gateway response).
        'no_link'      => 'The payment link for this charge is not available yet. To pay by the due date, please talk to our team.',
        'card'         => 'We will charge :amount to the :brand card ending in :last4 on :date, automatically — no action needed.',
        'card_in_full' => 'Note: the card renewal is charged in full (installments apply only to the initial purchase).',
        'card_change'  => 'To change the card or pay another way before the due date, open My subscription in the panel.',
    ],

    'overdue' => [
        'subject'  => 'We could not find the payment for your :app subscription',
        'line'     => 'We could not find the payment of :amount for the :entity subscription, which was due on :date.',
        'deadline' => 'On :limited_date, AI and the financial module will be blocked; on :blocked_date, access to the panel will be suspended.',
        'retry'    => 'You can pay now using the link below.',
    ],

    'limited' => [
        'subject'  => 'Limited access: :app subscription payment overdue',
        'line'     => 'The payment of :amount for the :entity subscription, due on :date, is still open. Because of that, AI and the financial module are blocked; schedule, patients and medical records remain available.',
        'deadline' => 'Without payment, access to the panel will be suspended on :blocked_date and the subscription will be terminated.',
    ],

    'terminated' => [
        'subject' => ':app subscription terminated for non-payment',
        'line'    => 'The :entity subscription was terminated because the payment of :amount, due on :date, was not received.',
        'next'    => 'To use :app again, please subscribe again with our team.',
    ],

    'first_charge_overdue' => [
        'subject' => 'The first :app subscription charge is overdue',
        'line'    => 'The first subscription charge for :entity, in the amount of :amount, was due on :date with no payment received, and access to the panel has been suspended.',
        'retry'   => 'Access is restored as soon as the payment is confirmed. Without payment, the subscription will be cancelled on :terminate_date.',
    ],

    'first_charge_terminated' => [
        'subject' => ':app subscription cancelled for non-payment',
        'line'    => 'The :entity subscription was cancelled because the first charge of :amount, due on :date, was not paid.',
        'next'    => 'To subscribe again, please talk to our team.',
    ],

    // Termination: what actually happened to the charges (DunningService::stopCharges).
    'charges' => [
        'recurrence_cancelled'  => 'The recurring charge was cancelled with the payment provider: no new charges will be issued. If you still have an open bank slip/Pix for this subscription, do not pay it — paying it does not reactivate the subscription.',
        'recurrence_cancelling' => 'We asked the payment provider to cancel the recurring charge, and the cancellation is in progress. If a new charge for this subscription arrives, do not pay it — paying it does not reactivate the subscription.',
        'open_charge'           => 'The bank slip/Pix already issued cannot be cancelled automatically: do not pay it, because paying it does not reactivate the subscription.',
        'open_charge_cancelled' => 'The bank slip/Pix already issued was cancelled with the payment provider, and no new charges will be issued.',
        'none'                  => 'No new charges will be issued.',
    ],

    // WhatsApp (SaaS global instance): the essentials of the e-mail, short,
    // with the link to pay inside the system (My subscription).
    'whatsapp' => [
        'reminder'                => '*:app*: the :entity subscription (:amount) is due on :date. Pay in the panel, under My subscription: :url',
        'reminder_card'           => '*:app*: the :entity subscription (:amount) will be charged to the card ending in :last4 on :date. To change the card or pay another way: :url',
        'overdue'                 => '*:app*: we have not identified the payment of :amount for the :entity subscription (due :date). On :limited_date, AI and financial will be blocked. Pay in the panel: :url',
        'limited'                 => '*:app*: the payment of :amount for the :entity subscription is still open and AI and financial are blocked. On :blocked_date, access to the panel will be suspended. Pay in the panel: :url',
        'terminated'              => '*:app*: the :entity subscription was ended for non-payment. To use it again, subscribe under My subscription: :url',
        'first_charge_overdue'    => '*:app*: the 1st charge of the :entity subscription (:amount) was due on :date and access was suspended. It comes back as soon as the payment is confirmed. Pay in the panel: :url',
        'first_charge_terminated' => '*:app*: the :entity subscription was cancelled because the 1st charge was not paid. To subscribe again: :url',
    ],
];
