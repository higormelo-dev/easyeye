<?php

declare(strict_types=1);

/*
 * Relatórios financeiros (Panel/Financial/Reports/{CashFlow,Covenants}.vue +
 * FinancialReportsController, inclusive cabeçalhos das exportações).
 * Prop `t` das páginas; chaves idênticas em lang/en/financial_reports.php.
 * Textos do filtro de período vêm de financial_shared (`t.shared.period`).
 */
return [
    // Estado da tela
    'loading'    => 'Carregando…',
    'load_error' => 'Não foi possível carregar o relatório. Tente novamente em instantes.',
    'sort_by'    => 'Ordenar por :column',

    // Exportação
    'export'            => 'Exportar',
    'export_title'      => 'Exportar o período aplicado (:from a :to)',
    'export_csv'        => 'CSV (planilha simples)',
    'export_xlsx'       => 'Excel (.xlsx)',
    'export_pdf'        => 'PDF',
    'export_pdf_failed' => 'Não foi possível gerar o PDF agora. Exporte em Excel ou CSV, ou tente de novo mais tarde.',

    // Formatação dos arquivos exportados
    'date_format'       => 'd/m/Y',
    'decimal_separator' => ',',

    // Valores ausentes
    'no_category' => 'Sem categoria',
    'no_covenant' => 'Sem convênio',
    // Convênio excluído (soft delete) que ainda tem faturamento no período.
    'covenant_inactive' => ':name (inativo)',
    'no_patient'        => 'Sem paciente',
    // LGPD (decisão do usuário): paciente só pelo código + iniciais, na tela e na exportação.
    'patient_ref' => ':code · :initials',

    // Rótulos de enums (lançamento de caixa e guia)
    'entry_type' => [
        'income'  => 'Receita',
        'expense' => 'Despesa',
    ],
    'entry_status' => [
        'pending'   => 'Pendente',
        'paid'      => 'Pago',
        'cancelled' => 'Cancelado',
    ],
    'claim_status' => [
        'draft'     => 'Rascunho',
        'submitted' => 'Enviado',
        'paid'      => 'Pago',
        'denied'    => 'Glosado',
        'cancelled' => 'Cancelado',
    ],

    // Relatório de fluxo de caixa
    'cashflow' => [
        'title'       => 'Relatório de fluxo de caixa',
        'breadcrumb'  => 'Relatório de fluxo de caixa',
        'sheet_name'  => 'Fluxo de caixa',
        'total_label' => 'Lançamentos:',

        // Indicadores: realizado × previsto (mesma definição da tela de Fluxo de caixa)
        'kpis_label'                 => 'Indicadores do período',
        'group_realized'             => 'Realizado',
        'group_realized_hint'        => 'Só lançamentos com status Pago.',
        'group_projected'            => 'Previsto',
        'group_projected_hint'       => 'Inclui os pendentes: o que ainda falta receber e pagar.',
        'kpi_received'               => 'Recebido',
        'kpi_received_hint'          => 'Receitas com status Pago no período.',
        'kpi_paid'                   => 'Pago',
        'kpi_paid_hint'              => 'Despesas com status Pago no período.',
        'kpi_realized_balance'       => 'Saldo realizado',
        'kpi_realized_balance_hint'  => 'Recebido menos Pago: o que efetivamente entrou e saiu do caixa.',
        'kpi_receivable'             => 'A receber',
        'kpi_receivable_hint'        => 'Receitas ainda pendentes no período.',
        'kpi_payable'                => 'A pagar',
        'kpi_payable_hint'           => 'Despesas ainda pendentes no período.',
        'kpi_projected_balance'      => 'Saldo previsto',
        'kpi_projected_balance_hint' => 'Saldo realizado mais A receber, menos A pagar.',
        'balance_positive'           => 'Positivo',
        'balance_negative'           => 'Negativo',
        'balance_zero'               => 'Zerado',
        'kpi_scope_note'             => 'Lançamentos cancelados ficam de fora. O Dashboard gerencial mostra só o realizado.',

        // Por categoria (receitas e despesas separadas)
        'by_category_income'  => 'Receitas por categoria',
        'by_category_expense' => 'Despesas por categoria',
        'col_category'        => 'Categoria',
        'col_total'           => 'Total',
        'col_share'           => '% do total',
        'no_category_income'  => 'Nenhuma receita no período.',
        'no_category_expense' => 'Nenhuma despesa no período.',

        // Por dia
        'by_day'          => 'Por dia',
        'by_day_hint'     => 'Pagos e pendentes, sem cancelados. O saldo acumulado soma os dias desde o início do período.',
        'col_day'         => 'Dia',
        'col_income'      => 'Receitas',
        'col_expense'     => 'Despesas',
        'col_day_balance' => 'Saldo do dia',
        'col_cumulative'  => 'Saldo acumulado',
        'footer_total'    => 'Total do período',
        'no_day_data'     => 'Nenhum dia com movimento no período.',

        // Lançamentos (tela e cabeçalhos da exportação)
        'entries'            => 'Lançamentos',
        'col_date'           => 'Data',
        'col_code'           => 'Código',
        'col_description'    => 'Descrição',
        'col_covenant'       => 'Convênio',
        'col_payment_method' => 'Forma de pagamento',
        'col_type'           => 'Tipo',
        'col_status'         => 'Status',
        'col_value'          => 'Valor',
        'no_entries'         => 'Nenhum lançamento no período.',

        // Teto do período (tela e exportações)
        'period_capped' => 'O período pedido (:requested_from a :requested_to) passa do limite de :days dias. Mostrando de :from a :to; a exportação usa o mesmo período.',

        // Lista de lançamentos: busca, filtros, ordenação, atalho e paginação
        'filters_label'          => 'Filtros da lista de lançamentos',
        'filters_scope_hint'     => 'Busca e filtros valem só para a lista. Indicadores, tabelas por categoria e por dia e a exportação consideram o período inteiro.',
        'search_placeholder'     => 'Buscar por descrição ou código',
        'search_clear'           => 'Limpar busca',
        'filter_type'            => 'Filtrar por tipo',
        'filter_type_all'        => 'Todos os tipos',
        'filter_status'          => 'Filtrar por status',
        'filter_status_all'      => 'Todos os status',
        'filter_category'        => 'Filtrar por categoria',
        'filter_category_all'    => 'Todas as categorias',
        'filters_clear'          => 'Limpar filtros',
        'filtered_count_one'     => ':count lançamento encontrado com os filtros',
        'filtered_count_other'   => ':count lançamentos encontrados com os filtros',
        'no_entries_filtered'    => 'Nenhum lançamento encontrado com a busca e os filtros aplicados.',
        'list_loading'           => 'Atualizando a lista…',
        'col_actions'            => 'Ações',
        'open_in_cash_flow'      => 'Abrir no fluxo de caixa',
        'open_in_cash_flow_code' => 'Abrir :code no fluxo de caixa',
        'pagination_label'       => 'Paginação dos lançamentos',
        'pagination_showing'     => 'Exibindo',
        'pagination_of'          => 'de',
        'pagination_suffix'      => 'lançamentos',
        'pagination_previous'    => 'Página anterior',
        'pagination_next'        => 'Próxima página',

        // PDF (resources/views/pdf/financial_cashflow.blade.php); colunas e
        // vazios reaproveitam as chaves da tela.
        'pdf' => [
            'clinic'         => 'Clínica: :name',
            'period'         => 'Período: :from até :to',
            'generated_at'   => 'Gerado em: :datetime',
            'total_income'   => 'Total de receitas',
            'total_expense'  => 'Total de despesas',
            'period_balance' => 'Saldo do período',
        ],
    ],

    // Relatório de faturamento por convênio
    'covenants' => [
        'title'        => 'Relatório de faturamento por convênio',
        'breadcrumb'   => 'Faturamento por convênio',
        'sheet_name'   => 'Faturamento por convênio',
        'period_basis' => 'Período pela data de atendimento. Guias em rascunho e canceladas não entram nos totais, a mesma regra do Dashboard gerencial.',
        'total_label'  => 'Guias:',

        // Indicadores
        'kpis_label'        => 'Indicadores do período',
        'kpi_claims'        => 'Guias',
        'kpi_claims_hint'   => 'Guias com atendimento no período, sem rascunhos e canceladas.',
        'kpi_billed'        => 'Total faturado',
        'kpi_billed_hint'   => 'Soma do valor das guias faturadas no período.',
        'kpi_received'      => 'Recebido',
        'kpi_received_hint' => 'Valor pago das guias com status Pago (mesma regra do Dashboard gerencial).',
        'kpi_glosa'         => 'Glosado',
        'kpi_glosa_hint'    => 'Valor negado pelos convênios nas guias do período.',
        'kpi_open'          => 'Em aberto',
        'kpi_open_hint'     => 'Valor das guias enviadas que ainda aguardam pagamento.',
        'rate_of_billed'    => ':percent do faturado',

        // Consolidado por convênio
        'by_covenant'        => 'Consolidado por convênio',
        'col_covenant'       => 'Convênio',
        'col_billed'         => 'Faturado',
        'col_received'       => 'Recebido',
        'col_glosa'          => 'Glosado',
        'col_open'           => 'Em aberto',
        'col_glosa_rate'     => '% Glosa',
        'col_received_rate'  => '% Recebido',
        'claims_count_one'   => ':count guia',
        'claims_count_other' => ':count guias',
        'inactive_badge'     => 'Inativo',
        'inactive_hint'      => 'Convênio excluído do cadastro; o faturamento do período continua aqui.',
        'glosa_alert_badge'  => 'Alta',
        'glosa_alert_hint'   => 'Glosa acima de :threshold do valor faturado.',
        'glosa_alert_legend' => '"Alta" = glosa acima de :threshold do valor faturado. % Glosa e % Recebido são calculados sobre o faturado.',
        'footer_total'       => 'Total',
        'no_data'            => 'Nenhuma guia faturada no período.',

        // Guias do convênio (linha expandida) e cabeçalhos da exportação
        'toggle_hint'         => 'Selecione um convênio para ver as guias do período.',
        'claims_title'        => 'Guias de :covenant no período',
        'claims_loading'      => 'Carregando guias…',
        'claims_error'        => 'Não foi possível carregar as guias. Tente novamente.',
        'claims_retry'        => 'Tentar novamente',
        'claims_empty'        => 'Nenhuma guia deste convênio no período.',
        'claims_close'        => 'Fechar',
        'claims_privacy_note' => 'Por privacidade (LGPD), o paciente aparece só pelo código e pelas iniciais.',
        'col_guide'           => 'Guia',
        'col_attendance_date' => 'Data do atendimento',
        'col_patient'         => 'Paciente (código · iniciais)',
        'col_status'          => 'Status',
        'col_value'           => 'Valor',
        'view_in_billing'     => 'Ver no faturamento',
        'view_glosas'         => 'Ver glosas do período',
        'pagination_label'    => 'Paginação das guias',
        'pagination_previous' => 'Página anterior',
        'pagination_next'     => 'Próxima página',
        'pagination_status'   => 'Página :current de :last',
    ],
];
