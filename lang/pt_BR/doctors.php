<?php

declare(strict_types=1);

/**
 * Textos da listagem de médicos (Pages/Panel/Doctors/Index, DoctorTable,
 * DoctorCards) — injetados como prop `t` por DoctorsController::index.
 */
return [
    'page_title'           => 'Médicos',
    'breadcrumb_dashboard' => 'Dashboard',
    'total_label'          => 'Total:',
    'view_table'           => 'Tabela',
    'view_cards'           => 'Cards',
    'btn_import'           => 'Importar',
    'btn_new'              => 'Novo médico',
    'search_clear'         => 'Limpar busca',
    'search_placeholder'   => 'Buscar por nome, e-mail, código ou CRM...',

    // Colunas
    'col_name'       => 'Nome',
    'col_phone'      => 'Telefone',
    'col_record'     => 'CRM',
    'col_email'      => 'E-mail',
    'col_created_at' => 'Cadastro',
    'col_code'       => 'Código',
    'col_status'     => 'Status',
    'col_actions'    => 'Ações',
    'sort_by'        => 'Ordenar por :column',
    'whatsapp'       => 'WhatsApp',
    'specialty'      => 'Especialidade',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Status / ações
    'status_active'        => 'Ativo',
    'status_inactive'      => 'Inativo',
    'action_view'          => 'Visualizar',
    'action_work_schedule' => 'Horários de atendimento',
    'action_edit'          => 'Editar',
    'action_activate'      => 'Ativar',
    'action_deactivate'    => 'Desativar',
    'action_delete'        => 'Excluir',
    'confirm_delete'       => 'Tem certeza que deseja excluir este médico?',

    // Estados
    'empty_list'   => 'Nenhum médico encontrado.',
    'loading'      => 'Carregando...',
    'retry'        => 'Tentar novamente',
    'more_actions' => 'Mais ações',
    'load_error'   => 'Não foi possível carregar os médicos. Tente novamente.',

    // Paginação ("Exibindo 1–15 de 40 médicos")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'médicos',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',
];
