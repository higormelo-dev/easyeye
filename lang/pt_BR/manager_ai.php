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

    // Papéis explícitos (painel dinâmico)
    'roles_title'                        => 'Papéis do assistente',
    'roles_subtitle'                     => 'Escolha qual provedor cumpre cada papel. A mudança vale IMEDIATAMENTE para todos os clientes — sem deploy e sem mexer em arquivos.',
    'role_primary'                       => 'Principal (gera a resposta)',
    'role_reviewer_title'                => 'Revisor (confere a resposta)',
    'role_adjudicator_title'             => 'Árbitro (desempata/consolida)',
    'role_primary_hint'                  => 'Obrigatório. Todo atendimento de IA começa por ele.',
    'role_reviewer_hint'                 => 'Opcional. Com revisor, o modo Validado fica disponível (2 chamadas).',
    'role_adjudicator_hint'              => 'Opcional. Exige revisor. Habilita o modo Consenso (3 chamadas).',
    'role_none'                          => '— Nenhum —',
    'price_missing'                      => 'Modelo sem preço cadastrado',
    'price_missing_hint'                 => 'Cadastre o preço do modelo (seeder de preços) antes de usar: sem preço, a execução falha após gastar no provedor.',
    'test_connection'                    => 'Testar conexão',
    'test_ok'                            => 'Conexão OK — credencial válida e provedor respondendo.',
    'test_failed'                        => 'Falha ao conectar com o provedor.',
    'test_unconfigured'                  => 'Provedor sem credencial/modelo configurados no servidor.',
    'error_adjudicator_without_reviewer' => 'Não é possível definir um árbitro sem definir um revisor.',
    'propagation_note'                   => 'As alterações entram em vigor imediatamente para todos os clientes (cache invalidado na hora).',
    'error_duplicate_role'               => 'Cada papel precisa de um provedor diferente.',
    'error_model_without_price'          => 'O modelo ":model" de :provider não tem preço cadastrado — escolha um modelo da lista.',
    'model_env_fallback'                 => 'Padrão do servidor (.env)',
    'model_hint'                         => 'Só aparecem modelos com preço cadastrado — garante a cobrança correta dos créditos.',
    'prices_title'       => 'Modelos e preços (catálogo)',
    'prices_subtitle'    => 'Cadastre aqui o preço público do modelo (USD por 1 milhão de tokens) e ele passa a aparecer no seletor de modelos — sem deploy.',
    'price_new'          => 'Novo modelo',
    'price_saved'        => 'Catálogo de modelos atualizado.',
    'price_duplicate'    => 'Este modelo já está cadastrado para o provedor — edite a linha existente.',
    'price_audit_reason' => 'Alteração no catálogo de modelos/preços de IA (área administrativa).',
    'price_input'        => 'Entrada (USD/1M tokens)',
    'price_output'       => 'Saída (USD/1M tokens)',
    'price_reasoning'    => 'Raciocínio (USD/1M, opcional)',
    'price_model_name'   => 'Nome do modelo (id oficial do provedor)',
    'price_model_hint'   => 'Exatamente como o provedor nomeia, ex.: gpt-4o-mini, claude-sonnet-4-5, gemini-2.0-flash.',
    'price_active'       => 'Ativo (elegível no seletor)',
    'edit'               => 'Editar',
    'cancel'             => 'Cancelar',
];
