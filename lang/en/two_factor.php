<?php

declare(strict_types=1);

return [
    'setup_title'        => 'Set up two-factor authentication',
    'setup_intro'        => 'Use an authenticator app (Google Authenticator, Authy, 1Password, Microsoft Authenticator) to scan the QR code below.',
    'setup_step_1'       => '1. Scan the QR code with your authenticator app.',
    'setup_step_2'       => '2. Enter the 6-digit code your app generates.',
    'manual_secret'      => 'Can\'t scan? Enter this code manually:',
    'code_label'         => '6-digit code',
    'code_placeholder'   => '000000',
    'btn_confirm'        => 'Confirm and enable',
    'btn_regenerate'     => 'Generate new QR code',
    'btn_cancel'         => 'Cancel',
    'enabled'            => 'Two-factor authentication enabled.',
    'verified'           => 'Code verified.',

    'recovery_title'     => 'Recovery codes',
    'recovery_intro'     => 'Save these codes in a safe place (password manager, vault, paper). Each code can be used ONCE if you lose access to your authenticator app.',
    'recovery_warning'   => 'This is the only time these codes will be shown. If you lose them and lose access to the app, you will lose access to your account.',
    'btn_copy'           => 'Copy all',
    'btn_download'       => 'Download as file',
    'btn_done'           => 'I saved them. Continue',

    'verify_title'       => 'Two-factor verification',
    'verify_intro'       => 'Open your authenticator app and enter the current code. If you no longer have access, use a recovery code.',
    'verify_use_recovery' => 'Use recovery code',
    'verify_use_totp'     => 'Use app code',
    'recovery_code_label' => 'Recovery code (format XXXX-XXXX)',
    'btn_verify'          => 'Verify',
];
