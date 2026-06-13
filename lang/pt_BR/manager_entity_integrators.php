<?php

declare(strict_types=1);

return [
    'page_title'          => 'Integradores',
    'breadcrumb_home'     => 'Dashboard',
    'breadcrumb_entities' => 'Empresas',
    'breadcrumb_users'    => 'Usuários Integradores',
    'breadcrumb_current'  => 'Integradores',

    'btn_back'           => 'Voltar para usuários integradores',
    'btn_new'            => 'Novo integrador',
    'search_placeholder' => 'Buscar por nome, IP, MAC ou código...',
    'total_label'        => 'Total:',

    // Colunas
    'col_registered_at' => 'Cadastro',
    'col_code'          => 'Código',
    'col_name'          => 'Nome',
    'col_ip'            => 'IP',
    'col_mac'           => 'MAC',
    'col_status'        => 'Status',
    'col_actions'       => 'Ações',

    // Status
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Removido',

    // Ações
    'action_view'       => 'Ver detalhes',
    'action_edit'       => 'Editar',
    'action_equipments' => 'Equipamentos',
    'action_activate'   => 'Ativar',
    'action_deactivate' => 'Desativar',
    'action_delete'     => 'Excluir',
    'action_restore'    => 'Restaurar',

    // Empty
    'empty_list' => 'Nenhum integrador cadastrado para este usuário.',
    'loading'    => 'Carregando...',

    // Form modal
    'form_title_create' => 'Novo Integrador',
    'form_title_edit'   => 'Editar Integrador',
    'field_name'        => 'Nome',
    'field_ip'          => 'Endereço IP',
    'field_ip_hint'     => 'IPv4 ou IPv6 do equipamento/host.',
    'field_mac'         => 'Endereço MAC',
    'field_mac_hint'    => 'Formato: 00:1B:44:11:3A:B7',
    'field_active'      => 'Ativo',
    'field_yes'         => 'Sim',
    'field_no'          => 'Não',
    'btn_cancel'        => 'Cancelar',
    'btn_save'          => 'Salvar',
    'btn_create'        => 'Cadastrar',

    // Detail drawer
    'detail_loading'            => 'Carregando...',
    'detail_section_identity'   => 'Identidade',
    'detail_section_network'    => 'Rede',
    'detail_section_security'   => 'Segurança',
    'detail_section_equipments' => 'Equipamentos vinculados',
    'detail_open_equipments'    => 'Abrir equipamentos',
    'detail_active_tokens'      => 'Tokens ativos (Sanctum)',
    'detail_active_tokens_hint' => 'Tokens emitidos via POST /api/integrators (login do equipamento). Expiram em 7 dias.',
    'detail_registered_at'      => 'Cadastrado em',
    'detail_btn_edit'           => 'Editar',
    'detail_btn_close'          => 'Fechar',

    // Confirmações / toasts
    'confirm_delete'  => 'Excluir este integrador?',
    'confirm_restore' => 'Restaurar este integrador?',
    'created'         => 'Integrador cadastrado.',
    'updated'         => 'Integrador atualizado.',
    'deleted'         => 'Integrador removido.',
    'restored'        => 'Integrador restaurado.',
    'activated'       => 'Integrador ativado.',
    'deactivated'     => 'Integrador desativado.',
];
