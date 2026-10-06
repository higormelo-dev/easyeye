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

    // Operational gateway alerts (GatewayAlertService).
    'gateway' => [
        'action' => 'Open Gateways in the manager',

        'credential_rejected' => [
            'subject' => '[Alert] :gateway refused the API key (HTTP :status)',
            'line'    => ':gateway answered HTTP :status to an EasyEye call: the API key was refused. Detail: :detail',
            'hint'    => 'Common causes: key expired or disabled for lack of use, sandbox key in production (or vice versa), revoked key or the server IP outside the whitelist. Charges and checkout fail until the key is replaced in Manager → Gateways.',
        ],
        'environment_mismatch' => [
            'subject' => '[Alert] :gateway key from the wrong environment',
            'line'    => 'The configured :gateway key belongs to a different environment than the API URL (sandbox × production). Detail: :detail',
            'hint'    => 'Production uses a $aact_prod_ key with https://api.asaas.com; sandbox uses $aact_hmlg_ with https://api-sandbox.asaas.com.',
        ],
        'health_failed' => [
            'subject' => '[Alert] :gateway health check failed (:status)',
            'line'    => 'The daily :gateway check failed (:status). Detail: :detail',
            'hint'    => 'Check the configuration (URL and key) in Manager → Gateways and in the .env.',
        ],
        'access_token' => [
            'subject' => '[Alert] :gateway API key: :event',
            'line'    => ':gateway warned about the API key ":name": :event. Reason: :reason. Expires: :expires.',
            'hint'    => 'Unused keys are disabled after 3 months and expire after 6 (the daily health check keeps the key in use). Re-enable or replace the key in the gateway dashboard and in Manager → Gateways.',
        ],
        'recurrence_alignment' => [
            'subject' => '[Alert] Adjust the due date of recurrence :subscription on :gateway',
            'line'    => 'The card subscription of :entity (:subscription) started with the next due date on :from, but the paid period runs until :to. EasyEye could not adjust it through the API.',
            'hint'    => 'Set the subscription next due date to :to in the gateway dashboard (for cards, changing it through the API requires tokenization enabled on the account).',
        ],
        'recurrence_diverged' => [
            'subject' => '[Alert] Recurrence :subscription changed on :gateway',
            'line'    => 'The recurrence of :entity (:subscription) was changed directly on :gateway and differs from the EasyEye subscription (:fields).',
            'hint'    => 'What counts is what was contracted in EasyEye: undo the change in the gateway dashboard or adjust the subscription in the manager.',
        ],
        'refund_credits' => [
            'subject' => '[Alert] Partial refund of AI pack :reference without enough credits',
            'line'    => 'The partial refund of AI credit pack :reference of :entity required removing credits the clinic had already used: :revoked removed, :shortfall could not be removed.',
            'hint'    => 'Check with the clinic and adjust the credit wallet in the manager if needed.',
        ],
        'checkout_terms_changed' => [
            'subject' => '[Alert] :entity card checkout paid with the terms before the plan change',
            'line'    => 'The :entity card checkout was paid after the plan change, with the old terms (:amount, :cycle cycle). The card recurrence it created (:subscription) was undone and the current recurrence (new terms) was kept.',
            'hint'    => 'The invoice payment counts normally. Check the payment method of the next invoices with the clinic.',
        ],
        'card_reregister' => [
            'subject' => '[Notice] :entity recurrence recreated without the card on plan change',
            'line'    => 'The :entity plan change recreated the recurrence on :gateway (:subscription), but without the card: the API does not create a card subscription without the card data.',
            'hint'    => 'The clinic was told in My subscription to pay the next invoice by card (which moves the recurrence back to the card). Until then, invoices go out with boleto/Pix/card through the invoice.',
        ],
        'dunning_check_failed' => [
            'subject' => '[Alert] Dunning on hold: could not check the :entity payment on :gateway',
            'line'    => 'The :step dunning step for :entity was postponed :count times because :gateway did not answer conclusively (:detail). Dunning never limits or ends without checking.',
            'hint'    => 'Check the payment on the gateway dashboard and the key/connection in Manager → Gateways. Paid: record it; not paid: dunning resumes once the check works again.',
        ],
        'refund_unconfirmed' => [
            'subject' => '[Alert] :amount refund still requested without confirmation on :gateway',
            'line'    => 'The R$ :amount refund (charge :payment) has been requested for too long and could not be checked through the :gateway API.',
            'hint'    => 'Check on the gateway dashboard whether the refund was made and use "Check" in the subscription detail.',
        ],
    ],
];
