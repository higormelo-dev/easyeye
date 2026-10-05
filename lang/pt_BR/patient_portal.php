<?php

/*
 * Portal do Paciente — convite e vínculo de clínicas à conta. Cada clínica tem
 * o seu cadastro do paciente; o próprio paciente junta as clínicas numa conta
 * aceitando o convite de cada uma (logado e confirmando a senha).
 */
return [
    // Clínica com o serviço suspenso: o portal fica só para consulta (sem
    // expor ao paciente o motivo — nunca falar de pagamento/assinatura).
    'read_only' => [
        'badge'          => 'Somente consulta',
        'notice'         => 'Agendamento online e outras solicitações estão indisponíveis no momento; entre em contato com a clínica. Seus documentos continuam disponíveis para ver e baixar.',
        'action_blocked' => 'Esta ação está indisponível no momento. Entre em contato com a clínica.',
    ],

    'invitation' => [
        'already_used'     => 'Este convite já foi utilizado. Faça login com sua senha.',
        'login_to_link'    => 'Você já tem conta no Portal do Paciente. Entre com sua senha para adicionar :clinic à sua conta.',
        'linked'           => ':clinic adicionada à sua conta.',
        'no_email'         => 'Paciente sem e-mail cadastrado — contate a clínica.',
        'account_disabled' => 'O acesso deste e-mail ao Portal do Paciente está desativado. Entre em contato com a clínica.',
    ],

    'link' => [
        'page_title'      => 'Adicionar clínica — Portal do Paciente',
        'title'           => 'Adicionar clínica à sua conta',
        'intro'           => 'Você recebeu um convite para ver, na sua conta do Portal do Paciente, os documentos liberados por:',
        'clinic_fallback' => 'Clínica',
        'account'         => 'Sua conta',
        'email_mismatch'  => 'Este convite foi enviado para :email, que não é o e-mail desta conta. Por segurança, ele não pode ser adicionado aqui: saia e abra o convite novamente para criar o acesso com esse e-mail.',
        'submit'          => 'Adicionar clínica',
        'not_you'         => 'Não é sua conta? Sair',
    ],
];
