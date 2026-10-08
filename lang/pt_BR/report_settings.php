<?php

/**
 * Modelos de documentação da clínica (Pages/Panel/Settings/ReportSettings/*)
 * — `status` é usado por App\Enums\ReportSettingStatus; o restante é a prop
 * `t` da listagem e as mensagens de Setting\ReportSettingsController.
 */
return [
    'status' => [
        'draft'     => 'Rascunho',
        'published' => 'Publicado',
        'archived'  => 'Arquivado',
    ],

    // Página
    'page_title'         => 'Modelos de documentação',
    'form_title_create'  => 'Novo modelo de documento',
    'form_title_edit'    => 'Editar modelo de documento',
    'total_label'        => 'Total:',
    'view_table'         => 'Tabela',
    'view_cards'         => 'Cards',
    'btn_new'            => 'Novo modelo',
    'search_placeholder' => 'Buscar por título ou descrição...',
    'search_clear'       => 'Limpar busca',
    'close'              => 'Fechar',

    // Filtros
    'filter_category_label'  => 'Filtrar por categoria',
    'filter_category_all'    => 'Todas as categorias',
    'filter_status_label'    => 'Filtrar por status',
    'filter_status_all'      => 'Todos',
    'filter_status_active'   => 'Ativos',
    'filter_status_inactive' => 'Inativos',

    // Colunas
    'col_title'      => 'Modelo',
    'col_category'   => 'Categoria',
    'col_paper'      => 'Papel',
    'col_blocks'     => 'Blocos',
    'col_origin'     => 'Origem',
    'col_updated_at' => 'Atualizado em',
    'col_status'     => 'Status',
    'col_actions'    => 'Ações',
    'sort_by'        => 'Ordenar por :column',
    'no_description' => 'Sem descrição',

    // Blocos do documento
    'block_header'    => 'Cabeçalho',
    'block_signature' => 'Assinatura',
    'block_footer'    => 'Rodapé',
    'block_on'        => ':block: incluído',
    'block_off'       => ':block: não incluído',

    // Origem
    'origin_own'       => 'Próprio',
    'origin_adopted'   => 'Adotado',
    'update_available' => 'Atualização disponível',

    // Status
    'status_active'   => 'Ativo',
    'status_inactive' => 'Inativo',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Ações
    'action_preview'   => 'Pré-visualizar',
    'action_edit'      => 'Editar',
    'action_reimport'  => 'Reimportar modelo global',
    'action_delete'    => 'Excluir',
    'more_actions'     => 'Mais ações',
    'confirm_delete'   => 'Excluir o modelo ":title"?',
    'confirm_reimport' => 'Reimportar a versão atual do modelo global em ":title"? Os textos alterados nesta clínica serão substituídos.',

    // Estados
    'empty_list'   => 'Nenhum modelo cadastrado.',
    'empty_search' => 'Nenhum modelo encontrado com estes filtros.',

    // Paginação ("Exibindo 1–12 de 40 modelos")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'modelos',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',

    // Retorno das ações
    'flash_saved'      => 'Modelo salvo com sucesso.',
    'flash_updated'    => 'Modelo atualizado com sucesso.',
    'flash_deleted'    => 'Modelo excluído com sucesso.',
    'flash_adopted'    => 'Modelo adotado com sucesso.',
    'flash_reimported' => 'Conteúdo reimportado com sucesso.',
    'error_reimport'   => 'Não foi possível reimportar: o modelo global de origem não está mais disponível.',
    'error_adopt'      => 'Este modelo não está disponível para adoção.',

    // Atualização do modelo padrão em conteúdo personalizado pela clínica
    'upstream_badge'       => 'Atualização disponível do modelo padrão',
    'upstream_hint'        => 'Este texto foi personalizado pela clínica e o modelo padrão do EasyEye mudou depois. Nada foi alterado sem você decidir.',
    'upstream_view'        => 'Ver modelo padrão',
    'upstream_apply'       => 'Aplicar atualização',
    'upstream_keep'        => 'Manter o meu',
    'upstream_confirm'     => 'Substituir o texto de ":label" pelo do modelo padrão atual? O texto personalizado da clínica será trocado (a troca fica registrada).',
    'upstream_title'       => 'Seu texto × modelo padrão atualizado',
    'upstream_local'       => 'Seu texto (personalizado)',
    'upstream_global'      => 'Modelo padrão atualizado',
    'upstream_loading'     => 'Carregando…',
    'upstream_error'       => 'Não foi possível carregar o modelo padrão.',
    'upstream_unsaved'     => 'Salve ou descarte as alterações deste formulário antes de aplicar ou manter.',
    'flash_update_applied' => 'Atualização do modelo padrão aplicada.',
    'flash_update_kept'    => 'Texto da clínica mantido. O aviso volta se o modelo padrão mudar de novo.',
];
