<?php

declare(strict_types=1);

/**
 * Textos da tela de Perfis de acesso (Pages/Panel/AccessControl/Roles/Index,
 * RoleTable, RoleCards, RoleFormModal) — injetados como prop `t` por
 * App\Http\Controllers\AccessControl\RolesController::index. Também usados
 * pelo controller (mensagens de retorno) e pelo RoleRequest (validação).
 */
return [
    'page_title'         => 'Perfis de acesso',
    'total_label'        => 'Total:',
    'view_table'         => 'Tabela',
    'view_cards'         => 'Cards',
    'btn_new'            => 'Novo perfil',
    'search_placeholder' => 'Buscar perfil por nome ou descrição...',
    'search_clear'       => 'Limpar busca',
    'close'              => 'Fechar',

    // Aviso + perfis fixos da plataforma
    'notice'                => 'Os perfis do sistema já vêm definidos pela plataforma e são escolhidos no cadastro de cada usuário. Os perfis customizados abaixo concedem permissões administrativas adicionais. Ações clínicas (laudos, prescrições) continuam exclusivas de médicos, independente de perfil.',
    'system_profiles_title' => 'Perfis do sistema',
    'system_profiles_count' => ':count pré-definidos pela plataforma',
    'system_profile_badge'  => 'Padrão',

    // Colunas
    'col_name'        => 'Perfil',
    'col_permissions' => 'Permissões',
    'col_users'       => 'Usuários',
    'col_created_at'  => 'Cadastro',
    'col_actions'     => 'Ações',
    'sort_by'         => 'Ordenar por :column',
    'no_description'  => 'Sem descrição',

    // Contagens ("1 permissão", "3 usuários")
    'permissions_one'   => ':count permissão',
    'permissions_other' => ':count permissões',
    'users_one'         => ':count usuário',
    'users_other'       => ':count usuários',

    // Personalizar colunas
    'columns_label'       => 'Colunas',
    'columns_customize'   => 'Personalizar colunas',
    'columns_order_title' => 'Ordem das colunas',
    'columns_move_up'     => 'Mover para cima',
    'columns_move_down'   => 'Mover para baixo',
    'columns_reset'       => 'Restaurar padrão',

    // Ações
    'action_edit'               => 'Editar',
    'action_delete'             => 'Excluir',
    'confirm_delete'            => 'Excluir o perfil ":name"?',
    'confirm_delete_with_users' => 'Excluir o perfil ":name"? :count usuário(s) perderão estas permissões adicionais.',

    // Estados
    'empty_list'   => 'Nenhum perfil customizado cadastrado. Os perfis do sistema já cobrem os papéis padrão da clínica.',
    'empty_search' => 'Nenhum perfil encontrado para esta busca.',

    // Paginação ("Exibindo 1–12 de 40 perfis")
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'perfis',
    'pagination_label'    => 'Paginação',
    'pagination_previous' => 'Anterior',
    'pagination_next'     => 'Próxima',

    // Formulário (painel lateral)
    'form_title_create'      => 'Novo perfil',
    'form_title_edit'        => 'Editar perfil',
    'field_name'             => 'Nome',
    'field_description'      => 'Descrição',
    'field_description_hint' => 'Opcional — explique quando este perfil deve ser usado',
    'field_permissions'      => 'Permissões',
    'no_permissions'         => 'Nenhuma permissão disponível para atribuir.',
    'select_all'             => 'Marcar todos',
    'unselect_all'           => 'Desmarcar todos',
    'btn_cancel'             => 'Cancelar',
    'btn_create'             => 'Criar perfil',
    'btn_save'               => 'Salvar alterações',
    'required'               => 'obrigatório',

    // Retorno das ações (flash)
    'flash_created' => 'Perfil de acesso cadastrado com sucesso.',
    'flash_updated' => 'Perfil de acesso alterado com sucesso.',
    'flash_deleted' => 'Perfil de acesso excluído com sucesso.',

    // Validação (RoleRequest)
    'validation_name_unique'        => 'Já existe um perfil com este nome nesta clínica.',
    'validation_permissions_exists' => 'Uma ou mais permissões selecionadas são inválidas.',

    // Rótulos das permissões e grupos (chave = valor de App\Enums\Permission).
    // Sem tradução para uma chave, vale o label()/group() do enum.
    'permission_labels' => [
        'settings.manage'  => 'Gerenciar configurações',
        'users.manage'     => 'Gerenciar usuários',
        'roles.manage'     => 'Gerenciar perfis de acesso',
        'financial.view'   => 'Visualizar financeiro',
        'exams.import'     => 'Importar exames',
        'patients.manage'  => 'Gerenciar pacientes e médicos',
        'financial.manage' => 'Gerenciar financeiro e faturamento',
        'stock.manage'     => 'Gerenciar estoque',
    ],
    'permission_groups' => [
        'settings.manage'  => 'Configurações',
        'users.manage'     => 'Usuários',
        'roles.manage'     => 'Usuários',
        'financial.view'   => 'Financeiro',
        'exams.import'     => 'Pacientes',
        'patients.manage'  => 'Pacientes',
        'financial.manage' => 'Financeiro',
        'stock.manage'     => 'Estoque',
    ],
];
