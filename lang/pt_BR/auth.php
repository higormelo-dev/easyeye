<?php

declare(strict_types=1);

return [
    'failed'                   => 'As credenciais indicadas não coincidem com as registradas no sistema.',
    'password'                 => 'A senha está errada.',
    'throttle'                 => 'O número limite de tentativas de login foi atingido. Por favor, tente novamente dentro de :seconds segundos.',
    'sign_in'                  => 'Entrar',
    'sign_up'                  => 'Registrar-se',
    'log_out'                  => 'Sair',
    'forget_password'          => 'Esqueceu sua senha?',
    'remember_me'              => 'Lembrar-me',
    'back_to_login'            => 'Voltar para o login',
    'select_entity'            => 'Selecionar empresa',
    'login_subtitle'           => 'Por favor, informe seus dados para acessar o sistema',
    'no_account_yet'           => 'Ainda não tem uma conta?',
    'inactive'                 => 'Usuário não está ativo',
    'integrator_invalid'       => 'Código do integrador inválido ou inativo',
    'token_not_provided'       => 'Token não fornecido',
    'integrator_inactive'      => 'Integrador inativo',
    'entity_inactive'          => 'Empresa inativa',
    'token_valid'              => 'Token válido',
    'token_renewed'            => 'Token renovado automaticamente',
    'token_invalid'            => 'Token inválido',
    'token_expired'            => 'Token expirado',
    'user_integrator_inactive' => 'Usuário inativo',
    'token_expired_inactivity' => 'Token expirado por inatividade',
    'session_expiring_title'   => 'Sessão prestes a expirar',
    'session_expiring_html'    => 'Sua sessão irá expirar em <strong id="swal-session-countdown">2:00</strong> por inatividade.',
    'session_stay'             => 'Continuar conectado',
    'session_logout'           => 'Sair agora',

    'register' => [
        /* meta */
        'meta_title'       => ':app — Criar conta grátis',
        'meta_description' => 'Crie sua conta EasyEye e comece a usar gratuitamente. :days dias grátis, sem cartão de crédito.',

        /* painel esquerdo */
        'left_headline'         => 'Comece a transformar',
        'left_headline_em'      => 'sua clínica hoje',
        'left_sub'              => 'Gestão completa para clínicas oftalmológicas. Configure em menos de um dia.',
        'benefit_trial_title'   => ':days dias grátis, sem cartão',
        'benefit_trial_text'    => 'Experimente tudo sem compromisso. Cancele quando quiser.',
        'benefit_setup_title'   => 'Pronto em menos de 1 dia',
        'benefit_setup_text'    => 'Sem instalação, sem servidores. Tudo na nuvem, 100% online.',
        'benefit_support_title' => 'Suporte dedicado na implantação',
        'benefit_support_text'  => 'Nossa equipe acompanha você desde o primeiro acesso.',
        'benefit_lgpd_title'    => 'CFM & LGPD por padrão',
        'benefit_lgpd_text'     => 'Conformidade com as regulamentações do CFM, ANS e LGPD.',
        'testimonial_text'      => 'O EasyEye transformou nossa clínica. O faturamento TISS que levava dias agora é feito em horas.',
        'testimonial_name'      => 'Dr. Ricardo Mendes',
        'testimonial_role'      => 'Oftalmologista — Clínica Visão SP',

        /* faixa mobile */
        'days_free'  => 'dias grátis',
        'no_card'    => 'Sem cartão de crédito',
        'setup_fast' => 'Setup em menos de 1 dia',

        /* cabeçalho do card */
        'step1_title'    => 'Crie sua conta',
        'step1_subtitle' => ':days dias grátis · sem cartão de crédito',
        'step2_title'    => 'Dados da empresa',
        'step2_subtitle' => 'Quase lá! Configure sua clínica para começar.',

        /* campos e ações */
        'title'              => 'Crie sua conta',
        'step_personal'      => 'Seus dados',
        'step_company'       => 'Empresa & Plano',
        'name'               => 'Nome completo',
        'email'              => 'E-mail',
        'password'           => 'Senha',
        'confirm_password'   => 'Confirmar senha',
        'company_name'       => 'Nome da clínica / empresa',
        'cnpj'               => 'CNPJ',
        'optional'           => 'opcional',
        'choose_plan'        => 'Escolha um plano',
        'trial_note'         => 'Comece seu período gratuito — sem cartão de crédito.',
        'next'               => 'Próximo',
        'back'               => 'Voltar',
        'create_account'     => 'Criar conta',
        'start_trial'        => 'Iniciar período grátis',
        'processing'         => 'Processando...',
        'already_registered' => 'Já tem uma conta?',
        'log_in'             => 'Fazer login',
        'or'                 => 'ou',
        'quick_start'        => 'Começar com o plano',

        /* campos adicionais */
        'phone' => 'Telefone',

        /* métricas do painel esquerdo */
        'metric_clinics' => 'clínicas',

        /* validações JS */
        'email_taken'        => 'Este e-mail já está cadastrado.',
        'field_required'     => 'Campo obrigatório.',
        'passwords_mismatch' => 'As senhas não conferem.',

        /* força da senha */
        'strength_very_weak'   => 'Muito fraca',
        'strength_weak'        => 'Fraca',
        'strength_fair'        => 'Razoável',
        'strength_strong'      => 'Forte',
        'strength_very_strong' => 'Muito forte',
    ],

    'forgot_password' => [
        'title'       => 'Recuperar senha',
        'description' => 'Esqueceu sua senha? Sem problema. Informe seu e-mail e enviaremos um link para você criar uma nova senha.',
        'send_link'   => 'Enviar link de recuperação',
    ],

    'reset_password' => [
        'title'            => 'Nova senha',
        'email'            => 'E-mail',
        'password'         => 'Nova senha',
        'confirm_password' => 'Confirmar nova senha',
        'submit'           => 'Redefinir senha',
    ],

    'verify_email' => [
        'title'       => 'Verificar e-mail',
        'description' => 'Obrigado por se cadastrar! Antes de começar, verifique seu e-mail clicando no link que enviamos. Se não recebeu, podemos reenviar.',
        'link_sent'   => 'Um novo link de verificação foi enviado para o seu e-mail.',
        'resend'      => 'Reenviar e-mail de verificação',
    ],

    'panel_fp' => [
        'title'         => 'Recupere seu acesso',
        'subtitle'      => 'Em poucos minutos você terá acesso novamente à sua clínica.',
        'step_1'        => 'Informe o e-mail cadastrado na sua conta',
        'step_2'        => 'Receba o link de redefinição no seu e-mail',
        'step_3'        => 'Crie uma nova senha e acesse o sistema',
        'security_note' => 'Link válido por 60 minutos',
        'link_expiry'   => 'Link expira em 60 min',
    ],

    'panel' => [
        'feature_schedule'   => 'Agenda inteligente com confirmação automática',
        'feature_record'     => 'Prontuário oftalmológico completo e digital',
        'feature_tiss'       => 'Faturamento TISS 3.06 homologado ANS',
        'feature_compliance' => 'Compliance CFM & LGPD integrado',
        'quote_text'         => 'O EasyEye transformou nossa clínica. O faturamento TISS que levava dias agora é feito em horas.',
        'quote_author'       => 'Dr. Ricardo Mendes — Clínica Visão SP',
    ],
];
