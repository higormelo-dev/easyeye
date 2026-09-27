<?php

declare(strict_types=1);

/**
 * Textos dos relatórios de estoque: tela (Pages/Panel/Stock/Reports/Index e
 * ReportTable, prop `t` de StockReportsController::index), fallback do
 * StockReportService e cabeçalhos do CSV (`csv`, usados só por exportCsv e
 * fora da prop `t`). Breadcrumbs usam actions.sidemenu.*.
 */
return [
    'page_title'   => 'Relatórios de estoque',
    'btn_export'   => 'Exportar CSV',
    'export_title' => 'Exportar ":report" em CSV',

    // Período
    'period_label'    => 'Período (giro/consumo/compras):',
    'period_from'     => 'Data inicial',
    'period_to'       => 'Data final',
    'period_until'    => 'até',
    'period_invalid'  => 'A data inicial deve ser anterior ou igual à data final.',
    'period_required' => 'Informe a data inicial e a data final.',

    // Abas
    'tabs_label'      => 'Relatórios de estoque',
    'tab_inventory'   => 'Posição valorizada / Curva ABC',
    'tab_turnover'    => 'Giro de estoque',
    'tab_consumption' => 'Consumo por procedimento',
    'tab_purchases'   => 'Compras por fornecedor',

    // Colunas
    'col_product'        => 'Produto',
    'col_category'       => 'Categoria',
    'col_qty_on_hand'    => 'Saldo',
    'col_cost_avg'       => 'Custo médio',
    'col_total_value'    => 'Valor total',
    'col_cumulative_pct' => '% acumulado',
    'col_abc_class'      => 'Classe',
    'col_qty_out'        => 'Saída no período',
    'col_current_qty'    => 'Saldo atual',
    'col_turnover_ratio' => 'Giro (saída ÷ saldo)',
    'col_procedure'      => 'Procedimento',
    'col_doctor'         => 'Médico',
    'col_executed_at'    => 'Executado em',
    'col_materials'      => 'Materiais',
    'col_total_cost'     => 'Custo total',
    'col_supplier'       => 'Fornecedor',
    'col_orders_count'   => 'Pedidos com recebimento',
    'col_total_spent'    => 'Total gasto',
    'sort_by'            => 'Ordenar por :column',
    'abc_class_title'    => 'Classe :class da curva ABC',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Resumo e notas
    'inventory_total' => 'Valor total em estoque:',
    'note_abc'        => 'Curva ABC: classe A = produtos que somam até 80% do valor total em estoque, B = até 95%, C = o restante — convenção padrão pra priorizar controle nos itens de maior peso financeiro.',
    'note_turnover'   => 'Giro aproximado (saída no período ÷ valor do saldo atual) — não é o giro clássico por saldo médio (exigiria snapshot diário de estoque, que o sistema não guarda). Útil pra comparar produto parado vs. girando.',
    'note_purchases'  => 'Valorizado pelo que REALMENTE entrou no estoque (recebimentos confirmados), não pelo total pedido — pedido cancelado/parcial nunca infla este número.',

    // Estados vazios
    'empty_inventory'   => 'Nenhum produto com saldo.',
    'empty_turnover'    => 'Sem movimentação de saída no período.',
    'empty_consumption' => 'Nenhum consumo em procedimento registrado no período.',
    'empty_purchases'   => 'Nenhum recebimento de compra no período.',

    // Fornecedor excluído depois do recebimento (StockReportService)
    'supplier_removed' => 'Fornecedor removido',

    // Cabeçalhos do CSV (exportCsv) — pt_BR mantém o texto de sempre das planilhas
    'csv' => [
        'product'        => 'Produto',
        'code'           => 'Código',
        'category'       => 'Categoria',
        'qty_on_hand'    => 'Saldo',
        'cost_avg'       => 'Custo médio',
        'total_value'    => 'Valor total',
        'cumulative_pct' => '% acumulado',
        'abc_class'      => 'Classe ABC',
        'qty_out'        => 'Saída no período',
        'current_qty'    => 'Saldo atual',
        'turnover_ratio' => 'Giro',
        'procedure'      => 'Procedimento',
        'doctor'         => 'Médico',
        'executed_at'    => 'Executado em',
        'quantity'       => 'Quantidade',
        'unit_cost'      => 'Custo unitário',
        'total_cost'     => 'Custo total',
        'supplier'       => 'Fornecedor',
        'orders_count'   => 'Pedidos com recebimento',
        'total_spent'    => 'Total gasto',
    ],
];
