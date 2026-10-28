<?php

declare(strict_types=1);

return [
    // Setup
    'setup_title'      => 'Configurar autenticação em dois fatores',
    'setup_subtitle'   => 'Proteja sua conta antes de continuar',
    'setup_intro'      => 'Use um app autenticador (Google Authenticator, Authy, 1Password, Microsoft Authenticator) para escanear o QR code abaixo.',
    'setup_help'       => 'Digite o código de 6 dígitos gerado pelo app para ativar a proteção na sua conta.',
    'setup_step_1'     => '1. Escaneie o QR code com seu app autenticador.',
    'setup_step_2'     => '2. Digite o código de 6 dígitos que o app gerar.',
    'manual_secret'    => 'Não consegue escanear? Use este código manualmente:',
    'btn_copy_secret'  => 'Copiar código',
    'secret_copied'    => 'Código copiado para a área de transferência.',
    'code_label'       => 'Código de 6 dígitos',
    'code_aria_label'  => 'Código de verificação em dois fatores',
    'code_placeholder' => '000000',
    'btn_confirm'      => 'Confirmar e ativar',
    'btn_regenerate'   => 'Gerar novo QR code',
    'regenerated'      => 'Novo QR code gerado. Escaneie novamente com seu app autenticador.',
    'btn_cancel'       => 'Cancelar',
    'enabled'          => 'Autenticação em dois fatores ativada com sucesso.',
    'verified'         => 'Código verificado.',

    // Recovery codes (após confirm)
    'recovery_title'    => 'Códigos de recuperação',
    'recovery_subtitle' => 'Guarde estes códigos antes de continuar',
    'recovery_intro'    => 'Guarde estes códigos em local seguro (gerenciador de senhas, cofre, papel). Cada código pode ser usado UMA vez se você perder acesso ao app autenticador.',
    'recovery_warning'  => 'Esta é a única vez que estes códigos serão exibidos. Se você perdê-los e perder o app, perderá acesso à sua conta.',
    'btn_copy'          => 'Copiar todos',
    'btn_download'      => 'Baixar como arquivo',
    'btn_done'          => 'Já guardei. Continuar',
    'copied'            => 'Códigos copiados para a área de transferência.',
    'copy_failed'       => 'Não foi possível copiar automaticamente. Selecione os códigos e copie manualmente.',
    'downloaded'        => 'Arquivo gerado. Guarde-o em local seguro.',

    // Verify (login flow)
    'verify_title'              => 'Verificação em dois fatores',
    'verify_subtitle'           => 'Último passo antes de entrar',
    'verify_intro'              => 'Abra seu app autenticador e digite o código atual. Se você não tem mais acesso ao app, use um código de recuperação.',
    'verify_hint_totp'          => 'Abra seu app autenticador (Google ou Microsoft Authenticator) e veja o código de 6 dígitos exibido agora.',
    'verify_hint_recovery'      => 'Digite um dos códigos de recuperação que você guardou ao ativar a verificação em dois fatores. Cada código funciona apenas uma vez.',
    'verify_help'               => 'Digite o código abaixo para concluir o acesso ao sistema.',
    'verify_use_recovery'       => 'Usar código de recuperação',
    'verify_use_totp'           => 'Usar aplicativo autenticador',
    'recovery_code_label'       => 'Código de recuperação (formato XXXX-XXXX)',
    'recovery_code_aria_label'  => 'Código de recuperação',
    'recovery_code_placeholder' => 'XXXX-XXXX',
    'btn_verify'                => 'Verificar',

    // Feedback e ações comuns
    'invalid_code'      => 'Código inválido ou expirado.',
    'network_error'     => 'Erro de rede. Tente novamente.',
    'too_many_attempts' => 'Muitas tentativas. Aguarde um minuto e tente novamente.',
    'session_expired'   => 'Sua sessão expirou. Recarregue a página e entre novamente.',
    'btn_logout'        => 'Sair',
];
