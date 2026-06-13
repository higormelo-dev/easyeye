<?php

declare(strict_types=1);

return [
    'page_title'          => 'Usuários da Empresa',
    'breadcrumb_home'     => 'Dashboard',
    'breadcrumb_entities' => 'Empresas',
    'breadcrumb_current'  => 'Usuários',

    'btn_back'           => 'Voltar para empresas',
    'search_placeholder' => 'Buscar por nome ou e-mail...',
    'total_label'        => 'Total:',

    // Colunas
    'col_registered_at' => 'Cadastro',
    'col_code'          => 'Código',
    'col_name'          => 'Nome',
    'col_email'         => 'E-mail',
    'col_rule'          => 'Papel',
    'col_status'        => 'Status',
    'col_actions'       => 'Ações',

    // Status
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Removido',

    // Ações
    'action_impersonate'          => 'Entrar como este usuário',
    'action_impersonate_disabled' => 'Não é possível impersonar este usuário',
    'confirm_impersonate_title'   => 'Entrar como :name?',
    'confirm_impersonate_text'    => 'Você assumirá temporariamente o contexto desta clínica. Use apenas para suporte autorizado.',
    'confirm_impersonate_yes'     => 'Sim, continuar',
    'confirm_impersonate_no'      => 'Cancelar',

    // Estados vazios
    'empty_list' => 'Nenhum usuário encontrado nesta empresa.',
    'loading'    => 'Carregando...',
];
