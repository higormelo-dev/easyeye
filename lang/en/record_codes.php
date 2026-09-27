<?php

/*
 * Sequential record codes (SDL-, PAC-, EIQ-) and identifiers received by the
 * integrators API.
 */
return [
    'ambiguous_identifier' => [
        'schedule'  => 'The given identifier matches more than one appointment. Send the UUID or the full code (SDL-XXXXXXXXXX).',
        'patient'   => 'The given identifier matches more than one patient. Send the UUID or the full code (PAC-XXXXXXXXXX).',
        'equipment' => 'The given identifier matches more than one equipment of this integrator. Send the equipment UUID.',
    ],

    'unexpected_unique_conflict' => 'The appointment could not be saved due to an unexpected data conflict. Import the row again; if the error persists, contact support.',
];
