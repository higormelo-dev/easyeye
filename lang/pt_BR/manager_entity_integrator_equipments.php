<?php

declare(strict_types=1);

return [
    'page_title'           => 'Equipamentos',
    'breadcrumb_home'      => 'Dashboard',
    'breadcrumb_entities'  => 'Empresas',
    'breadcrumb_users'     => 'Usuários Integradores',
    'breadcrumb_integrators' => 'Integradores',
    'breadcrumb_current'   => 'Equipamentos',

    'btn_back'             => 'Voltar para integradores',
    'search_placeholder'   => 'Buscar por nome, IP, MAC, série ou código...',
    'total_label'          => 'Total:',

    // Read-only banner
    'readonly_note' => 'Esta tela é somente leitura. Equipamentos são cadastrados pelo próprio integrador via API.',

    // Colunas
    'col_registered_at' => 'Cadastro',
    'col_code'          => 'Código',
    'col_name'          => 'Nome',
    'col_ip'            => 'IP',
    'col_mac'           => 'MAC',
    'col_serial'        => 'Nº Série',
    'col_status'        => 'Status',
    'col_actions'       => 'Ações',

    // Status
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Removido',

    // Ações
    'action_view' => 'Ver detalhes',

    // Empty
    'empty_list' => 'Nenhum equipamento cadastrado para este integrador.',
    'loading'    => 'Carregando...',

    // Detail drawer
    'detail_loading'           => 'Carregando...',
    'detail_section_identity'  => 'Identidade',
    'detail_section_network'   => 'Rede',
    'detail_section_audit'     => 'Auditoria',
    'detail_registered_at'     => 'Cadastrado em',
    'detail_deleted_at'        => 'Removido em',
    'detail_btn_close'         => 'Fechar',
];
