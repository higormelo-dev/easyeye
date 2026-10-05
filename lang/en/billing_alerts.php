<?php

// Billing alerts for the SaaS team (manager). Subscription data only.
return [
    'greeting' => 'Hello, :name!',

    'recurrence_lost' => [
        'subject' => '[Alert] :gateway deactivated the recurring billing of :entity',
        'line'    => ':gateway deactivated on its own the recurring billing of the :entity subscription (:plan plan). Billing moved to renewal by the system: the next invoice is issued by EasyEye and paid under My subscription.',
        'access'  => 'The clinic was not blocked: access continues until the end of the paid period (:until).',
        'next'    => 'Next due date: :next. Without payment, the regular dunning applies (reminder, overdue, limited access and termination).',
        'action'  => 'Open Subscriptions in the manager',
        'hint'    => 'Check the reason for the deactivation in the gateway dashboard (e.g., card declined several times, subscription removed).',
    ],
];
