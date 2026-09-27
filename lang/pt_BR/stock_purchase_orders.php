<?php

declare(strict_types=1);

/**
 * Textos da listagem de pedidos de compra (Pages/Panel/Stock/PurchaseOrders/
 * Index, PurchaseOrderTable, PurchaseOrderCards) — injetados como prop `t`
 * por App\Http\Controllers\Stock\PurchaseOrdersController::index. Mensagens
 * de validação/flash continuam em lang/pt_BR/stock.php.
 */
return [
    'page_title'         => 'Pedidos de compra',
    'total_label'        => 'Total:',
    'view_table'         => 'Visualização em tabela',
    'view_cards'         => 'Visualização em cards',
    'btn_suppliers'      => 'Fornecedores',
    'btn_new'            => 'Novo pedido',
    'search_placeholder' => 'Buscar por código ou fornecedor...',
    'search_clear'       => 'Limpar busca',

    // Filtros
    'filter_status_label'      => 'Filtrar por status',
    'filter_status_all'        => 'Todos os status',
    'filter_supplier_label'    => 'Filtrar por fornecedor',
    'filter_supplier_all'      => 'Todos os fornecedores',
    'filter_supplier_unlisted' => 'Fornecedor selecionado',
    'filter_supplier_inactive' => ':name (inativo)',

    // Colunas
    'col_code'              => 'Código',
    'col_supplier'          => 'Fornecedor',
    'col_order_date'        => 'Data',
    'col_expected_delivery' => 'Previsão de entrega',
    'col_total'             => 'Total',
    'col_status'            => 'Status',
    'col_actions'           => 'Ações',
    'sort_by'               => 'Ordenar por :column',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Rótulos de status: prop `statuses`/`status_label` (PurchaseOrderStatus::label(), lang/*/stock_enums.php).

    // Ações
    'action_pdf'     => 'Baixar PDF',
    'action_send'    => 'Enviar ao fornecedor',
    'action_receive' => 'Receber',
    'action_edit'    => 'Editar',
    'action_cancel'  => 'Cancelar pedido',
    'action_delete'  => 'Excluir',
    'more_actions'   => 'Mais ações',
    'confirm_send'   => 'Enviar o pedido :code ao fornecedor?',
    'confirm_cancel' => 'Cancelar o pedido :code?',
    'confirm_delete' => 'Excluir o rascunho :code?',

    // Estados
    'empty_list' => 'Nenhum pedido de compra encontrado.',
    'load_error' => 'Não foi possível abrir o pedido. Tente novamente.',
    'close'      => 'Fechar',

    // Paginação ("Exibindo 1–15 de 40 pedidos")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'pedidos',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
