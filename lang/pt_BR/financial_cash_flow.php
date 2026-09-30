<?php

/*
 * Tela viva de Fluxo de Caixa (Panel/Financial/CashFlow/Index.vue +
 * CashEntryFormModal.vue), mensagens do CashFlowController/CashFlowService/
 * CashEntryRequest e rótulos dos enums FinancialEntryType/FinancialEntryStatus.
 * Manter as MESMAS chaves em lang/en/financial_cash_flow.php.
 */

return [
    'page_title'           => 'Fluxo de Caixa',
    'breadcrumb_financial' => 'Financeiro',
    'breadcrumb'           => 'Fluxo de Caixa',
    'total_label'          => 'Total:',
    'new_entry'            => 'Novo lançamento',
    'close_cash'           => 'Fechar caixa',
    'report'               => 'Relatório',

    // Indicadores (mesmos filtros da lista; cancelados ficam de fora)
    'kpis_label'                 => 'Indicadores do período',
    'kpi_received'               => 'Recebido',
    'kpi_received_hint'          => 'Receitas com status Pago no período e nos filtros da lista.',
    'kpi_receivable'             => 'A receber',
    'kpi_receivable_hint'        => 'Receitas ainda pendentes no período e nos filtros da lista.',
    'kpi_paid'                   => 'Pago',
    'kpi_paid_hint'              => 'Despesas com status Pago no período e nos filtros da lista.',
    'kpi_payable'                => 'A pagar',
    'kpi_payable_hint'           => 'Despesas ainda pendentes no período e nos filtros da lista.',
    'kpi_realized_balance'       => 'Saldo realizado',
    'kpi_realized_balance_hint'  => 'Recebido menos Pago: o que efetivamente entrou e saiu do caixa.',
    'kpi_projected_balance'      => 'Saldo previsto',
    'kpi_projected_balance_hint' => 'Saldo realizado mais A receber, menos A pagar.',
    'kpi_scope_note'             => 'Os indicadores seguem o período e os filtros da lista. Lançamentos cancelados ficam de fora.',

    // Filtros (aplicação automática)
    'filters_label'       => 'Filtros do fluxo de caixa',
    'search_placeholder'  => 'Buscar por descrição ou código (FLC)',
    'search_clear'        => 'Limpar busca',
    'filter_type'         => 'Tipo',
    'filter_type_all'     => 'Todos',
    'filter_type_income'  => 'Receitas',
    'filter_type_expense' => 'Despesas',
    'filter_status'       => 'Status',
    'filter_status_all'   => 'Todos os status',
    'filter_category'     => 'Categoria',
    'filter_category_all' => 'Todas as categorias',
    'filter_clear'        => 'Limpar filtros',
    'filtering'           => 'Atualizando a lista…',

    // Aviso de período fechado
    'closed_banner'      => 'Parte deste período está com o caixa fechado (:periods). Lançamentos nesses dias não podem ser criados, alterados nem excluídos.',
    'closed_banner_link' => 'Ver fechamentos',

    // Tabela e cards
    'table_caption'      => 'Lançamentos do período',
    'col_code'           => 'Código',
    'col_date'           => 'Data',
    'col_description'    => 'Descrição',
    'col_patient'        => 'Paciente',
    'col_category'       => 'Categoria',
    'col_payment_method' => 'Forma',
    'col_origin'         => 'Origem',
    'col_type'           => 'Tipo',
    'col_status'         => 'Status',
    'col_value'          => 'Valor',
    'col_actions'        => 'Ações',
    'sort_by'            => 'Ordenar por :column',
    'empty'              => 'Nenhum lançamento no período.',
    'empty_filtered'     => 'Nenhum lançamento encontrado com esses filtros.',
    'action_edit'        => 'Editar lançamento',
    'action_delete'      => 'Excluir lançamento',

    // Rodapé: totais de todo o conjunto filtrado (todas as páginas)
    'footer_label'   => 'Totais do filtro (:count lançamentos, sem cancelados)',
    'footer_income'  => 'Receitas',
    'footer_expense' => 'Despesas',
    'footer_balance' => 'Saldo',

    // Origem do lançamento (vínculo de sistema)
    'origins' => [
        'schedule'      => 'Agenda',
        'claim'         => 'Guia',
        'purchase'      => 'Compra',
        'doctor_payout' => 'Repasse médico',
        'manual'        => 'Manual',
    ],

    // Motivo de trava por linha (lock_reason)
    'lock_billing_claim'      => 'Guia de convênio',
    'lock_billing_claim_hint' => 'Lançamento gerado automaticamente ao registrar o recebimento de uma guia. Não pode ser alterado nem excluído aqui.',
    'lock_closed_period'      => 'Caixa fechado',
    'lock_closed_period_hint' => 'A data está num período de caixa fechado. Reabra o período no Fechamento de caixa para alterar.',
    'lock_doctor_payout'      => 'Repasse médico',
    'lock_doctor_payout_hint' => 'Despesa gerada automaticamente ao registrar o pagamento de um repasse médico. Para corrigir, estorne o pagamento em Financeiro › Repasse médico.',

    'lock_doctor_payout_allocation'      => 'Alocada em repasse',
    'lock_doctor_payout_allocation_hint' => 'Parte desta receita foi alocada como recebimento de repasse médico. Para alterar ou excluir, estorne as alocações em Financeiro › Repasse médico.',
    'locked_by_doctor_payout_allocation' => 'Esta receita tem recebimento de repasse médico alocado e não pode ser alterada nem excluída. Estorne as alocações em Financeiro › Repasse médico.',

    'types' => [
        'income'  => 'Receita',
        'expense' => 'Despesa',
    ],
    'statuses' => [
        'pending'   => 'Pendente',
        'paid'      => 'Pago',
        'cancelled' => 'Cancelado',
    ],
    // Formas de pagamento (App\Enums\PaymentMethod::label()).
    'payment_methods' => [
        'cash'        => 'À Vista',
        'credit'      => 'Crédito',
        'credit_cash' => 'Crédito e Dinheiro',
        'debit_cash'  => 'Débito e Dinheiro',
        'transfer'    => 'Transferência Bancária',
        'courtesy'    => 'Cortesia',
    ],

    // Paginação
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'lançamentos',
    'pagination_label'    => 'Paginação dos lançamentos',
    'pagination_previous' => 'Página anterior',
    'pagination_next'     => 'Próxima página',

    // Confirmação de exclusão
    'delete_title'         => 'Excluir lançamento?',
    'delete_message'       => 'O lançamento sai do caixa e do saldo do período.',
    'delete_confirm'       => 'Excluir',
    'cancel'               => 'Cancelar',
    'deleted'              => 'Lançamento excluído.',
    'delete_error'         => 'Não foi possível excluir o lançamento.',
    'network_error'        => 'Falha de conexão. Verifique a internet e tente novamente.',
    'session_expired'      => 'Sua sessão expirou. Recarregue a página e tente novamente.',
    'saved_outside_period' => 'O lançamento foi salvo em :date, fora do período filtrado.',

    // Modal de lançamento
    'form_title_new'           => 'Novo lançamento',
    'form_title_edit'          => 'Editar lançamento',
    'form_date'                => 'Data',
    'form_type'                => 'Tipo',
    'form_description'         => 'Descrição',
    'form_category'            => 'Categoria',
    'form_category_none'       => 'Sem categoria',
    'form_status'              => 'Status',
    'form_amount'              => 'Valor',
    'form_payment_method'      => 'Forma de pagamento',
    'form_payment_method_none' => 'Não informada',
    'form_covenant'            => 'Convênio',
    'form_covenant_none'       => 'Nenhum',
    'form_notes'               => 'Observações',
    'form_required'            => 'obrigatório',
    'form_save'                => 'Salvar',
    'form_create'              => 'Cadastrar',
    'form_save_and_new'        => 'Salvar e lançar outro',
    'form_cancel'              => 'Cancelar',
    'form_save_error'          => 'Não foi possível salvar o lançamento.',
    'form_discard_title'       => 'Há alterações não salvas. Descartar?',
    'form_discard_confirm'     => 'Descartar',
    'form_discard_keep'        => 'Continuar editando',
    'form_schedule_locked'     => 'Recebimento da agenda com pagamento dividido (dinheiro e cartão): valor e forma de pagamento só mudam pela agenda.',
    'form_schedule_link'       => 'Editar pela agenda',

    // Mensagens do servidor
    'created'                 => 'Lançamento cadastrado com sucesso.',
    'updated'                 => 'Lançamento atualizado com sucesso.',
    'destroyed'               => 'Lançamento excluído com sucesso.',
    'locked_by_claim'         => 'Este lançamento foi gerado pelo recebimento de uma guia de convênio e não pode ser alterado nem excluído no fluxo de caixa.',
    'locked_by_doctor_payout' => 'Este lançamento foi gerado pelo pagamento de um repasse médico e não pode ser alterado nem excluído no fluxo de caixa. Estorne o pagamento na tela de Repasse médico.',
    'category_type_mismatch'  => 'Selecione uma categoria da clínica compatível com o tipo do lançamento.',
    'schedule_split_locked'   => 'Este recebimento veio da agenda com pagamento dividido (dinheiro e cartão): valor e forma de pagamento só podem ser alterados pela agenda.',

    'reference_managed_by_system' => 'O vínculo do lançamento (agendamento, guia ou compra) é definido pelo sistema e não pode ser informado.',

    'attributes' => [
        'entry_date'     => 'data',
        'description'    => 'descrição',
        'type'           => 'tipo',
        'status'         => 'status',
        'amount'         => 'valor',
        'category_id'    => 'categoria',
        'covenant_id'    => 'convênio',
        'notes'          => 'observações',
        'payment_method' => 'forma de pagamento',
        'reference_type' => 'tipo de vínculo',
        'reference_id'   => 'vínculo',
    ],

    // php artisan financial:audit-cash-references (somente leitura)
    'audit_references' => [
        'invalid_entity' => 'A opção --entity precisa ser um UUID válido.',
        'invalid_limit'  => 'A opção --limit precisa ser um inteiro entre 1 e :max.',
        'scope_all'      => 'Escopo: todas as clínicas.',
        'scope_entity'   => 'Escopo: clínica :entity.',
        'col_issue'      => 'Inconsistência',
        'col_count'      => 'Lançamentos',
        'sample'         => ':issue — até :limit id(s) (lançamento | clínica):',
        'none'           => 'Nenhuma referência inconsistente encontrada.',
        'found'          => ':count lançamento(s) com referência inconsistente. Relatório somente leitura: nada foi alterado.',
        'issues'         => [
            'unknown_type'   => 'Tipo de vínculo fora da whitelist',
            'incomplete'     => 'Vínculo incompleto (tipo sem id ou id sem tipo)',
            'missing_target' => 'Registro vinculado inexistente',
            'cross_entity'   => 'Registro vinculado de outra clínica',
            'claim_mismatch' => 'Vínculo de guia divergente de billing_claim_id',
        ],
    ],
];
