<?php

declare(strict_types=1);

return [
    // Setup
    'setup_title'      => 'Configurar autenticação em dois fatores',
    'setup_intro'      => 'Use um app autenticador (Google Authenticator, Authy, 1Password, Microsoft Authenticator) para escanear o QR code abaixo.',
    'setup_step_1'     => '1. Escaneie o QR code com seu app autenticador.',
    'setup_step_2'     => '2. Digite o código de 6 dígitos que o app gerar.',
    'manual_secret'    => 'Não consegue escanear? Use este código manualmente:',
    'code_label'       => 'Código de 6 dígitos',
    'code_placeholder' => '000000',
    'btn_confirm'      => 'Confirmar e ativar',
    'btn_regenerate'   => 'Gerar novo QR code',
    'btn_cancel'       => 'Cancelar',
    'enabled'          => 'Autenticação em dois fatores ativada com sucesso.',
    'verified'         => 'Código verificado.',

    // Recovery codes (após confirm)
    'recovery_title'   => 'Códigos de recuperação',
    'recovery_intro'   => 'Guarde estes códigos em local seguro (gerenciador de senhas, cofre, papel). Cada código pode ser usado UMA vez se você perder acesso ao app autenticador.',
    'recovery_warning' => 'Esta é a única vez que estes códigos serão exibidos. Se você perdê-los e perder o app, perderá acesso à sua conta.',
    'btn_copy'         => 'Copiar todos',
    'btn_download'     => 'Baixar como arquivo',
    'btn_done'         => 'Já guardei. Continuar',

    // Verify (login flow)
    'verify_title'        => 'Verificação em dois fatores',
    'verify_intro'        => 'Abra seu app autenticador e digite o código atual. Se você não tem mais acesso ao app, use um código de recuperação.',
    'verify_use_recovery' => 'Usar código de recuperação',
    'verify_use_totp'     => 'Usar código do app',
    'recovery_code_label' => 'Código de recuperação (formato XXXX-XXXX)',
    'btn_verify'          => 'Verificar',
];
