<?php

declare(strict_types=1);

return [
    // Page
    'title'      => 'Gateways de Pagamento',
    'subtitle'   => 'Gerencie os gateways usados pelo <strong>EasyEye</strong> para cobrar assinaturas e controle quais gateways cada clínica pode usar para receber pagamentos dos pacientes.',
    'breadcrumb' => 'Gateways de Pagamento',

    // Default gateway banner
    'default_banner_title'    => 'Gateway Padrão do Sistema',
    'default_banner_subtitle' => '— usado para cobrar todas as assinaturas de planos',
    'default_banner_change'   => 'Trocar',
    'no_default_title'        => 'Nenhum gateway padrão definido.',
    'no_default_subtitle'     => 'As cobranças de assinatura vão falhar até que um gateway padrão seja configurado.',
    'no_default_action'       => 'Definir agora',

    // Context cards
    'ctx_saas_title'   => 'SaaS Billing — cobrar clínicas',
    'ctx_saas_desc'    => 'Credenciais usadas pelo EasyEye para cobrar mensalidades. Configure via <strong>Credenciais</strong> e defina o padrão acima.',
    'ctx_saas_badge'   => 'Credenciais globais no banco',
    'ctx_tenant_title' => 'Tenant Payment — clínicas recebem de pacientes',
    'ctx_tenant_desc'  => 'Cada clínica configura suas próprias credenciais. Habilite o acesso via <strong>Acesso por Clínica</strong>.',
    'ctx_tenant_badge' => 'Credenciais isoladas por clínica',

    // Gateway card
    'default_badge'       => 'Padrão',
    'status_active'       => 'Ativo',
    'status_inactive'     => 'Inativo',
    'toggle_deactivate'   => 'Desativar',
    'toggle_activate'     => 'Ativar',
    'priority_label'      => 'Prioridade:',
    'priority_change'     => 'alterar',
    'billing_credentials' => 'Credenciais de billing',
    'credentials_active'  => '{1} 1 ativa|[2,*] :count ativas',
    'credentials_none'    => 'Sem credencial',
    'clinics_with_access' => 'Clínicas com acesso',
    'clinics_none'        => 'Nenhuma',
    'clinics_count'       => '{1} 1 clínica|[2,*] :count clínicas',

    // Capabilities
    'cap_subscriptions' => 'Assinaturas',
    'cap_one_time'      => 'Cobranças avulsas',
    'cap_refunds'       => 'Reembolsos',
    'cap_webhooks'      => 'Webhooks',

    // Footer buttons
    'btn_credentials'       => 'Credenciais',
    'btn_entity_access'     => 'Acesso por Clínica',
    'btn_set_default'       => 'Definir como Padrão',
    'btn_current_default'   => 'Gateway Padrão Atual',
    'btn_activate_first'    => 'Ative o gateway primeiro',
    'btn_add_credential'    => 'Cadastre uma credencial primeiro',
    'btn_set_default_title' => 'Definir como gateway padrão do sistema',

    // Modal: Change default
    'modal_default_title'       => 'Gateway Padrão do Sistema',
    'modal_default_alert'       => 'O gateway padrão é usado para <strong>todas as cobranças de assinaturas</strong> de planos quando não há preferência específica de gateway definida. Só gateways <strong>ativos com credencial ativa</strong> podem ser o padrão.',
    'modal_default_current'     => 'Atual',
    'modal_default_btn'         => 'Definir',
    'modal_default_unavailable' => 'Indisponível',
    'modal_default_close'       => 'Fechar',

    // Modal: Credentials
    'modal_cred_title'       => 'Credenciais de Billing',
    'modal_cred_alert'       => 'Estas credenciais são usadas pelo <strong>EasyEye</strong> para cobrar as clínicas pelas assinaturas. A chave <strong>nunca é exibida</strong> após salvar. Ao cadastrar uma nova, a anterior é desativada automaticamente.',
    'modal_cred_history'     => 'Histórico de credenciais',
    'modal_cred_loading'     => '',
    'modal_cred_empty'       => 'Nenhuma credencial cadastrada ainda.',
    'modal_cred_new'         => 'Nova credencial',
    'modal_cred_label'       => 'Rótulo',
    'modal_cred_label_ph'    => 'Ex.: Produção — rotação abr/2026',
    'modal_cred_api_key'     => 'Chave de API',
    'modal_cred_api_key_ph'  => 'Cole a chave aqui',
    'modal_cred_webhook'     => 'Webhook Secret',
    'modal_cred_webhook_opt' => '(opcional)',
    'modal_cred_valid_from'  => 'Válida a partir de',
    'modal_cred_valid_to'    => 'Válida até',
    'modal_cred_save'        => 'Salvar credencial',
    'modal_cred_close'       => 'Fechar',
    'modal_cred_active'      => 'Ativa',
    'modal_cred_inactive'    => 'Inativa',
    'modal_cred_hidden'      => 'chave oculta',
    'modal_cred_revoke'      => 'Revogar',

    // Modal: Entity access
    'modal_ea_title'     => 'Acesso por Clínica',
    'modal_ea_alert'     => 'Habilite este gateway para as clínicas que poderão configurar suas próprias credenciais e receber pagamentos de pacientes. A clínica só verá o gateway no painel dela após ser habilitada aqui.',
    'modal_ea_search_ph' => 'Filtrar por nome ou código da clínica…',
    'modal_ea_loading'   => 'Carregando clínicas…',
    'modal_ea_empty'     => 'Nenhuma clínica encontrada.',
    'modal_ea_close'     => 'Fechar',
    'modal_ea_enable'    => 'Habilitar',
    'modal_ea_disable'   => 'Desabilitar',

    // Modal: Priority
    'modal_priority_title'  => 'Prioridade de Fallback',
    'modal_priority_desc'   => 'Menor valor = maior prioridade no fallback automático. Não afeta o gateway padrão (definido explicitamente acima).',
    'modal_priority_save'   => 'Salvar',
    'modal_priority_cancel' => 'Cancelar',

    // JS confirm messages
    'js_confirm_set_default'       => 'Definir ":name" como gateway padrão do sistema para cobrança de assinaturas?',
    'js_confirm_set_default_modal' => 'Definir ":name" como gateway padrão?',
    'js_confirm_revoke'            => 'Revogar esta credencial? Esta ação não pode ser desfeita.',

    // JS error fallbacks
    'js_error_set_default'  => 'Erro ao definir gateway padrão.',
    'js_error_generic'      => 'Erro.',
    'js_error_save'         => 'Erro ao salvar.',
    'js_error_load'         => 'Erro ao carregar.',
    'js_error_load_clinics' => 'Erro ao carregar clínicas.',

    // JS credential list labels (passed from view to JS)
    'js_no_label' => 'Sem rótulo',

    // Secret field labels per gateway (used in JS gatewaySecretLabels)
    'secret_label' => [
        'asaas'       => ['label' => 'API Key (access_token)', 'hint' => 'Chave de acesso da conta Asaas (começa com $aact_…)'],
        'infinitepay' => ['label' => 'Bearer Token', 'hint' => 'Token OAuth da InfinitePay'],
        'mercadopago' => ['label' => 'Access Token', 'hint' => 'Começa com APP_USR-…'],
        'pagarme'     => ['label' => 'Secret Key', 'hint' => 'Chave secreta da conta Pagar.me'],
        'stripe_br'   => ['label' => 'Secret Key', 'hint' => 'Começa com sk_live_… ou sk_test_…'],
        'pagbank'     => ['label' => 'Token de Acesso', 'hint' => 'Token Bearer do PagBank'],
    ],

    // Controller messages
    'set_default_success'    => ':name definido como gateway padrão do sistema.',
    'gateway_activated'      => 'Gateway ativado.',
    'gateway_deactivated'    => 'Gateway desativado.',
    'priority_updated'       => 'Prioridade atualizada.',
    'credential_saved'       => 'Credencial salva com sucesso. A credencial anterior foi desativada.',
    'credential_revoked'     => 'Credencial revogada.',
    'saas_entity_forbidden'  => 'A entidade SaaS não pode ser configurada aqui.',
    'gateway_enabled_for'    => ':gateway habilitado para :entity.',
    'gateway_disabled_for'   => ':gateway desabilitado para :entity.',
    'error_inactive_gateway' => 'O gateway precisa estar ativo para ser o padrão.',
    'error_no_credential'    => 'O gateway precisa ter ao menos uma credencial ativa.',
];
