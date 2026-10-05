<?php

// Free trial ending — notices to the clinic (billing:trial-notices command),
// by e-mail and WhatsApp. Only the clinic name and dates — never patient data.
return [
    'greeting'   => 'Hello, :name!',
    'salutation' => 'The :app team',
    'cta'        => 'Subscribe now',
    'after'      => 'When the trial ends, access to the panel is suspended until you subscribe — your data stays saved.',
    'paid_note'  => 'If you have already subscribed, please disregard this notice.',

    'three_days' => [
        'subject' => 'Your :app free trial ends in :days days',
        'line'    => 'The :entity free trial ends on :date. To keep using :app without interruption, subscribe to a plan in the panel, under My subscription.',
    ],
    'one_day' => [
        'subject' => 'Your :app free trial ends tomorrow',
        'line'    => 'The :entity free trial ends tomorrow, :date. Subscribe to a plan in the panel, under My subscription, so you do not lose access.',
    ],
    'today' => [
        'subject' => 'Your :app free trial ends today',
        'line'    => 'The :entity free trial ends today, :date. Subscribe to a plan in the panel, under My subscription, to keep using :app.',
    ],

    'whatsapp' => [
        'three_days' => '*:app*: the :entity free trial ends in :days days (:date). To continue without interruption, subscribe in the panel: :url',
        'one_day'    => '*:app*: the :entity free trial ends tomorrow (:date). Subscribe in the panel so you do not lose access: :url',
        'today'      => '*:app*: the :entity free trial ends today (:date). Subscribe in the panel to continue: :url',
    ],
];
