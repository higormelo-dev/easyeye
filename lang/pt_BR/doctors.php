<?php

declare(strict_types=1);

/**
 * Textos da listagem de médicos (Pages/Panel/Doctors/Index, DoctorTable,
 * DoctorCards) — injetados como prop `t` por DoctorsController::index.
 */
return [
    'page_title'           => 'Médicos',
    'breadcrumb_dashboard' => 'Dashboard',
    'total_label'          => 'Total:',
    'view_table'           => 'Tabela',
    'view_cards'           => 'Cards',
    'btn_import'           => 'Importar',
    'btn_new'              => 'Novo médico',
    'search_clear'         => 'Limpar busca',
    'search_placeholder'   => 'Buscar por nome, e-mail, código ou CRM...',

    // Colunas
    'col_name'       => 'Nome',
    'col_phone'      => 'Telefone',
    'col_record'     => 'CRM',
    'col_email'      => 'E-mail',
    'col_created_at' => 'Cadastro',
    'col_code'       => 'Código',
    'col_status'     => 'Status',
    'col_actions'    => 'Ações',
    'sort_by'        => 'Ordenar por :column',
    'whatsapp'       => 'WhatsApp',
    'specialty'      => 'Especialidade',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Status / ações
    'status_active'        => 'Ativo',
    'status_inactive'      => 'Inativo',
    'action_view'          => 'Visualizar',
    'action_work_schedule' => 'Horários de atendimento',
    'action_edit'          => 'Editar',
    'action_activate'      => 'Ativar',
    'action_deactivate'    => 'Desativar',
    'action_delete'        => 'Excluir',
    'confirm_delete'       => 'Tem certeza que deseja excluir este médico?',

    // Estados
    'empty_list'   => 'Nenhum médico encontrado.',
    'loading'      => 'Carregando...',
    'retry'        => 'Tentar novamente',
    'more_actions' => 'Mais ações',
    'load_error'   => 'Não foi possível carregar os médicos. Tente novamente.',

    // Paginação ("Exibindo 1–15 de 40 médicos")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'médicos',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',

    // Convite a médico que já tem login no EasyEye (outra clínica).
    'invitation' => [
        'exists_elsewhere'  => 'Este médico já possui cadastro no EasyEye, mas ainda não nesta clínica. Envie um convite: ele recebe no e-mail do login dele e, ao aceitar, passa a atender aqui.',
        'send_button'       => 'Enviar convite',
        'sending'           => 'Enviando…',
        'sent'              => 'Convite enviado. O médico receberá o link no e-mail cadastrado no EasyEye.',
        'conflict'          => 'Não foi possível usar este CPF e este e-mail juntos. Verifique os dados.',
        'not_invitable'     => 'Nenhum médico com este CPF ou e-mail foi encontrado no EasyEye. Use o cadastro normal.',
        'import_use_invite' => 'Médico já possui cadastro no EasyEye (outra clínica). Para trazê-lo, use Novo médico e depois Enviar convite — a planilha não cria um segundo acesso.',
        'plan_limit'        => 'O limite de médicos do plano foi atingido.',
        'cancelled'         => 'Convite cancelado.',
        'pending_title'     => 'Convites pendentes',
        'pending_hint'      => 'Aguardando o médico aceitar no e-mail dele.',
        'col_sent_at'       => 'Enviado em',
        'col_expires_at'    => 'Expira em',
        'cancel'            => 'Cancelar convite',
        'confirm_cancel'    => 'Cancelar este convite? O link enviado ao médico deixa de funcionar.',

        'page' => [
            'title'   => 'Convite para atender em :clinic',
            'intro'   => 'A clínica :clinic convidou você para atender por ela no EasyEye, com o seu login de sempre.',
            'note'    => 'Seu login, senha e dados nas outras clínicas não mudam. A clínica usará as informações que ela mesma cadastrou.',
            'accept'  => 'Aceitar convite',
            'decline' => 'Recusar',
            'closed'  => 'Este convite não está mais disponível (expirado, recusado ou cancelado).',
            'back'    => 'Ir para minhas clínicas',
        ],

        'result' => [
            'accepted'       => 'Pronto! Agora você também atende em :clinic. Selecione a clínica para continuar.',
            'already_member' => 'Você já faz parte de :clinic.',
            'plan_limit'     => ':clinic atingiu o limite de médicos do plano. Peça para a clínica falar com o suporte.',
            'closed'         => 'Este convite não está mais disponível (expirado, recusado ou cancelado).',
            'declined'       => 'Convite recusado.',
            'conflict'       => 'Alguns dados do convite (CPF, CRM, especialidade ou cor) já estão em uso em :clinic. Peça para a clínica enviar um novo convite.',
        ],

        'mail' => [
            'subject'    => '[:clinic] Convite para atender pelo EasyEye',
            'greeting'   => 'Olá, :name!',
            'intro'      => 'A clínica :clinic convidou você para atender por ela no EasyEye.',
            'login_note' => 'Você entra com o seu login de sempre — nada muda nas outras clínicas.',
            'action'     => 'Ver convite',
            'expires'    => 'O convite vale por :days dias. Se você não reconhece esta clínica, ignore este e-mail.',
        ],
    ],
];
