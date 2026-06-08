<?php

declare(strict_types=1);

return [
    // Page
    'title'      => 'Payment Gateways',
    'subtitle'   => 'Manage the gateways used by <strong>EasyEye</strong> to charge subscriptions and control which gateways each clinic can use to receive patient payments.',
    'breadcrumb' => 'Payment Gateways',

    // Default gateway banner
    'default_banner_title'    => 'System Default Gateway',
    'default_banner_subtitle' => '— used to bill all subscription plans',
    'default_banner_change'   => 'Change',
    'no_default_title'        => 'No default gateway defined.',
    'no_default_subtitle'     => 'Subscription billing will fail until a default gateway is configured.',
    'no_default_action'       => 'Set now',

    // Context cards
    'ctx_saas_title'   => 'SaaS Billing — charge clinics',
    'ctx_saas_desc'    => 'Credentials used by EasyEye to charge monthly fees. Configure via <strong>Credentials</strong> and set the default above.',
    'ctx_saas_badge'   => 'Global credentials in database',
    'ctx_tenant_title' => 'Tenant Payment — clinics receive from patients',
    'ctx_tenant_desc'  => 'Each clinic configures its own credentials. Enable access via <strong>Clinic Access</strong>.',
    'ctx_tenant_badge' => 'Isolated credentials per clinic',

    // Gateway card
    'default_badge'       => 'Default',
    'status_active'       => 'Active',
    'status_inactive'     => 'Inactive',
    'toggle_deactivate'   => 'Deactivate',
    'toggle_activate'     => 'Activate',
    'priority_label'      => 'Priority:',
    'priority_change'     => 'change',
    'billing_credentials' => 'Billing credentials',
    'credentials_active'  => '{1} 1 active|[2,*] :count active',
    'credentials_none'    => 'No credential',
    'clinics_with_access' => 'Clinics with access',
    'clinics_none'        => 'None',
    'clinics_count'       => '{1} 1 clinic|[2,*] :count clinics',

    // Capabilities
    'cap_subscriptions' => 'Subscriptions',
    'cap_one_time'      => 'One-time charges',
    'cap_refunds'       => 'Refunds',
    'cap_webhooks'      => 'Webhooks',

    // Footer buttons
    'btn_credentials'       => 'Credentials',
    'btn_entity_access'     => 'Clinic Access',
    'btn_set_default'       => 'Set as Default',
    'btn_current_default'   => 'Current Default Gateway',
    'btn_activate_first'    => 'Activate the gateway first',
    'btn_add_credential'    => 'Add a credential first',
    'btn_set_default_title' => 'Set as system default gateway',

    // Modal: Change default
    'modal_default_title'       => 'System Default Gateway',
    'modal_default_alert'       => 'The default gateway is used for <strong>all subscription charges</strong> when no specific gateway preference is defined. Only <strong>active gateways with an active credential</strong> can be the default.',
    'modal_default_current'     => 'Current',
    'modal_default_btn'         => 'Set',
    'modal_default_unavailable' => 'Unavailable',
    'modal_default_close'       => 'Close',

    // Modal: Credentials
    'modal_cred_title'       => 'Billing Credentials',
    'modal_cred_alert'       => 'These credentials are used by <strong>EasyEye</strong> to charge clinics for subscriptions. The key is <strong>never displayed</strong> after saving. When a new one is added, the previous is automatically deactivated.',
    'modal_cred_history'     => 'Credential history',
    'modal_cred_loading'     => '',
    'modal_cred_empty'       => 'No credentials added yet.',
    'modal_cred_new'         => 'New credential',
    'modal_cred_label'       => 'Label',
    'modal_cred_label_ph'    => 'e.g. Production — Apr/2026 rotation',
    'modal_cred_api_key'     => 'API Key',
    'modal_cred_api_key_ph'  => 'Paste the key here',
    'modal_cred_webhook'     => 'Webhook Secret',
    'modal_cred_webhook_opt' => '(optional)',
    'modal_cred_valid_from'  => 'Valid from',
    'modal_cred_valid_to'    => 'Valid until',
    'modal_cred_save'        => 'Save credential',
    'modal_cred_close'       => 'Close',
    'modal_cred_active'      => 'Active',
    'modal_cred_inactive'    => 'Inactive',
    'modal_cred_hidden'      => 'key hidden',
    'modal_cred_revoke'      => 'Revoke',

    // Modal: Entity access
    'modal_ea_title'     => 'Clinic Access',
    'modal_ea_alert'     => 'Enable this gateway for clinics that will configure their own credentials and receive patient payments. The clinic will only see the gateway in their panel after being enabled here.',
    'modal_ea_search_ph' => 'Filter by clinic name or code…',
    'modal_ea_loading'   => 'Loading clinics…',
    'modal_ea_empty'     => 'No clinics found.',
    'modal_ea_close'     => 'Close',
    'modal_ea_enable'    => 'Enable',
    'modal_ea_disable'   => 'Disable',

    // Modal: Priority
    'modal_priority_title'  => 'Fallback Priority',
    'modal_priority_desc'   => 'Lower value = higher priority in automatic fallback. Does not affect the default gateway (set explicitly above).',
    'modal_priority_save'   => 'Save',
    'modal_priority_cancel' => 'Cancel',

    // JS confirm messages
    'js_confirm_set_default'       => 'Set ":name" as the system default gateway for subscription billing?',
    'js_confirm_set_default_modal' => 'Set ":name" as default gateway?',
    'js_confirm_revoke'            => 'Revoke this credential? This action cannot be undone.',

    // JS error fallbacks
    'js_error_set_default'  => 'Error setting default gateway.',
    'js_error_generic'      => 'Error.',
    'js_error_save'         => 'Error saving.',
    'js_error_load'         => 'Error loading.',
    'js_error_load_clinics' => 'Error loading clinics.',

    // JS credential list labels (passed from view to JS)
    'js_no_label' => 'No label',

    // Secret field labels per gateway (used in JS gatewaySecretLabels)
    'secret_label' => [
        'asaas'       => ['label' => 'API Key (access_token)', 'hint' => 'Asaas account access key (starts with $aact_…)'],
        'infinitepay' => ['label' => 'Bearer Token', 'hint' => 'InfinitePay OAuth token'],
        'mercadopago' => ['label' => 'Access Token', 'hint' => 'Starts with APP_USR-…'],
        'pagarme'     => ['label' => 'Secret Key', 'hint' => 'Pagar.me account secret key'],
        'stripe_br'   => ['label' => 'Secret Key', 'hint' => 'Starts with sk_live_… or sk_test_…'],
        'pagbank'     => ['label' => 'Access Token', 'hint' => 'PagBank Bearer Token'],
    ],

    // Controller messages
    'set_default_success'    => ':name set as system default gateway.',
    'gateway_activated'      => 'Gateway activated.',
    'gateway_deactivated'    => 'Gateway deactivated.',
    'priority_updated'       => 'Priority updated.',
    'credential_saved'       => 'Credential saved successfully. The previous credential was deactivated.',
    'credential_revoked'     => 'Credential revoked.',
    'saas_entity_forbidden'  => 'The SaaS entity cannot be configured here.',
    'gateway_enabled_for'    => ':gateway enabled for :entity.',
    'gateway_disabled_for'   => ':gateway disabled for :entity.',
    'error_inactive_gateway' => 'The gateway must be active to be set as default.',
    'error_no_credential'    => 'The gateway must have at least one active credential.',
];
