<?php

declare(strict_types=1);

return [
    // Page
    'title'      => 'Gateways de Pagamento do EasyEye',
    'subtitle'   => 'Gateways da empresa dona do <strong>EasyEye</strong> para cobrar as clínicas: assinaturas dos planos e pacotes de créditos de IA.',
    'breadcrumb' => 'Gateways de Pagamento',

    // Default gateway banner
    'default_banner_title'    => 'Gateway Padrão do Sistema',
    'default_banner_subtitle' => '— usado para cobrar as assinaturas e os pacotes de créditos de IA das clínicas',
    'default_banner_change'   => 'Trocar',
    'no_default_title'        => 'Nenhum gateway padrão definido.',
    'no_default_subtitle'     => 'As cobranças de assinatura vão falhar até que um gateway padrão seja configurado.',
    'no_default_action'       => 'Definir agora',

    // Context card
    'ctx_saas_title' => 'Para que servem estes gateways',
    'ctx_saas_desc'  => 'As credenciais são da empresa dona do EasyEye: com elas o sistema cobra das clínicas as <strong>assinaturas</strong> e os <strong>pacotes de créditos de IA</strong>, e o dinheiro cai na conta do EasyEye. Cadastre em <strong>Credenciais</strong> e defina o padrão acima.',
    'ctx_saas_badge' => 'Credenciais globais do EasyEye',

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

    // Capabilities

    // O que o gateway faz hoje na EasyEye (métodos de capacidade da classe)
    'caps_title'                 => 'Na EasyEye hoje',
    'caps_transparent'           => 'Sem sair do EasyEye:',
    'caps_method'                => ['pix' => 'Pix', 'boleto' => 'Boleto', 'credit_card' => 'Cartão'],
    'caps_card_public_key_title' => 'Cartão transparente só com a chave pública cadastrada na credencial (no PagBank ela é opcional).',
    'caps_link_only'             => 'Só pelo link do gateway',
    'caps_link_only_title'       => 'A clínica paga na página do gateway (Pix e cartão de lá).',
    'caps_card_link'             => 'Cartão pelo link',
    'caps_card_link_title'       => 'A cobrança sem forma definida abre a página do gateway, que aceita cartão.',
    'caps_hosted_card'           => 'Cartão no ambiente seguro',
    'caps_hosted_card_title'     => 'O cartão é digitado na página hospedada do gateway (Asaas Checkout) e a clínica volta para o EasyEye; a fatura do plano vira assinatura no cartão.',
    'caps_refund'                => 'Estorno total pelo sistema',
    'caps_refund_title'          => 'O manager estorna pagamentos pagos pelo gateway (Assinaturas → detalhe → faturas).',
    'caps_refund_partial'        => 'Estorno total e parcial',
    'caps_refund_partial_title'  => 'O manager estorna pagamentos pagos pelo gateway, total ou parcial (Assinaturas → detalhe → faturas).',
    'health_title'               => 'Conexão com a API',
    'health_checked_at'          => 'conferido em :date',
    'health_status'              => [
        'ok'                   => 'Funcionando',
        'auth_error'           => 'Chave recusada',
        'environment_mismatch' => 'Chave de outro ambiente',
        'not_configured'       => 'Sem configuração',
        'rate_limited'         => 'Limite da API',
        'unreachable'          => 'Sem resposta',
        'config_only'          => 'Configurado',
    ],
    'caps_installments'            => 'Até :countx no cartão',
    'caps_installments_one'        => 'Cartão só à vista',
    'caps_installments_title'      => 'Teto do gateway; o checkout usa o menor entre este, o máximo configurado em Planos e os meses do ciclo.',
    'caps_saved_card'              => 'Renova no cartão salvo',
    'caps_saved_card_title'        => 'A renovação cobra o cartão guardado no gateway, sem a clínica digitar de novo.',
    'caps_card_replacement'        => 'Troca de cartão',
    'caps_card_replacement_title'  => 'A clínica troca o cartão da renovação sem ser cobrada (precisa da chave pública).',
    'caps_native_recurrence'       => 'Recorrência no gateway',
    'caps_native_recurrence_title' => 'O próprio gateway emite a cobrança de cada ciclo da assinatura.',
    'caps_local_renewal'           => 'Renovação pelo EasyEye',
    'caps_local_renewal_title'     => 'O EasyEye emite a cobrança de cada renovação neste gateway.',

    // Empty state
    'empty_state' => 'Nenhum gateway cadastrado.',

    // Footer buttons
    'btn_credentials'       => 'Credenciais',
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
    'modal_cred_alert'       => 'Estas credenciais são usadas pelo <strong>EasyEye</strong> para cobrar as clínicas pelas assinaturas e pelos pacotes de créditos de IA. A chave <strong>nunca é exibida</strong> após salvar. Ao cadastrar uma nova, a anterior é desativada automaticamente.',
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

    // Chave pública do checkout transparente (SDK do cartão no navegador)
    'modal_cred_public_key'         => 'Chave pública (checkout no navegador)',
    'modal_cred_public_key_ph'      => 'pk_… / APP_USR-… / chave pública',
    'modal_cred_public_key_hint'    => 'Usada pelo formulário seguro do cartão (Mercado Pago, Stripe, Pagar.me; no PagBank é opcional). É pública por definição — nunca cole aqui a chave secreta (sk_/rk_).',
    'modal_cred_public_key_current' => 'Chave pública: :key',
    'js_error_public_key_secret'    => 'Esta parece ser a chave secreta (sk_/rk_). Informe a chave pública.',

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
    'js_error_set_default' => 'Erro ao definir gateway padrão.',
    'js_error_generic'     => 'Erro.',
    'js_error_save'        => 'Erro ao salvar.',
    'js_error_load'        => 'Erro ao carregar.',

    // JS credential list labels (passed from view to JS)
    'js_no_label' => 'Sem rótulo',

    // Secret field labels per gateway (used in JS gatewaySecretLabels)
    'secret_label' => [
        'asaas'       => ['label' => 'API Key (access_token)', 'hint' => 'Chave de acesso da conta Asaas (começa com $aact_…)'],
        'infinitepay' => ['label' => 'Token (opcional)', 'hint' => 'O Checkout Integrado da InfinitePay não usa token — deixe vazio.'],
        'mercadopago' => ['label' => 'Access Token', 'hint' => 'Começa com APP_USR-…'],
        'pagarme'     => ['label' => 'Secret Key', 'hint' => 'Chave secreta da conta Pagar.me'],
        'stripe_br'   => ['label' => 'Secret Key', 'hint' => 'Começa com sk_live_… ou sk_test_…'],
        'pagbank'     => ['label' => 'Token de Acesso', 'hint' => 'Token Bearer do PagBank'],
    ],

    // InfinitePay: a credencial é a InfiniteTag (handle)
    'modal_cred_handle'      => 'InfiniteTag (handle)',
    'modal_cred_handle_ph'   => 'ex.: minhaloja',
    'modal_cred_handle_hint' => 'Sua InfiniteTag, sem o "$" — a mesma do app InfinitePay. O webhook não tem segredo: o pagamento é confirmado na própria InfinitePay.',
    'handle_required'        => 'Informe a InfiniteTag (handle) da conta InfinitePay.',
    'handle_invalid'         => 'InfiniteTag inválida: use só letras, números, ponto, hífen ou sublinhado (sem espaços).',
    'public_key_invalid'     => 'Chave pública em formato inválido. Esperado: :format.',
    'public_key_secret'      => 'Isto parece a chave secreta (ou é igual ao token). Informe a chave PÚBLICA do SDK JS — ela vai para o navegador.',
    'public_key_unsupported' => 'Este gateway não usa chave pública (sem cartão transparente).',
    'public_key_formats'     => [
        'mercadopago' => 'Public key "APP_USR-" ou "TEST-" seguida de um UUID',
        'pagarme'     => 'chave pública "pk_…" ou "pk_test_…"',
        'stripe_br'   => 'publishable key "pk_live_…" ou "pk_test_…"',
        'pagbank'     => 'chave pública RSA (PEM ou o texto "MII…")',
    ],
    'js_error_handle_required' => 'Informe a InfiniteTag (handle).',

    // Dica do segredo do webhook por gateway
    'webhook_hint' => [
        'asaas'       => 'Token de autenticação cadastrado no webhook do Asaas (32 a 255 caracteres), enviado no header asaas-access-token.',
        'infinitepay' => 'Não usado: a InfinitePay não assina o webhook.',
        'mercadopago' => 'Assinatura secreta das notificações Webhooks (Suas integrações → Webhooks).',
        'pagarme'     => 'Usuário e senha da autenticação do webhook no formato usuario:senha.',
        'stripe_br'   => 'Signing secret do endpoint de webhook (começa com whsec_).',
        'pagbank'     => 'Token da conta PagBank (o mesmo da chave de API) — valida o x-authenticity-token.',
    ],

    // Controller messages
    'set_default_success'    => ':name definido como gateway padrão do sistema.',
    'gateway_activated'      => 'Gateway ativado.',
    'gateway_deactivated'    => 'Gateway desativado.',
    'priority_updated'       => 'Prioridade atualizada.',
    'credential_saved'       => 'Credencial salva com sucesso. A credencial anterior foi desativada.',
    'credential_revoked'     => 'Credencial revogada.',
    'error_inactive_gateway' => 'O gateway precisa estar ativo para ser o padrão.',
    'error_no_credential'    => 'O gateway precisa ter ao menos uma credencial ativa.',
];
