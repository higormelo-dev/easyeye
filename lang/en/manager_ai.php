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

    // Explicit roles (dynamic panel)
    'roles_title'                        => 'Assistant roles',
    'roles_subtitle'                     => 'Choose which provider fills each role. Changes apply IMMEDIATELY for all clients — no deploy, no file edits.',
    'role_primary'                       => 'Primary (generates the answer)',
    'role_reviewer_title'                => 'Reviewer (checks the answer)',
    'role_adjudicator_title'             => 'Adjudicator (settles/consolidates)',
    'role_primary_hint'                  => 'Required. Every AI run starts with it.',
    'role_reviewer_hint'                 => 'Optional. With a reviewer, Validated mode becomes available (2 calls).',
    'role_adjudicator_hint'              => 'Optional. Requires a reviewer. Enables Consensus mode (3 calls).',
    'role_none'                          => '— None —',
    'price_missing'                      => 'Model without registered price',
    'price_missing_hint'                 => 'Register the model price (price seeder) before use: without it the run fails after spending on the provider.',
    'test_connection'                    => 'Test connection',
    'test_ok'                            => 'Connection OK — credential valid and provider responding.',
    'test_failed'                        => 'Failed to connect to the provider.',
    'test_unconfigured'                  => 'Provider has no credential/model configured on the server.',
    'error_adjudicator_without_reviewer' => 'Cannot set an adjudicator without a reviewer.',
    'propagation_note'                   => 'Changes take effect immediately for all clients (cache invalidated instantly).',
    'error_duplicate_role'               => 'Each role needs a different provider.',
    'error_model_without_price'          => 'Model ":model" for :provider has no registered price — pick one from the list.',
    'model_env_fallback'                 => 'Server default (.env)',
    'model_hint'                         => 'Only models with a registered price are listed — guarantees correct credit billing.',
    'prices_title'       => 'Models & prices (catalog)',
    'prices_subtitle'    => 'Register the model public price here (USD per 1M tokens) and it becomes available in the model selector — no deploy.',
    'price_new'          => 'New model',
    'price_saved'        => 'Model catalog updated.',
    'price_duplicate'    => 'This model is already registered for the provider — edit the existing row.',
    'price_audit_reason' => 'AI model/price catalog change (admin area).',
    'price_input'        => 'Input (USD/1M tokens)',
    'price_output'       => 'Output (USD/1M tokens)',
    'price_reasoning'    => 'Reasoning (USD/1M, optional)',
    'price_model_name'   => 'Model name (provider official id)',
    'price_model_hint'   => 'Exactly as the provider names it, e.g. gpt-4o-mini, claude-sonnet-4-5, gemini-2.0-flash.',
    'price_active'       => 'Active (eligible in the selector)',
    'edit'               => 'Edit',
    'cancel'             => 'Cancel',
];
