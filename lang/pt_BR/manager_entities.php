<?php

declare(strict_types=1);

return [
    // ── Página ────────────────────────────────────────────────────────────────
    'page_title'         => 'Empresas',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Empresas',
    'btn_new'            => 'Nova empresa',
    'search_placeholder' => 'Buscar por nome, código ou cidade...',
    'showing_from'       => 'Exibindo',
    'showing_to'         => 'a',
    'showing_of'         => 'de',
    'showing_suffix'     => 'empresas',
    'total_label'        => 'Total:',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Visualização em tabela',
    'view_cards' => 'Visualização em cards',

    // ── Tabela — cabeçalhos ───────────────────────────────────────────────────
    'col_registered_at' => 'Cadastro',
    'col_code'          => 'Código',
    'col_company'       => 'Empresa',
    'col_city_state'    => 'Cidade / UF',
    'col_users'         => 'Usuários',
    'col_status'        => 'Status',
    'col_actions'       => 'Ações',

    // ── Status badges ─────────────────────────────────────────────────────────
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Excluído',

    // ── Ações ─────────────────────────────────────────────────────────────────
    'action_view'                => 'Visualizar detalhes',
    'action_edit'                => 'Editar',
    'action_users'               => 'Usuários',
    'action_user_integrators'    => 'Usuários Integradores',
    'action_activate'            => 'Ativar',
    'action_deactivate'          => 'Desativar',
    'action_delete'              => 'Excluir',
    'action_restore'             => 'Restaurar',

    // ── Estados vazios ────────────────────────────────────────────────────────
    'empty_list'    => 'Nenhuma empresa encontrada.',
    'loading'       => 'Carregando...',

    // ── Confirmações ──────────────────────────────────────────────────────────
    'confirm_delete' => 'Tem certeza que deseja excluir esta empresa?',

    // ── Cards ─────────────────────────────────────────────────────────────────
    'card_code'     => 'Código:',
    'card_location' => 'Local:',

    // ── Formulário — títulos ──────────────────────────────────────────────────
    'form_title_create' => 'Nova Empresa',
    'form_title_edit'   => 'Editar Empresa',

    // ── Formulário — abas ─────────────────────────────────────────────────────
    'tab_data'    => 'Dados',
    'tab_address' => 'Endereço',
    'tab_config'  => 'Config.',

    // ── Formulário — campos (Dados) ───────────────────────────────────────────
    'field_name'                   => 'Nome',
    'field_name_required'          => 'Nome *',
    'field_subdomain'              => 'Subdomínio',
    'field_subdomain_placeholder'  => 'minha-empresa',
    'field_email'                  => 'E-mail',
    'field_telephone'              => 'Telefone',
    'field_cellphone'              => 'Celular',
    'field_national_registration'  => 'CNPJ / CPF',
    'field_state_registration'     => 'Insc. Estadual',
    'field_municipal_registration' => 'Insc. Municipal',
    'field_website'                => 'Site',
    'field_website_placeholder'    => 'https://',

    // ── Formulário — campos (Endereço) ────────────────────────────────────────
    'field_zipcode'    => 'CEP',
    'field_zipcode_placeholder' => '00000-000',
    'field_address'    => 'Logradouro',
    'field_number'     => 'Número',
    'field_complement' => 'Complemento',
    'field_district'   => 'Bairro',
    'field_city'       => 'Cidade',
    'field_city_required' => 'Cidade *',
    'field_state'      => 'UF',
    'field_state_required' => 'UF *',
    'field_country'    => 'País',
    'field_country_required' => 'País *',
    'btn_lookup_cep'   => 'Buscar CEP',

    // ── Formulário — campos (Configurações) ───────────────────────────────────
    'field_schedule_interval'      => 'Intervalo de consulta',
    'interval_15'                  => '15 minutos',
    'interval_20'                  => '20 minutos',
    'interval_30'                  => '30 minutos',
    'field_status'                 => 'Status',
    'status_option_active'         => 'Ativo',
    'status_option_inactive'       => 'Inativo',
    'config_info_after_create'     => 'Após criar a empresa, acesse a ficha para configurar usuários e integradores.',

    // ── Formulário — botões ───────────────────────────────────────────────────
    'btn_cancel'         => 'Cancelar',
    'btn_save_changes'   => 'Salvar alterações',
    'btn_create_company' => 'Cadastrar empresa',

    // ── Drawer de detalhes — cabeçalho ────────────────────────────────────────
    'detail_loading'     => 'Carregando...',
    'detail_btn_edit'    => 'Editar',
    'detail_deleted_at'  => 'Excluído em :date',

    // ── Drawer de detalhes — seções ───────────────────────────────────────────
    'section_data'    => 'Dados da Empresa',
    'section_docs'    => 'Documentos',
    'section_address' => 'Endereço',
    'section_config'  => 'Configurações',

    // ── Drawer de detalhes — campos ───────────────────────────────────────────
    'detail_email'                   => 'E-mail',
    'detail_subdomain'               => 'Subdomínio',
    'detail_telephone'               => 'Telefone',
    'detail_cellphone'               => 'Celular',
    'detail_website'                 => 'Site',
    'detail_national_registration'   => 'CNPJ / CPF',
    'detail_state_registration'      => 'Insc. Estadual',
    'detail_municipal_registration'  => 'Insc. Municipal',
    'detail_zipcode'                 => 'CEP',
    'detail_address'                 => 'Logradouro',
    'detail_district'                => 'Bairro',
    'detail_city_state'              => 'Cidade / UF',
    'detail_country'                 => 'País',
    'detail_schedule_interval'       => 'Intervalo de consulta',
    'detail_interval_minutes'        => ':value minutos',
    'detail_registered_at'           => 'Cadastro',
];
