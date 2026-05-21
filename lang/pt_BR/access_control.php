<?php

declare(strict_types=1);

return [
    // Page
    'page_title'         => 'Controle de Acesso',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Usuários',

    // Header
    'total_label' => 'Total:',
    'new_user'    => 'Novo usuário',

    // Search
    'search_placeholder' => 'Buscar por nome ou e-mail…',

    // View toggle
    'view_table' => 'Visualizar em tabela',
    'view_cards' => 'Visualizar em cards',

    // Table columns
    'col_created_at' => 'Cadastro',
    'col_name'       => 'Nome',
    'col_email'      => 'E-mail',
    'col_role'       => 'Perfil',
    'col_status'     => 'Status',
    'col_actions'    => 'Ações',

    // Pagination
    'showing' => 'Exibindo :from–:to de :total usuários',

    // Status badges
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Excluído',

    // Empty state
    'empty' => 'Nenhum usuário encontrado.',

    // Row actions
    'btn_edit'       => 'Editar',
    'btn_restore'    => 'Restaurar',
    'btn_deactivate' => 'Desativar',
    'btn_activate'   => 'Ativar',
    'btn_delete'     => 'Excluir',

    // Confirm messages
    'confirm_delete'  => 'Tem certeza que deseja remover este usuário? Esta ação pode ser revertida via restauração.',
    'confirm_restore' => 'Restaurar este usuário?',

    // Form modal
    'form_title_create' => 'Novo Usuário',
    'form_title_edit'   => 'Editar Usuário',

    'field_name'            => 'Nome completo',
    'field_email'           => 'E-mail',
    'field_role'            => 'Perfil de acesso',
    'field_role_placeholder'=> 'Selecione um perfil',
    'field_active'          => 'Usuário ativo',
    'field_password'        => 'Senha',
    'field_password_hint'   => 'Mínimo 8 caracteres, com letras maiúsculas, minúsculas, números e símbolos.',
    'field_password_confirm'=> 'Confirmar senha',

    'credentials_info' => 'O usuário receberá estas credenciais para acessar o sistema.',

    'btn_cancel' => 'Cancelar',
    'btn_save'   => 'Salvar alterações',
    'btn_create' => 'Criar usuário',

    // Owner / self-protection
    'badge_owner'      => 'Proprietário',
    'owner_protected'  => 'O proprietário da entidade não pode ser desativado nem removido.',
    'self_protected'   => 'Você não pode desativar ou remover sua própria conta.',

    // JS errors
    'js_error_load' => 'Erro ao carregar dados do usuário.',
];
