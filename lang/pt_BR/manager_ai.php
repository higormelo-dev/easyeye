<?php

return [
    // Página
    'title'      => 'Provedores de IA',
    'subtitle'   => 'Defina quais provedores o sistema pode usar e a ordem de prioridade. Os modos disponíveis (Economia, Validado, Consenso) escalam com o número de provedores ativos.',
    'breadcrumb' => 'Provedores de IA',

    // Colunas / rótulos
    'provider'           => 'Provedor',
    'model'              => 'Modelo',
    'enabled'            => 'Ativo',
    'priority'           => 'Prioridade',
    'configured'         => 'Configurado',
    'not_configured'     => 'Sem credencial',
    'move_up'            => 'Subir prioridade',
    'move_down'          => 'Descer prioridade',
    'no_credential_hint' => 'Defina a API key no ambiente (.env) para poder ativar.',

    // Papéis derivados da ordem
    'role_generator'   => 'Gerador',
    'role_reviewer'    => 'Revisor',
    'role_adjudicator' => 'Adjudicador',

    // Modos
    'available_modes' => 'Modos disponíveis',
    'mode_economy'    => 'Economia',
    'mode_validated'  => 'Validado',
    'mode_consensus'  => 'Consenso',
    'mode_needs'      => 'requer :n provedor(es)',

    // Ações / mensagens
    'save'               => 'Salvar',
    'saved'              => 'Provedores de IA atualizados.',
    'audit_reason'       => 'Atualização dos provedores de IA habilitados (área administrativa).',
    'error_empty'        => 'Habilite ao menos um provedor de IA.',
    'error_unconfigured' => 'Não é possível habilitar provedor(es) sem credencial: :providers.',
];
