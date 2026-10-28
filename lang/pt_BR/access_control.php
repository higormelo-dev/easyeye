<?php

declare(strict_types=1);

/**
 * Textos da tela de Usuários (Pages/Panel/Users/Index, UserTable, UserCards,
 * UserFormModal) — injetados como prop `t` por UsersController::index. Também
 * usados pelo controller (mensagens de retorno) e pelo EntityUserService
 * (proteções do proprietário e da própria conta).
 */
return [
    // Página
    'page_title'  => 'Usuários',
    'total_label' => 'Total:',
    'new_user'    => 'Novo usuário',
    'roles_link'  => 'Perfis e permissões',
    'close'       => 'Fechar',

    // Busca
    'search_placeholder' => 'Buscar por nome ou e-mail…',
    'search_clear'       => 'Limpar busca',

    // Alternância tabela/cards
    'view_table' => 'Visualizar em tabela',
    'view_cards' => 'Visualizar em cards',

    // Colunas
    'col_created_at'    => 'Cadastro',
    'col_name'          => 'Nome',
    'col_email'         => 'E-mail',
    'col_role'          => 'Perfil',
    'col_status'        => 'Status',
    'col_actions'       => 'Ações',
    'sort_by'           => 'Ordenar por :column',
    'extra_roles_one'   => '+:count perfil adicional',
    'extra_roles_other' => '+:count perfis adicionais',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Paginação ("Exibindo 1–12 de 40 usuários")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'usuários',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',

    // Status
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Excluído',

    // Selos
    'badge_owner' => 'Proprietário',
    'badge_self'  => 'Você',

    // Estados
    'empty'        => 'Nenhum usuário cadastrado.',
    'empty_search' => 'Nenhum usuário encontrado para esta busca.',

    // Ações
    'btn_edit'       => 'Editar',
    'btn_restore'    => 'Restaurar',
    'btn_deactivate' => 'Desativar',
    'btn_activate'   => 'Ativar',
    'btn_delete'     => 'Excluir',
    'more_actions'   => 'Mais ações',
    'owner_locked'   => 'O proprietário da clínica não pode ser alterado nesta tela.',

    // Confirmações
    'confirm_delete'  => 'Remover o acesso de ":name" a esta clínica? Dá para desfazer depois, restaurando o usuário.',
    'confirm_restore' => 'Restaurar o acesso de ":name" a esta clínica?',

    // Formulário
    'form_title_create'      => 'Novo usuário',
    'form_title_edit'        => 'Editar usuário',
    'field_name'             => 'Nome completo',
    'field_email'            => 'E-mail',
    'field_role'             => 'Perfil de acesso',
    'field_role_placeholder' => 'Selecione um perfil',
    'field_active'           => 'Usuário ativo',
    'field_password'         => 'Senha',
    'field_password_hint'    => 'Mínimo 8 caracteres, com letras maiúsculas, minúsculas, números e símbolos.',
    'field_password_confirm' => 'Confirmar senha',
    'field_extra_roles'      => 'Perfis adicionais',
    'extra_roles_empty'      => 'Nenhum perfil customizado cadastrado nesta clínica.',
    'extra_roles_hint'       => 'Permissões administrativas adicionais, além do perfil base acima.',
    'credentials_info'       => 'O usuário receberá estas credenciais para acessar o sistema.',
    'required'               => 'obrigatório',
    'btn_cancel'             => 'Cancelar',
    'btn_save'               => 'Salvar alterações',
    'btn_create'             => 'Criar usuário',

    // Retorno das ações (flash)
    'flash_created'     => 'Usuário cadastrado com sucesso.',
    'flash_updated'     => 'Usuário alterado com sucesso.',
    'flash_activated'   => 'Usuário ativado com sucesso.',
    'flash_deactivated' => 'Usuário desativado com sucesso.',
    'flash_deleted'     => 'Acesso do usuário removido com sucesso.',
    'flash_restored'    => 'Usuário restaurado com sucesso.',

    // Proprietário / própria conta
    'owner_protected' => 'O proprietário da entidade não pode ser desativado nem removido.',
    'self_protected'  => 'Você não pode desativar ou remover sua própria conta.',

    // Erros no navegador
    'js_error_load' => 'Erro ao carregar dados do usuário.',

    // Convite a usuário que já tem login no EasyEye (outra clínica). A
    // resposta nunca diz se o e-mail tem conta.
    'invitation' => [
        'button'         => 'Convidar usuário existente',
        'title'          => 'Convidar quem já usa o EasyEye',
        'intro'          => 'Informe o e-mail que a pessoa usa para entrar no EasyEye e o perfil que ela terá nesta clínica. Se o e-mail já tiver acesso, ela receberá um convite para aceitar.',
        'email'          => 'E-mail',
        'rule'           => 'Perfil nesta clínica',
        'rule_hint'      => 'Médicos são convidados pelo cadastro de médicos (com CRM).',
        'submit'         => 'Enviar convite',
        'close'          => 'Fechar',
        'sent'           => 'Se este e-mail já tiver acesso ao EasyEye, ele receberá um convite. Se não for aceito, cadastre a pessoa como novo usuário.',
        'plan_limit'     => 'O limite de usuários do plano foi atingido.',
        'cancelled'      => 'Convite cancelado.',
        'pending_title'  => 'Convites pendentes',
        'pending_hint'   => 'Aguardando aceite. Convites para e-mails sem acesso ao EasyEye não chegam a ninguém e expiram sozinhos.',
        'col_email'      => 'E-mail',
        'col_rule'       => 'Perfil',
        'col_sent_at'    => 'Enviado em',
        'col_expires_at' => 'Expira em',
        'cancel'         => 'Cancelar convite',
        'confirm_cancel' => 'Cancelar este convite?',

        'page' => [
            'title'   => 'Convite para acessar :clinic',
            'intro'   => ':clinic convidou você para acessar o EasyEye por ela, com o seu login de sempre.',
            'note'    => 'Seu login, senha e acesso às outras clínicas não mudam.',
            'role'    => 'Perfil oferecido nesta clínica: :role',
            'accept'  => 'Aceitar convite',
            'decline' => 'Recusar',
            'closed'  => 'Este convite não está mais disponível (expirado, recusado ou cancelado).',
            'back'    => 'Ir para minhas clínicas',
        ],

        'result' => [
            'accepted'       => 'Pronto! Agora você também tem acesso a :clinic. Selecione a clínica para continuar.',
            'already_member' => 'Você já tem acesso a :clinic.',
            'plan_limit'     => ':clinic atingiu o limite de usuários do plano. Peça para a clínica falar com o suporte.',
            'closed'         => 'Este convite não está mais disponível (expirado, recusado ou cancelado).',
            'declined'       => 'Convite recusado.',
        ],

        'mail' => [
            'subject'    => '[:clinic] Convite para acessar o EasyEye',
            'greeting'   => 'Olá, :name!',
            'intro'      => 'A clínica :clinic convidou você para acessar o EasyEye por ela.',
            'login_note' => 'Você entra com o seu login de sempre — nada muda nas outras clínicas.',
            'role'       => 'Perfil oferecido: :role.',
            'action'     => 'Ver convite',
            'expires'    => 'O convite vale por :days dias. Se você não reconhece esta clínica, ignore este e-mail.',
        ],
    ],
];
