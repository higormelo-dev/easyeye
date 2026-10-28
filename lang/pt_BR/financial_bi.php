<?php

declare(strict_types=1);

/*
 * Dashboard gerencial (Panel/Financial/Bi/Index.vue + ClinicBiService).
 * Prop `t` da página (o período usa `t.shared`, de financial_shared.php);
 * chaves idênticas em lang/en/financial_bi.php.
 */
return [
    'title' => 'Dashboard gerencial',

    // Carregamento do período
    'loading'    => 'Carregando…',
    'load_error' => 'Não foi possível atualizar o painel. Tente novamente em instantes.',

    // Atualização do cache
    'updated_at'    => 'Atualizado às :time',
    'refresh'       => 'Atualizar',
    'refresh_title' => 'Recalcular agora. Os números ficam guardados por até :minutes minutos para a tela abrir mais rápido.',

    // Faixas de indicadores
    'section_cash'          => 'Caixa',
    'section_cash_hint'     => 'Só lançamentos já pagos ou recebidos no período.',
    'section_billing'       => 'Convênios e TISS',
    'section_billing_hint'  => 'Guias pela data de atendimento, sem rascunhos e canceladas.',
    'section_schedule'      => 'Agenda',
    'section_schedule_hint' => 'Agendamentos do período.',

    // Caixa (rótulo, subtítulo e definição — a definição aparece na dica do card)
    'income'           => 'Receita',
    'income_sub'       => 'Entradas recebidas',
    'income_hint'      => 'Soma das entradas com status pago e data de lançamento no período.',
    'expense'          => 'Despesa',
    'expense_sub'      => 'Saídas pagas',
    'expense_hint'     => 'Soma das saídas com status pago e data de lançamento no período.',
    'balance'          => 'Saldo',
    'balance_hint'     => 'Receita menos despesa, só com lançamentos pagos.',
    'balance_positive' => 'Positivo',
    'balance_negative' => 'Negativo',
    'balance_zero'     => 'Sem diferença',

    // Convênios
    'billed'            => 'Faturado',
    'billed_sub'        => 'Guias enviadas, pagas e glosadas',
    'billed_hint'       => 'Valor das guias com atendimento no período, sem rascunhos e canceladas.',
    'received'          => 'Recebido',
    'received_sub'      => 'Guias pagas',
    'received_hint'     => 'Valor pago das guias com status Paga.',
    'glosa'             => 'Glosado',
    'glosa_sub'         => 'Negado pelos convênios',
    'glosa_hint'        => 'Valor negado pelos convênios nas guias do período.',
    'receipt_rate'      => 'Taxa de recebimento',
    'receipt_rate_sub'  => 'Recebido ÷ faturado',
    'receipt_rate_hint' => 'Valor recebido nas guias pagas dividido pelo valor faturado no período.',
    'avg_ticket'        => 'Ticket médio',
    'avg_ticket_sub'    => 'Por guia paga',
    'avg_ticket_hint'   => 'Valor recebido dividido pela quantidade de guias pagas no período.',
    'open_billing'      => 'Abrir faturamento',
    'see_glosas'        => 'Ver glosas',
    'see_report'        => 'Relatório por convênio',

    // Agenda
    'attended'             => 'Atendidos',
    'attended_sub'         => 'de :count agendamento(s)',
    'attended_hint'        => 'Agendamentos do período com situação Atendido.',
    'attendance_rate'      => 'Comparecimento',
    'attendance_rate_sub'  => ':count falta(s)',
    'attendance_rate_hint' => 'Atendidos ÷ (atendidos + faltas). Cancelados e pendentes não entram na conta.',
    'occupancy_rate'       => 'Ocupação',
    'occupancy_rate_sub'   => ':count cancelado(s)',
    'occupancy_rate_hint'  => 'Atendidos ÷ agendamentos não cancelados do período.',
    'new_patients'         => 'Pacientes novos',
    'new_patients_sub'     => 'Cadastrados no período',
    'new_patients_hint'    => 'Pacientes cadastrados na clínica dentro do período.',

    // Faturamento por convênio (top 6)
    'billing_by_covenant'      => 'Faturamento por convênio',
    'billing_by_covenant_hint' => 'Os 6 convênios com maior valor faturado no período.',
    'no_claims'                => 'Nenhuma guia faturada no período.',
    'no_covenant'              => 'Sem convênio',
    'covenant_inactive'        => ':name (inativo)',

    // Tendência mensal (gráfico de barras + tabela "Ver dados")
    'monthly_trend'      => 'Tendência mensal',
    'monthly_trend_hint' => 'Sempre os últimos 6 meses, independente do período escolhido acima. Só lançamentos pagos.',
    'trend_chart_aria'   => 'Gráfico de barras de receita e despesa por mês, de :from a :to, com a linha do saldo. Total: receita :income, despesa :expense, saldo :balance.',
    'col_month'          => 'Mês',
    'col_income'         => 'Receita',
    'col_expense'        => 'Despesa',
    'col_balance'        => 'Saldo',
    'no_trend_data'      => 'Nenhum lançamento pago nos últimos 6 meses.',
    'see_cash_flow'      => 'Relatório de fluxo de caixa',
    'see_data'           => 'Ver dados',
    'hide_data'          => 'Ocultar dados',

    // Mix da agenda (gráfico de rosca + tabela "Ver dados")
    'schedule_mix'        => 'Mix da agenda',
    'schedule_mix_hint'   => 'Situação dos agendamentos do período. Pendentes: agendados, confirmados ou em atendimento.',
    'schedule_chart_aria' => 'Gráfico de rosca com a situação dos :total agendamento(s) do período: :items.',
    'schedule_total'      => 'agendamento(s)',
    'no_schedules'        => 'Nenhum agendamento no período.',
    'col_status'          => 'Situação',
    'col_quantity'        => 'Quantidade',
    'col_share'           => 'Participação',
    'col_total'           => 'Total',

    // Rótulos das situações da agenda (chart_* também usados pelo ClinicBiService)
    'chart_attended'  => 'Atendidos',
    'chart_no_show'   => 'Não compareceram',
    'chart_cancelled' => 'Cancelados',
    'chart_pending'   => 'Pendentes',
];
