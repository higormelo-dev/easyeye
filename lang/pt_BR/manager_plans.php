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
    'detail_btn_edit'    => 'Editar',
    'section_pricing'    => 'Precificação',
    'section_features'   => 'Limites e Features',
    'detail_price'       => 'Preço',
    'detail_cycle'       => 'Ciclo',
    'detail_sort_order'  => 'Ordem de exibição',
    'detail_description' => 'Descrição',
    'detail_created_at'  => 'Cadastrado em',

    // ── Valores de features (drawer) ──────────────────────────────────────────
    'feature_included'     => 'Incluído',
    'feature_not_included' => 'Não incluído',
    'feature_unlimited'    => 'Ilimitado',

    // ── Preços por ciclo ──────────────────────────────────────────────────────
    'tab_pricing'                => 'Preços e ciclos',
    'pricing_info'               => 'Marque os ciclos que o cliente pode escolher no site e no cadastro e informe o preço de cada um. O ciclo padrão aparece primeiro.',
    'pricing_offer'              => 'Oferecer o ciclo :cycle',
    'pricing_price_label'        => 'Preço do ciclo :cycle',
    'pricing_default'            => 'Padrão',
    'no_sellable_cycle'          => 'Nenhum ciclo à venda — fica fora do site até você cadastrar um.',
    'pricing_default_label'      => 'Usar :cycle como ciclo padrão',
    'pricing_monthly_equivalent' => '≈ :price/mês',
    'pricing_savings'            => ':percent% de economia sobre o mensal',
    'pricing_no_savings'         => 'Mesmo valor de pagar mês a mês',
    'pricing_more_expensive'     => 'Mais caro que pagar mês a mês',
    'pricing_discount_label'     => 'Desconto sobre o mensal (%)',
    'pricing_apply_discount'     => 'Calcular trimestral, semestral e anual',
    'pricing_apply_hint'         => 'Preenche os ciclos marcados a partir do preço mensal com o desconto informado. Você ainda pode ajustar cada valor.',
    'pricing_need_monthly'       => 'Informe o preço mensal para calcular os outros ciclos.',
    'field_is_featured'          => 'Destaque no site ("Mais popular")',
    'field_is_featured_hint'     => 'O cartão do plano aparece em evidência na página de preços.',

    // ── Listagem: preços e assinantes ────────────────────────────────────────
    'col_prices'                => 'Preços por ciclo',
    'col_subscribers'           => 'Assinantes',
    'subscribers_link_title'    => 'Ver as assinaturas deste plano',
    'action_view_subscriptions' => 'Ver assinaturas',
    'action_new_subscription'   => 'Nova assinatura neste plano',
    'featured_badge'            => 'Destaque',
    'cycles_count'              => ':count ciclo|:count ciclos',

    // ── Drawer: preços e assinantes ──────────────────────────────────────────
    'section_prices'             => 'Preços por ciclo',
    'section_subscribers'        => 'Assinantes',
    'detail_default_cycle'       => 'Ciclo padrão',
    'detail_featured'            => 'Destaque no site',
    'detail_yes'                 => 'Sim',
    'detail_no'                  => 'Não',
    'subscribers_total'          => 'Empresas usando o plano',
    'subscribers_trial'          => 'Em trial',
    'subscribers_gateway'        => 'Cobrança automática',
    'subscribers_complimentary'  => 'Cortesia',
    'subscribers_empty'          => 'Nenhuma empresa usa este plano agora.',
    'features_numeric_hint_none' => '0 = não incluído',

    // ── Navegação Planos ↔ Assinaturas ───────────────────────────────────────
    'nav_plans'         => 'Planos',
    'nav_subscriptions' => 'Assinaturas',
    'nav_label'         => 'Planos e assinaturas',

    // ── Mensagens ─────────────────────────────────────────────────────────────
    'flash_created'        => 'Plano criado com sucesso.',
    'flash_updated'        => 'Plano atualizado com sucesso.',
    'flash_status_updated' => 'Status do plano atualizado.',
    'flash_deleted'        => 'Plano removido com sucesso.',

    // ── Trial (empresas novas) ────────────────────────────────────────────────
    'trial_title'        => 'Período de teste (trial)',
    'trial_days'         => 'Dias de trial',
    'trial_days_unit'    => 'dia|dias',
    'trial_hint'         => 'Toda empresa nova começa com este período grátis. Quando ele acaba, o acesso é bloqueado na hora (não há dias de graça) e só volta quando a empresa contratar um plano. Vale para os próximos trials; os que estão em andamento não mudam.',
    'trial_save'         => 'Salvar',
    'trial_saved'        => 'Dias de trial atualizados.',
    'trial_save_failed'  => 'Não foi possível salvar os dias de trial.',
    'trial_audit_reason' => 'Dias de trial alterados na tela de Planos.',

    // ── Checkout transparente (cartão parcelado no anual) ─────────────────────
    'checkout_title'             => 'Parcelamento no cartão',
    'checkout_max_installments'  => 'Máximo de parcelas sem juros',
    'checkout_installments_hint' => 'Só no ciclo anual, sem juros para o cliente (o EasyEye absorve a taxa). Vale também como teto: o gateway pode aceitar menos (Stripe: só à vista).',
    'checkout_saved'             => 'Parcelamento do checkout atualizado.',
    'checkout_save'              => 'Salvar',
    'checkout_save_failed'       => 'Não foi possível salvar o parcelamento.',
    'checkout_installments_unit' => 'parcela|parcelas',
    'checkout_audit_reason'      => 'Máximo de parcelas do checkout alterado na tela de Planos.',

    'validation' => [
        'name_required'             => 'O nome do plano é obrigatório.',
        'cycle_required'            => 'Escolha o ciclo padrão.',
        'cycle_invalid'             => 'Ciclo de cobrança inválido.',
        'cycle_duplicated'          => 'Cada ciclo só pode ter um preço.',
        'prices_required'           => 'Ofereça pelo menos um ciclo de cobrança.',
        'price_required'            => 'Informe o preço do ciclo.',
        'price_invalid'             => 'O preço precisa ser um valor igual ou maior que zero.',
        'default_cycle_not_offered' => 'O ciclo padrão precisa estar entre os ciclos oferecidos.',
        'feature_required'          => 'Preencha este limite (0 = ilimitado).',
        'feature_integer'           => 'Use um número inteiro igual ou maior que zero.',
    ],
];
