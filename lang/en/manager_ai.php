<?php

return [
    // Page
    'title'      => 'AI Providers',
    'subtitle'   => 'Choose which providers the system may use and their priority order. Available modes (Economy, Validated, Consensus) scale with the number of active providers.',
    'breadcrumb' => 'AI Providers',

    // Columns / labels
    'provider'           => 'Provider',
    'model'              => 'Model',
    'enabled'            => 'Active',
    'priority'           => 'Priority',
    'configured'         => 'Configured',
    'not_configured'     => 'No credential',
    'move_up'            => 'Increase priority',
    'move_down'          => 'Decrease priority',
    'no_credential_hint' => 'Set the API key in the environment (.env) before enabling.',

    // Roles derived from order
    'role_generator'   => 'Generator',
    'role_reviewer'    => 'Reviewer',
    'role_adjudicator' => 'Adjudicator',

    // Modes
    'available_modes' => 'Available modes',
    'mode_economy'    => 'Economy',
    'mode_validated'  => 'Validated',
    'mode_consensus'  => 'Consensus',
    'mode_needs'      => 'needs :n provider(s)',

    // Actions / messages
    'save'               => 'Save',
    'saved'              => 'AI providers updated.',
    'audit_reason'       => 'Updated enabled AI providers (admin area).',
    'error_empty'        => 'Enable at least one AI provider.',
    'error_unconfigured' => 'Cannot enable provider(s) without credentials: :providers.',
];
