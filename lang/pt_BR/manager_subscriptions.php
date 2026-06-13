<?php

declare(strict_types=1);

return [
    // ── Página ────────────────────────────────────────────────────────────────
    'page_title'         => 'Assinaturas',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Assinaturas',
    'search_placeholder' => 'Buscar por empresa ou plano...',
    'total_label'        => 'Total:',
    'loading'            => 'Carregando...',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Visualização em tabela',
    'view_cards' => 'Visualização em cards',

    // ── Configurações ─────────────────────────────────────────────────────────
    'settings_title'      => 'Configurações de Trial e Graça',
    'settings_trial_days' => 'Dias de Trial',
    'settings_grace_days' => 'Dias de Graça (após expiração)',
    'settings_save'       => 'Salvar',
    'settings_saved'      => 'Configurações atualizadas com sucesso.',

    // ── Tabela — cabeçalhos ───────────────────────────────────────────────────
    'col_entity'        => 'Empresa',
    'col_plan'          => 'Plano',
    'col_status'        => 'Status',
    'col_billing_state' => 'Cobrança',
    'col_gateway'       => 'Gateway',
    'col_next_billing'  => 'Próxima cobrança',
    'col_starts_at'     => 'Início',
    'col_ends_at'       => 'Vencimento',
    'col_actions'       => 'Ações',

    // ── Paginação ─────────────────────────────────────────────────────────────
    'showing_from'   => 'Exibindo',
    'showing_to'     => 'a',
    'showing_of'     => 'de',
    'showing_suffix' => 'assinaturas',

    // ── Estados vazios ────────────────────────────────────────────────────────
    'empty_list'     => 'Nenhuma assinatura encontrada.',
    'empty_invoices' => 'Nenhuma fatura encontrada.',
    'empty_retries'  => 'Nenhuma retentativa registrada.',

    // ── Ações ─────────────────────────────────────────────────────────────────
    'action_view'     => 'Visualizar',
    'action_edit'     => 'Editar',
    'action_activate' => 'Ativar plano',
    'action_trial'    => 'Iniciar trial',
    'action_cancel'   => 'Cancelar',
    'action_block'    => 'Bloquear acesso',
    'action_unblock'  => 'Desbloquear acesso',
    'attention_badge' => 'Requer atenção',

    // ── Confirmações ──────────────────────────────────────────────────────────
    'confirm_cancel_title'  => 'Cancelar assinatura?',
    'confirm_cancel_text'   => 'O acesso será mantido durante o período de graça. O cancelamento também será enviado ao gateway.',
    'confirm_cancel_btn'    => 'Sim, cancelar',
    'confirm_block_title'   => 'Bloquear acesso?',
    'confirm_block_text'    => 'A empresa e todos os seus usuários perderão o acesso ao sistema.',
    'confirm_block_btn'     => 'Sim, bloquear',
    'confirm_unblock_title' => 'Desbloquear acesso?',
    'confirm_unblock_text'  => 'A empresa e todos os seus usuários terão o acesso restaurado.',
    'confirm_unblock_btn'   => 'Sim, desbloquear',
    'confirm_no'            => 'Não',

    // ── Formulário de edição ──────────────────────────────────────────────────
    'form_edit_title'   => 'Editar Assinatura',
    'form_field_plan'   => 'Plano *',
    'form_field_status' => 'Status *',
    'form_field_starts' => 'Início *',
    'form_field_ends'   => 'Vencimento',
    'form_field_trial'  => 'Trial até',
    'form_select_plan'  => '-- Selecione --',
    'btn_save_changes'  => 'Salvar alterações',
    'btn_cancel'        => 'Cancelar',

    // ── Modal Ativar Plano ────────────────────────────────────────────────────
    'activate_title'           => 'Ativar Plano Pago',
    'activate_company'         => 'Empresa',
    'activate_plan'            => 'Plano *',
    'activate_plan_select'     => '-- Selecione --',
    'activate_cycle'           => 'Ciclo de cobrança *',
    'activate_gateway'         => 'Gateway',
    'activate_gateway_hint'    => 'Deixe em branco para usar o gateway padrão configurado.',
    'activate_gateway_default' => 'Padrão do sistema',
    'activate_btn'             => 'Ativar assinatura',
    'activate_success'         => 'Assinatura ativada com sucesso.',

    // ── Modal Trial ───────────────────────────────────────────────────────────
    'trial_title'     => 'Iniciar Trial',
    'trial_company'   => 'Empresa',
    'trial_plan'      => 'Plano (opcional)',
    'trial_plan_none' => 'Sem plano específico',
    'trial_days'      => 'Duração (dias)',
    'trial_days_hint' => 'Deixe em branco para usar o padrão configurado (:days dias).',
    'trial_btn'       => 'Iniciar trial',
    'trial_success'   => 'Trial iniciado com sucesso.',

    // ── Drawer de detalhes ────────────────────────────────────────────────────
    'detail_title'    => 'Detalhes da Assinatura',
    'detail_btn_edit' => 'Editar',

    // Tabs
    'tab_overview' => 'Visão Geral',
    'tab_invoices' => 'Faturas & Pagamentos',
    'tab_retries'  => 'Retentativas',

    // Overview — campos
    'detail_entity'                  => 'Empresa',
    'detail_plan'                    => 'Plano',
    'section_subscription_status'    => 'Status da Assinatura',
    'detail_status'                  => 'Status',
    'detail_billing_state'           => 'Estado de Cobrança',
    'detail_billing_error'           => 'Erro de Cobrança',
    'section_gateway'                => 'Gateway de Pagamento',
    'detail_gateway'                 => 'Gateway',
    'detail_gateway_empty'           => 'Não configurado',
    'detail_gateway_customer_id'     => 'ID do Cliente no Gateway',
    'detail_gateway_subscription_id' => 'ID da Assinatura no Gateway',
    'section_dates'                  => 'Datas',
    'detail_starts_at'               => 'Início',
    'detail_ends_at'                 => 'Vencimento',
    'detail_ends_at_lifetime'        => 'Vitalício',
    'detail_next_billing'            => 'Próxima Cobrança',
    'detail_next_billing_overdue'    => 'Vencida',
    'detail_last_payment'            => 'Último Pagamento',
    'detail_trial_ends_at'           => 'Trial até',
    'detail_grace_period_ends_at'    => 'Período de Graça até',
    'detail_cancelled_at'            => 'Cancelado em',
    'detail_created_at'              => 'Criado em',

    // Faturas
    'invoice_period'       => 'Período:',
    'invoice_due_at'       => 'Vencimento:',
    'invoice_paid_at'      => 'Pago em:',
    'invoice_payments'     => 'Pagamentos',
    'invoice_paid_label'   => 'Pago em:',
    'invoice_failed_label' => 'Falhou em:',

    // Retentativas — colunas
    'retry_col_attempt'   => 'Tentativa',
    'retry_col_status'    => 'Status',
    'retry_col_gateway'   => 'Gateway',
    'retry_col_scheduled' => 'Agendada para',
    'retry_col_executed'  => 'Executada em',
    'retry_col_result'    => 'Resultado',
];
