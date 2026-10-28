<?php

declare(strict_types=1);

/**
 * Textos da listagem de produtos de estoque (Pages/Panel/Stock/Products/Index,
 * ProductTable, ProductCards) — injetados como prop `t` por
 * App\Http\Controllers\Stock\ProductsController::index.
 */
return [
    'page_title'         => 'Produtos',
    'total_label'        => 'Total:',
    'view_table'         => 'Tabela',
    'view_cards'         => 'Cards',
    'btn_movements'      => 'Movimentação',
    'btn_import'         => 'Importar',
    'btn_new'            => 'Novo produto',
    'search_placeholder' => 'Buscar por nome, SKU, código ou código de barras...',
    'search_clear'       => 'Limpar busca',
    'close'              => 'Fechar',
    'toggle_error'       => 'Não foi possível carregar os dados atuais do produto. Atualize a página e tente novamente.',

    // Filtros
    'filter_status_label'    => 'Filtrar por status',
    'filter_category_label'  => 'Filtrar por categoria',
    'filter_status_all'      => 'Todos',
    'filter_status_active'   => 'Ativos',
    'filter_status_inactive' => 'Inativos',
    'category_all'           => 'Todas as categorias',
    'filter_low_stock'       => 'Só abaixo do mínimo',
    'filter_expiring_lots'   => 'Só com lote vencendo (30d)',

    // Colunas
    'col_code'        => 'Código',
    'col_name'        => 'Nome',
    'col_category'    => 'Categoria',
    'col_unit'        => 'Unidade',
    'col_qty_on_hand' => 'Saldo',
    'col_cost_avg'    => 'Custo médio',
    'col_sale_price'  => 'Preço',
    'col_status'      => 'Status',
    'col_actions'     => 'Ações',
    'sort_by'         => 'Ordenar por :column',

    // Indicadores
    'badge_opm'          => 'OPM',
    'badge_opm_title'    => 'Órtese, prótese ou material especial',
    'badge_expiring_lot' => 'Lote vencendo',
    'expiring_lot_title' => 'Vence em :date',
    'below_minimum'      => 'Abaixo do mínimo',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Status / ações
    'status_active'     => 'Ativo',
    'status_inactive'   => 'Inativo',
    'action_movements'  => 'Movimentações do produto',
    'action_edit'       => 'Editar',
    'action_activate'   => 'Ativar',
    'action_deactivate' => 'Desativar',
    'action_delete'     => 'Excluir',
    'confirm_delete'    => 'Excluir o produto ":name"?',
    'more_actions'      => 'Mais ações',

    // Estados
    'empty_list' => 'Nenhum produto encontrado.',

    // Paginação ("Exibindo 1–15 de 40 produtos")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'produtos',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
