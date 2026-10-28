<?php

/*
 * WhatsApp follow-up invitation (Phase 6, ADR 0002 item 9): origin and response labels, and the reasons an
 * invitation is not sent (InviteBlocker). The message text itself is in `whatsapp.php` (templates.care_recall).
 */

return [
    'origin' => [
        'auto'   => 'Automatic',
        'manual' => 'Sent by the team',
    ],

    'response' => [
        'wants_to_schedule' => 'Asked to schedule',
        'already_scheduled' => 'Said they already scheduled',
    ],

    'blockers' => [
        'paused_global'       => 'Invitations are paused by EasyEye.',
        'feature_off'         => 'The clinic plan does not include patient follow-up: no invitation is sent.',
        'clinic_not_enabled'  => 'EasyEye has not yet enabled WhatsApp invitations for this clinic.',
        'no_sender'           => 'The clinic WhatsApp is not active (EasyEye number or its own).',
        'access_blocked'      => 'The clinic access is blocked (subscription): no automatic message is sent.',
        'clinic_switch_off'   => 'WhatsApp invitation is turned off in the follow-up settings.',
        'patient_unavailable' => 'The patient is inactive or was removed.',
        'contact_blocked'     => 'The patient is marked as "do not contact".',
        'no_consent'          => 'The patient did not authorise follow-up reminders by WhatsApp.',
        'no_whatsapp'         => 'The patient mobile phone is not marked as WhatsApp.',
        'invalid_phone'       => 'The patient mobile phone is not valid for WhatsApp.',
        'opted_out'           => 'The patient replied STOP and does not want messages from this number.',
        'nothing_to_invite'   => 'There is no follow-up to invite: it was scheduled, snoozed, dismissed, is not yet in the invitation window or the rule is not in use.',
        'outside_window'      => 'Outside the invitation time window.',
        'too_soon'            => 'The patient received an invitation recently.',
        'daily_cap'           => 'The clinic daily invitation limit was reached.',
    ],

    'refused' => 'The invitation could not be sent. :reasons',

    'errors' => [
        'not_found'     => 'This follow-up no longer exists or is not from this clinic.',
        'blocked'       => 'The invitation could not be sent.',
        'already_today' => 'This patient already received an invitation today.',
    ],
];
