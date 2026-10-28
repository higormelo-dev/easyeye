<?php

declare(strict_types=1);

return [
    // Page
    'title'      => 'EasyEye Payment Gateways',
    'subtitle'   => 'Gateways of the company that owns <strong>EasyEye</strong>, used to charge clinics: plan subscriptions and AI credit packs.',
    'breadcrumb' => 'Payment Gateways',

    // Default gateway banner
    'default_banner_title'    => 'System Default Gateway',
    'default_banner_subtitle' => '— used to charge clinics for subscriptions and AI credit packs',
    'default_banner_change'   => 'Change',
    'no_default_title'        => 'No default gateway defined.',
    'no_default_subtitle'     => 'Subscription billing will fail until a default gateway is configured.',
    'no_default_action'       => 'Set now',

    // Context card
    'ctx_saas_title' => 'What these gateways are for',
    'ctx_saas_desc'  => 'The credentials belong to the company that owns EasyEye: the system uses them to charge clinics for <strong>subscriptions</strong> and <strong>AI credit packs</strong>, and the money goes to the EasyEye account. Add them under <strong>Credentials</strong> and set the default above.',
    'ctx_saas_badge' => 'EasyEye global credentials',

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

    // Capabilities

    // What the gateway does in EasyEye today (gateway class capability methods)
    'caps_title'                 => 'In EasyEye today',
    'caps_transparent'           => 'Without leaving EasyEye:',
    'caps_method'                => ['pix' => 'Pix', 'boleto' => 'Boleto', 'credit_card' => 'Card'],
    'caps_card_public_key_title' => 'Transparent card only with the public key saved in the credential (optional for PagBank).',
    'caps_link_only'             => 'Gateway link only',
    'caps_link_only_title'       => "The clinic pays on the gateway's page (Pix and card there).",
    'caps_card_link'             => 'Card via link',
    'caps_card_link_title'       => "A charge without a set method opens the gateway's page, which accepts cards.",
    'caps_hosted_card'           => 'Card in the secure environment',
    'caps_hosted_card_title'     => "The card is entered on the gateway's hosted page (Asaas Checkout) and the clinic comes back to EasyEye; the plan invoice becomes a card subscription.",
    'caps_refund'                => 'Full refund by the system',
    'caps_refund_title'          => 'The manager refunds paid payments through the gateway (Subscriptions → detail → invoices).',
    'caps_refund_partial'        => 'Full and partial refund',
    'caps_refund_partial_title'  => 'The manager refunds paid payments through the gateway, full or partial (Subscriptions → detail → invoices).',
    'health_title'               => 'API connection',
    'health_checked_at'          => 'checked on :date',
    'health_status'              => [
        'ok'                   => 'Working',
        'auth_error'           => 'Key refused',
        'environment_mismatch' => 'Key from another environment',
        'not_configured'       => 'Not configured',
        'rate_limited'         => 'API rate limit',
        'unreachable'          => 'No response',
        'config_only'          => 'Configured',
    ],
    'caps_installments'            => 'Up to :countx on card',
    'caps_installments_one'        => 'Card: single payment only',
    'caps_installments_title'      => 'Gateway limit; checkout uses the lowest of this, the maximum set in Plans and the cycle months.',
    'caps_saved_card'              => 'Renews on saved card',
    'caps_saved_card_title'        => 'Renewal charges the card stored at the gateway, without the clinic typing it again.',
    'caps_card_replacement'        => 'Card replacement',
    'caps_card_replacement_title'  => 'The clinic replaces the renewal card without being charged (requires the public key).',
    'caps_native_recurrence'       => 'Gateway recurrence',
    'caps_native_recurrence_title' => 'The gateway itself issues the charge for each subscription cycle.',
    'caps_local_renewal'           => 'Renewal by EasyEye',
    'caps_local_renewal_title'     => 'EasyEye issues the charge for each renewal on this gateway.',

    // Empty state
    'empty_state' => 'No gateway registered.',

    // Footer buttons
    'btn_credentials'       => 'Credentials',
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
    'modal_cred_alert'       => 'These credentials are used by <strong>EasyEye</strong> to charge clinics for subscriptions and AI credit packs. The key is <strong>never displayed</strong> after saving. When a new one is added, the previous is automatically deactivated.',
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

    // Public key for the in-app checkout (card SDK in the browser)
    'modal_cred_public_key'         => 'Public key (in-browser checkout)',
    'modal_cred_public_key_ph'      => 'pk_… / APP_USR-… / public key',
    'modal_cred_public_key_hint'    => 'Used by the secure card form (Mercado Pago, Stripe, Pagar.me; optional for PagBank). It is public by definition — never paste the secret key (sk_/rk_) here.',
    'modal_cred_public_key_current' => 'Public key: :key',
    'js_error_public_key_secret'    => 'This looks like the secret key (sk_/rk_). Enter the public key.',

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
    'js_error_set_default' => 'Error setting default gateway.',
    'js_error_generic'     => 'Error.',
    'js_error_save'        => 'Error saving.',
    'js_error_load'        => 'Error loading.',

    // JS credential list labels (passed from view to JS)
    'js_no_label' => 'No label',

    // Secret field labels per gateway (used in JS gatewaySecretLabels)
    'secret_label' => [
        'asaas'       => ['label' => 'API Key (access_token)', 'hint' => 'Asaas account access key (starts with $aact_…)'],
        'infinitepay' => ['label' => 'Token (optional)', 'hint' => 'The InfinitePay Integrated Checkout does not use a token — leave it empty.'],
        'mercadopago' => ['label' => 'Access Token', 'hint' => 'Starts with APP_USR-…'],
        'pagarme'     => ['label' => 'Secret Key', 'hint' => 'Pagar.me account secret key'],
        'stripe_br'   => ['label' => 'Secret Key', 'hint' => 'Starts with sk_live_… or sk_test_…'],
        'pagbank'     => ['label' => 'Access Token', 'hint' => 'PagBank Bearer Token'],
    ],

    // InfinitePay: the credential is the InfiniteTag (handle)
    'modal_cred_handle'      => 'InfiniteTag (handle)',
    'modal_cred_handle_ph'   => 'e.g. mystore',
    'modal_cred_handle_hint' => 'Your InfiniteTag, without the "$" — the same as in the InfinitePay app. The webhook has no secret: the payment is confirmed with InfinitePay itself.',
    'handle_required'        => 'Enter the InfinitePay account InfiniteTag (handle).',
    'handle_invalid'         => 'Invalid InfiniteTag: use only letters, numbers, dot, hyphen or underscore (no spaces).',
    'public_key_invalid'     => 'Invalid public key format. Expected: :format.',
    'public_key_secret'      => 'This looks like the secret key (or equals the token). Enter the JS SDK PUBLIC key — it is sent to the browser.',
    'public_key_unsupported' => 'This gateway does not use a public key (no transparent card checkout).',
    'public_key_formats'     => [
        'mercadopago' => 'Public key "APP_USR-" or "TEST-" followed by a UUID',
        'pagarme'     => 'public key "pk_…" or "pk_test_…"',
        'stripe_br'   => 'publishable key "pk_live_…" or "pk_test_…"',
        'pagbank'     => 'RSA public key (PEM or the "MII…" text)',
    ],
    'js_error_handle_required' => 'Enter the InfiniteTag (handle).',

    // Webhook secret hint per gateway
    'webhook_hint' => [
        'asaas'       => 'Authentication token set on the Asaas webhook (32 to 255 characters), sent in the asaas-access-token header.',
        'infinitepay' => 'Not used: InfinitePay does not sign the webhook.',
        'mercadopago' => 'Webhooks notification secret signature (Your integrations → Webhooks).',
        'pagarme'     => 'Webhook authentication user and password as user:password.',
        'stripe_br'   => 'Webhook endpoint signing secret (starts with whsec_).',
        'pagbank'     => 'PagBank account token (the same as the API key) — validates x-authenticity-token.',
    ],

    // Controller messages
    'set_default_success'    => ':name set as system default gateway.',
    'gateway_activated'      => 'Gateway activated.',
    'gateway_deactivated'    => 'Gateway deactivated.',
    'priority_updated'       => 'Priority updated.',
    'credential_saved'       => 'Credential saved successfully. The previous credential was deactivated.',
    'credential_revoked'     => 'Credential revoked.',
    'error_inactive_gateway' => 'The gateway must be active to be set as default.',
    'error_no_credential'    => 'The gateway must have at least one active credential.',
];
