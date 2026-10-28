<?php

declare(strict_types=1);

/**
 * Textos da listagem de fornecedores (Pages/Panel/Stock/Suppliers/Index,
 * SupplierTable, SupplierCards) — injetados como prop `t` por
 * App\Http\Controllers\Stock\SuppliersController::index. Mensagens de
 * validação/flash continuam em lang/pt_BR/stock.php (FormRequests).
 */
return [
    'page_title'          => 'Fornecedores',
    'total_label'         => 'Total:',
    'view_table'          => 'Visualização em tabela',
    'view_cards'          => 'Visualização em cards',
    'btn_purchase_orders' => 'Pedidos de compra',
    'btn_new'             => 'Novo fornecedor',
    'search_placeholder'  => 'Buscar por nome, código ou documento...',
    'search_clear'        => 'Limpar busca',

    // Filtro de status
    'filter_status_label'    => 'Filtrar por status',
    'filter_status_all'      => 'Todos',
    'filter_status_active'   => 'Ativos',
    'filter_status_inactive' => 'Inativos',

    // Colunas
    'col_name'     => 'Nome',
    'col_phone'    => 'Telefone',
    'col_document' => 'Documento',
    'col_contact'  => 'Contato',
    'col_email'    => 'E-mail',
    'col_code'     => 'Código',
    'col_status'   => 'Status',
    'col_actions'  => 'Ações',
    'sort_by'      => 'Ordenar por :column',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Status / ações
    'status_active'          => 'Ativo',
    'status_inactive'        => 'Inativo',
    'action_purchase_orders' => 'Pedidos de compra deste fornecedor',
    'action_edit'            => 'Editar',
    'action_delete'          => 'Excluir',
    'more_actions'           => 'Mais ações',
    'confirm_delete'         => 'Excluir o fornecedor ":name"?',

    // Estados
    'empty_list' => 'Nenhum fornecedor encontrado.',
    'close'      => 'Fechar',

    // Paginação ("Exibindo 1–15 de 40 fornecedores")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'fornecedores',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
