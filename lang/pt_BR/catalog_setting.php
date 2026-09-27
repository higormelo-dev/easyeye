<?php

declare(strict_types=1);

/**
 * Strings genéricas reutilizadas por todos os catálogos clínicos
 * (tipos de pele, íris, lentes, convênios, etc.) renderizados via
 * a página Vue Pages/Panel/Settings/Catalog/Index.vue.
 */
return [
    // CRUD messages
    'created'  => 'Registro cadastrado.',
    'updated'  => 'Registro atualizado.',
    'deleted'  => 'Registro removido.',
    'restored' => 'Registro restaurado.',

    // Page chrome
    'btn_new'            => 'Novo',
    'btn_back'           => 'Voltar',
    'btn_save'           => 'Salvar',
    'btn_create'         => 'Cadastrar',
    'btn_cancel'         => 'Cancelar',
    'btn_close'          => 'Fechar',
    'btn_edit'           => 'Editar',
    'search_clear'       => 'Limpar busca',
    'search_placeholder' => 'Buscar por nome ou código...',
    'total_label'        => 'Total:',
    'view_table'         => 'Tabela',
    'view_cards'         => 'Cards',

    // Paginação ("Exibindo 1–15 de 812 registros")
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'registros',

    // Form modal
    'form_title_create' => 'Novo registro',
    'form_title_edit'   => 'Editar registro',

    // Status / badges
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',
    'status_deleted'  => 'Removido',
    'status_global'   => 'Padrão do sistema',

    // Ações
    'action_view'       => 'Ver detalhes',
    'action_edit'       => 'Editar',
    'action_delete'     => 'Excluir',
    'action_restore'    => 'Restaurar',
    'action_activate'   => 'Ativar',
    'action_deactivate' => 'Desativar',

    // Empty / loading
    'empty_list' => 'Nenhum registro cadastrado.',
    'loading'    => 'Carregando...',

    // Confirmações
    'confirm_delete'  => 'Excluir este registro?',
    'confirm_restore' => 'Restaurar este registro?',

    // Drawer de detalhes — chaves técnicas
    'detail_registered_at' => 'Cadastrado em',
    'detail_origin'        => 'Origem',
    'detail_origin_clinic' => 'Cadastrado pela clínica',
    'detail_origin_global' => 'Padrão do sistema',

    // Tabela (cabeçalhos, ordenação, colunas)
    'col_status'          => 'Status',
    'col_actions'         => 'Ações',
    'sort_by'             => 'Ordenar por :column',
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',
    'retry'               => 'Tentar novamente',
    'more_actions'        => 'Mais ações',
    'load_error'          => 'Não foi possível carregar os registros. Tente novamente.',

    // Yes/No genérico
    'yes' => 'Sim',
    'no'  => 'Não',
];
