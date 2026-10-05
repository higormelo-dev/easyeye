<?php

// Charge sent by the manager to the clinic (e-mail + WhatsApp). Only plan,
// amount and due date — never patient data. The link opens the invoice under
// My subscription, inside the system.
return [
    'greeting'   => 'Hello, :name!',
    'salutation' => 'The :app team',
    'subject'    => ':app subscription charge: :amount',
    'line'       => 'There is an open charge for the :entity subscription (:plan plan) of :amount, due on :date.',
    // Prorated difference of the plan change (upgrade).
    'line_plan_change' => 'The change of the :entity subscription to the :plan plan was requested. The plan changes as soon as the difference of :amount (due on :date) is paid.',
    'how'              => 'Payment is made inside the panel, under My subscription, by Pix, bank slip or card.',
    'pay'              => 'Pay in the panel',
    'paid_note'        => 'If you have already paid, please disregard this notice.',

    'whatsapp'             => '*:app*: there is a charge for the :entity subscription (:plan plan) of :amount, due :date. Pay in the panel: :url',
    'whatsapp_plan_change' => '*:app*: the change of :entity to the :plan plan was requested. Pay the difference of :amount (due :date) in the panel: :url',
];
