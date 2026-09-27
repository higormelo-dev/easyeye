<?php

declare(strict_types=1);

/**
 * Textos da listagem de lentes IOL (Pages/Panel/Stock/IolLenses/Index,
 * IolLensTable, IolLensCards) — injetados como prop `t` por
 * App\Http\Controllers\Stock\IolLensesController::index.
 */
return [
    'page_title'         => 'Lentes de catarata',
    'total_label'        => 'Total:',
    'view_table'         => 'Tabela',
    'view_cards'         => 'Cards',
    'btn_new'            => 'Nova lente',
    'search_placeholder' => 'Buscar por modelo ou fabricante...',
    'search_clear'       => 'Limpar busca',
    'close'              => 'Fechar',
    'toggle_error'       => 'Não foi possível carregar os dados atuais da lente. Atualize a página e tente novamente.',

    // Filtros
    'filter_status_label'    => 'Filtrar por status',
    'filter_status_all'      => 'Todas',
    'filter_status_active'   => 'Ativas',
    'filter_status_inactive' => 'Inativas',

    // Colunas
    'col_model'        => 'Modelo',
    'col_manufacturer' => 'Fabricante',
    'col_category'     => 'Tipo',
    'col_diopters'     => 'Dioptrias',
    'col_price'        => 'Valor',
    'col_stock'        => 'Estoque',
    'col_status'       => 'Status',
    'col_actions'      => 'Ações',
    'sort_by'          => 'Ordenar por :column',
    'diopter_range'    => ':min a :max D',
    'not_informed'     => 'Não informado',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Status / ações
    'status_active'     => 'Ativa',
    'status_inactive'   => 'Inativa',
    'action_movements'  => 'Movimentações da lente',
    'action_edit'       => 'Editar',
    'action_activate'   => 'Ativar',
    'action_deactivate' => 'Desativar',
    'action_delete'     => 'Excluir',
    'confirm_delete'    => 'Excluir a lente ":name"?',
    'more_actions'      => 'Mais ações',

    // Estados
    'empty_list' => 'Nenhuma lente encontrada.',

    // Paginação ("Exibindo 1–12 de 40 lentes")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'lentes',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
