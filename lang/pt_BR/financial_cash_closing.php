<?php

/*
 * Fechamento de Caixa (Panel/Financial/CashClosing/Index.vue), mensagens do
 * CashClosingController, do CashCloseRequest e do ReopenCashCloseRequest.
 * Manter as MESMAS chaves em lang/en/financial_cash_closing.php.
 */

return [
    'page_title'           => 'Fechamento de Caixa',
    'breadcrumb_financial' => 'Financeiro',
    'breadcrumb'           => 'Fechamento de Caixa',
    'subtitle'             => 'Feche períodos para travar lançamentos e impedir alterações retroativas.',
    'back_to_cash_flow'    => 'Fluxo de caixa',

    // Formulário de fechamento
    'form_title'      => 'Fechar um período',
    'last_close_hint' => 'Último fechamento até :date. O início sugerido é o dia seguinte.',
    'notes'           => 'Observações',
    'close_btn'       => 'Fechar período',

    // Prévia (só leitura; o fechamento recalcula no servidor)
    'preview'                 => 'Prévia do período',
    'preview_loading'         => 'Atualizando a prévia…',
    'preview_hint'            => 'Inclui lançamentos pagos e pendentes; cancelados ficam de fora.',
    'income'                  => 'Receitas',
    'expense'                 => 'Despesas',
    'balance'                 => 'Saldo',
    'entries_count'           => 'Lançamentos',
    'pending_title'           => 'Pendentes',
    'pending_summary'         => ':count pendente(s): :income a receber e :expense a pagar.',
    'pending_none'            => 'Nenhum lançamento pendente no período.',
    'pending'                 => 'A receber (pendente)',
    'pending_expense'         => 'A pagar (pendente)',
    'view_pending'            => 'Ver pendentes no fluxo de caixa',
    'by_payment_method'       => 'Por forma de pagamento',
    'by_payment_method_empty' => 'Nenhum lançamento no período.',
    'col_payment_method'      => 'Forma',
    'col_count'               => 'Qtd.',
    'payment_method_none'     => 'Não informada',
    'overlap_warning'         => 'Já existe um fechamento que cobre parte deste período. Ajuste as datas ou reabra o fechamento existente.',
    'overlap_periods'         => 'Fechamentos ativos no intervalo: :periods.',

    // Confirmação do fechamento
    'confirm_title'           => 'Confirmar fechamento de caixa',
    'confirm_intro'           => 'Depois de fechado, nenhum lançamento entre :from e :to poderá ser criado, alterado ou excluído até o período ser reaberto.',
    'confirm_period'          => 'Período',
    'confirm_pending_warning' => 'Lançamentos pendentes neste período (:count): :income a receber e :expense a pagar. Eles entram nos totais do fechamento e, depois dele, só poderão ser marcados como pagos reabrindo o período.',
    'confirm_btn'             => 'Confirmar fechamento',
    'cancel'                  => 'Cancelar',
    'close_error'             => 'Não foi possível fechar o período.',
    'closed'                  => 'Período fechado com sucesso.',

    // Histórico
    'history'           => 'Períodos fechados',
    'empty'             => 'Nenhum período fechado ainda.',
    'col_period'        => 'Período',
    'col_income'        => 'Receitas',
    'col_expense'       => 'Despesas',
    'col_balance'       => 'Saldo',
    'col_closed_by'     => 'Fechado por',
    'col_closed_at'     => 'Fechado em',
    'col_notes'         => 'Observações',
    'col_actions'       => 'Ações',
    'view_entries'      => 'Ver lançamentos do período',
    'actions_more'      => 'Mais ações',
    'reopen'            => 'Reabrir período',
    'reopen_admin_only' => 'Só administradores da clínica podem reabrir um período.',

    // Reabertura (só admin, com motivo)
    'reopen_title'   => 'Reabrir período?',
    'reopen_message' => 'Reabrir o período de :from a :to libera a criação, a alteração e a exclusão de lançamentos nesses dias. A reabertura fica registrada na auditoria.',
    'reopen_confirm' => 'Reabrir período',
    'reopened'       => 'Período reaberto.',
    'reopen_error'   => 'Não foi possível reabrir o período.',

    // Textos do modal de motivo (ConfirmationWithReasonModal) na reabertura
    'reopen_reason_modal' => [
        'modal_reason_label'       => 'Motivo da reabertura',
        'modal_reason_hint'        => 'Explique por que o período precisa ser reaberto (mínimo de 10 caracteres). O motivo fica gravado no fechamento e na auditoria.',
        'modal_reason_placeholder' => 'Ex.: recebimento de 28/08 lançado com valor errado; corrigir e fechar de novo.',
    ],

    // Paginação
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'fechamentos',
    'pagination_label'    => 'Paginação dos fechamentos',
    'pagination_previous' => 'Página anterior',
    'pagination_next'     => 'Próxima página',

    // Validação (CashCloseRequest / ReopenCashCloseRequest)
    'validation' => [
        'period_start_future' => 'O início do período não pode ser depois de hoje.',
        'period_end_future'   => 'O fim do período não pode ser depois de hoje.',
        'period_end_before'   => 'O fim do período precisa ser igual ou posterior ao início.',
        'reason_required'     => 'Informe o motivo da reabertura.',
        'reason_min'          => 'O motivo precisa ter pelo menos :min caracteres.',
        'reason_max'          => 'O motivo pode ter no máximo :max caracteres.',
    ],
    'attributes' => [
        'period_start' => 'início do período',
        'period_end'   => 'fim do período',
        'notes'        => 'observações',
        'reason'       => 'motivo',
    ],
];
