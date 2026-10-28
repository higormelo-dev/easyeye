<?php

declare(strict_types=1);

/**
 * Textos da contagem física de estoque (Pages/Panel/Stock/Counts/Index,
 * CountTable, CountCards) — injetados como prop `t` por
 * StockCountsController::index. Mesmas chaves em lang/en.
 */
return [
    'page_title'          => 'Contagem de estoque',
    'total_label'         => 'Total:',
    'view_table'          => 'Tabela',
    'view_cards'          => 'Cards',
    'btn_movements'       => 'Movimentações',
    'opens_new_tab'       => 'abre em nova aba',
    'btn_apply'           => 'Aplicar contagem (:count)',
    'applying'            => 'Aplicando contagem...',
    'help'                => 'Digite a quantidade CONTADA fisicamente ao lado de cada produto. Item deixado em branco não é alterado — só quem tem um valor digitado entra no ajuste. Contagem igual ao saldo do sistema não gera movimentação. Os valores digitados continuam guardados ao buscar, filtrar ou trocar de página.',
    'search_placeholder'  => 'Buscar por nome, código ou código de barras...',
    'search_clear'        => 'Limpar busca',
    'filter_category'     => 'Filtrar por categoria',
    'filter_category_all' => 'Todas as categorias',
    'touched_summary'     => ':touched de :total produto(s) com contagem digitada',

    // Colunas
    'col_product'     => 'Produto',
    'col_code'        => 'Código',
    'col_category'    => 'Categoria',
    'col_qty_on_hand' => 'Saldo do sistema',
    'col_counted'     => 'Contado',
    'col_difference'  => 'Diferença',
    'col_actions'     => 'Ações',
    'sort_by'         => 'Ordenar por :column',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Linha
    'requires_lot'     => 'Exige lote',
    'counted_label'    => 'Quantidade contada de :product',
    'difference_match' => 'Confere',
    // O título soma `opens_new_tab`: "Ver movimentações do produto (abre em nova aba)".
    'action_movements' => 'Ver movimentações do produto',

    // Rótulo das unidades: vem do backend (`unit_label`, lang/{locale}/stock_enums.php).

    // Resultado
    'result_applied' => ':count produto(s) ajustado(s) — o resto já batia com o sistema.',
    'apply_error'    => 'Não foi possível aplicar a contagem.',
    'close'          => 'Fechar',

    // Estados
    'empty_list' => 'Nenhum produto ativo encontrado para contar.',

    // Paginação ("Exibindo 1–50 de 120 produtos")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'produtos',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
