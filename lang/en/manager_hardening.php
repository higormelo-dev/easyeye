<?php

declare(strict_types=1);

return [
    'reason_required' => 'A reason is required for this action.',
    'reason_min'      => 'The reason must be at least :min characters.',
    'reason_max'      => 'The reason cannot exceed :max characters.',

    'modal_title'        => 'Confirm action',
    'modal_warning'      => 'This action is logged in the audit trail and cannot be silently undone.',
    'modal_reason_label' => 'Reason (LGPD/CFM)',
    'modal_reason_hint'  => 'Describe why this action is being performed. Minimum 20 characters. Will be recorded in the audit trail.',
    'modal_reason_placeholder' => 'E.g., Customer request via ticket #1234 — cancellation due to non-payment after 3 retry attempts.',
    'modal_cancel'       => 'Cancel',
    'modal_confirm'      => 'Confirm',
    'modal_counter'      => ':current / :min minimum',

    'two_factor_required' => 'Two-factor authentication is required for SaaS administrators.',
    'two_factor_required_by_entity' => 'Company ":entity" requires two-factor authentication for all users.',
    'two_factor_invalid'  => 'Invalid or expired verification code.',

    'entity_2fa_section'        => 'Two-factor authentication',
    'entity_2fa_label'          => 'Require 2FA for all users of this company',
    'entity_2fa_hint'           => 'When enabled, all users (including you) will need to set up and use an authenticator app (Google Authenticator, Authy, 1Password etc.) to access the system.',
    'entity_2fa_warning'        => 'If you enable this and have not yet set up 2FA, you will be redirected to configure it before continuing.',
    'entity_2fa_enabled_at'     => 'Enabled at :date by :user',
    'entity_2fa_btn_enable'     => 'Enable required 2FA',
    'entity_2fa_btn_disable'    => 'Disable required 2FA',
    'entity_2fa_enabled'        => 'Two-factor authentication enabled for all users of this company.',
    'entity_2fa_disabled'       => '2FA requirement disabled. Users may optionally use 2FA.',
    'entity_2fa_reason_enable'  => 'Justify why you are ENABLING required 2FA (LGPD audit).',
    'entity_2fa_reason_disable' => 'Justify why you are DISABLING required 2FA (LGPD audit).',
];
