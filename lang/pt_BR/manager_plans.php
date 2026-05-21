<?php

declare(strict_types=1);

return [
    // ── Página ────────────────────────────────────────────────────────────────
    'page_title'         => 'Planos',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Planos',
    'btn_new'            => 'Novo plano',
    'search_placeholder' => 'Buscar por nome...',
    'total_label'        => 'Total:',
    'loading'            => 'Carregando...',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Visualização em tabela',
    'view_cards' => 'Visualização em cards',

    // ── Confirmações ──────────────────────────────────────────────────────────
    'confirm_delete' => 'Tem certeza que deseja remover este plano?',

    // ── Tabela — cabeçalhos ───────────────────────────────────────────────────
    'col_order'   => 'Ordem',
    'col_name'    => 'Nome',
    'col_price'   => 'Preço',
    'col_cycle'   => 'Ciclo',
    'col_status'  => 'Status',
    'col_actions' => 'Ações',

    // ── Status badges ─────────────────────────────────────────────────────────
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',

    // ── Ações ─────────────────────────────────────────────────────────────────
    'action_view'       => 'Visualizar',
    'action_edit'       => 'Editar',
    'action_activate'   => 'Ativar',
    'action_deactivate' => 'Desativar',
    'action_delete'     => 'Remover',

    // ── Paginação ─────────────────────────────────────────────────────────────
    'showing_from'   => 'Exibindo',
    'showing_to'     => 'a',
    'showing_of'     => 'de',
    'showing_suffix' => 'planos',

    // ── Estados vazios ────────────────────────────────────────────────────────
    'empty_list'     => 'Nenhum plano encontrado.',
    'empty_features' => 'Nenhuma feature configurada neste plano.',

    // ── Formulário — títulos ──────────────────────────────────────────────────
    'form_title_create' => 'Novo Plano',
    'form_title_edit'   => 'Editar Plano',

    // ── Formulário — abas ─────────────────────────────────────────────────────
    'tab_data'     => 'Dados',
    'tab_features' => 'Features',

    // ── Formulário — campos (Dados) ───────────────────────────────────────────
    'field_name'                    => 'Nome',
    'field_name_required'           => 'Nome *',
    'field_sort_order'              => 'Ordem',
    'field_description'             => 'Descrição',
    'field_description_placeholder' => 'Descrição breve do plano...',
    'field_price'                   => 'Preço',
    'currency_prefix'               => 'R$',
    'field_billing_cycle'           => 'Ciclo de cobrança',
    'field_billing_cycle_required'  => 'Ciclo de cobrança *',
    'field_status'                  => 'Status',
    'status_option_active'          => 'Ativo',
    'status_option_inactive'        => 'Inativo',

    // ── Formulário — features ─────────────────────────────────────────────────
    'features_info'                 => 'Para limites numéricos, 0 = ilimitado. Features booleanas indicam se o recurso está disponível no plano.',
    'features_numeric_section'      => 'Limites Quantitativos',
    'features_numeric_placeholder'  => '0 = ilimitado',
    'features_numeric_hint'         => '0 = ilimitado',
    'features_boolean_section'      => 'Recursos Disponíveis',
    'features_boolean_not_included' => 'Não incluído',
    'features_boolean_included'     => 'Incluído',

    // ── Formulário — botões ───────────────────────────────────────────────────
    'btn_cancel'       => 'Cancelar',
    'btn_save_changes' => 'Salvar alterações',
    'btn_create_plan'  => 'Criar plano',

    // ── Drawer de detalhes ────────────────────────────────────────────────────
    'detail_btn_edit'   => 'Editar',
    'section_pricing'   => 'Precificação',
    'section_features'  => 'Limites e Features',
    'detail_price'      => 'Preço',
    'detail_cycle'      => 'Ciclo',
    'detail_sort_order' => 'Ordem de exibição',
    'detail_description'=> 'Descrição',
    'detail_created_at' => 'Cadastrado em',

    // ── Valores de features (drawer) ──────────────────────────────────────────
    'feature_included'     => 'Incluído',
    'feature_not_included' => 'Não incluído',
    'feature_unlimited'    => 'Ilimitado',
];
