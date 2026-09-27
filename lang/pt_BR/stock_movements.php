<?php

declare(strict_types=1);

/**
 * Textos da listagem de movimentação de estoque (Pages/Panel/Stock/
 * Movements/Index, MovementTable, MovementCards) — injetados como prop `t`
 * por StockMovementsController::index. Mesmas chaves em lang/en.
 */
return [
    'page_title'         => 'Movimentação de estoque',
    'total_label'        => 'Total:',
    'view_table'         => 'Tabela',
    'view_cards'         => 'Cards',
    'btn_products'       => 'Produtos',
    'btn_new'            => 'Nova movimentação',
    'search_placeholder' => 'Buscar por produto, código, lote ou observação...',
    'search_clear'       => 'Limpar busca',
    'filter_product'     => 'Filtrar por produto',
    'filter_product_all' => 'Todos os produtos',
    'filter_type'        => 'Filtrar por tipo',
    'filter_type_all'    => 'Todos os tipos',
    'close'              => 'Fechar',

    // Colunas
    'col_occurred_at'   => 'Data',
    'col_product'       => 'Produto',
    'col_lot'           => 'Lote',
    'col_type'          => 'Tipo',
    'col_quantity'      => 'Quantidade',
    'col_unit_cost'     => 'Custo unit.',
    'col_balance_after' => 'Saldo após',
    'col_note'          => 'Observação',
    'col_created_by'    => 'Por',
    'col_actions'       => 'Ações',
    'sort_by'           => 'Ordenar por :column',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Rótulo dos tipos: vem do backend (`type_label`, lang/{locale}/stock_enums.php).

    // Ações (ledger imutável: sem editar/excluir — correção é um novo lançamento)
    'action_filter_product' => 'Ver extrato deste produto',

    // Estados
    'empty_list' => 'Nenhuma movimentação encontrada.',

    // Paginação ("Exibindo 1–20 de 40 movimentações")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'movimentações',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
