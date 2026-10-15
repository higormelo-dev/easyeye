<?php

/*
 * Suggestion for the record's "Return" field (Phase 6): the interval the clinic chose for the follow-up visit of each
 * ACTIVE chronic condition of the patient, in days. Doctors only; nothing is filled in automatically.
 */

return [
    'title'   => 'Suggestion from the follow-up protocols',
    'line'    => ':title (:eye): visit every :interval :unit',
    'days'    => 'about :days days',
    'use'     => 'Use :days days',
    'in_use'  => 'Current value',
    'cap'     => 'A return above :days days counts as :days days in the returns list (the protocol\'s validated maximum).',
    'cap_hit' => 'The value is above :days days: in the returns list it counts as :days days (the protocol\'s validated maximum).',
];
