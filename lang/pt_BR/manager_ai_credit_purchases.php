<?php

return [
    'title'    => 'Créditos de IA',
    'subtitle' => 'Dois trabalhos: recarregar suas contas nos provedores (o que você gasta) e distribuir créditos às clínicas (o que você entrega).',

    'tabs' => [
        'clients'   => 'Créditos das clínicas',
        'providers' => 'Recargas nos provedores',
    ],

    // Explicação curta no topo de cada aba, deixando claro que são trabalhos distintos.
    'tab_help' => [
        'clients'   => 'Conceda cortesia ou registre uma compra paga direto na carteira de uma clínica.',
        'providers' => 'Registre quanto você carregou em cada provedor de IA (OpenAI/Claude/Gemini) para acompanhar o saldo restante.',
    ],

    // Faixa de resumo sempre visível, ligando os dois trabalhos.
    'summary' => [
        'providers_balance'      => 'Saldo nos provedores',
        'providers_balance_help' => 'estimado restante (USD)',
        'providers_no_data'      => 'sem recarga registrada',
        'distributed'            => 'Distribuído no mês',
        'distributed_help'       => 'créditos entregues às clínicas',
        'courtesy'               => 'cortesia',
        'paid'                   => 'compra',
        'margin'                 => 'Margem bruta (mês)',
        'margin_help'            => 'receita estimada − custo dos provedores',
        'alert'                  => [
            'ok'        => 'OK',
            'warning'   => 'Atenção',
            'critical'  => 'Crítico',
            'exhausted' => 'Esgotado',
        ],
    ],

    // Aviso discreto: pedidos de clientes aguardando aprovação (fluxo pouco usado).
    'pending_alert' => [
        'title'  => ':count pedido(s) de cliente aguardando aprovação',
        'body'   => 'Pedidos iniciados pelo cliente no app. Abra para aprovar, cancelar ou marcar como falha.',
        'action' => 'Ver pendentes',
    ],

    // Badge que classifica cada linha da tabela de créditos.
    'kind' => [
        'courtesy' => 'Cortesia',
        'purchase' => 'Compra avulsa',
        'client'   => 'Pedido do cliente',
    ],

    'providers_empty' => 'Registre uma recarga para acompanhar o saldo dos provedores.',

    'kpi' => [
        'pending'                 => 'Pendentes',
        'pending_help'            => 'Aguardando aprovação manual',
        'credited_30d'            => 'Creditados (30 dias)',
        'credited_30d_help'       => 'Receita realizada no período',
        'credits_sold'            => 'créditos vendidos',
        'conversion'              => 'Taxa de conversão (30d)',
        'conversion_help'         => 'Pedidos que viraram receita',
        'abandonment'             => 'Cancelados/Falhados (30d)',
        'top_consumers'           => 'Top 5 clínicas (30 dias)',
        'no_consumers'            => 'Nenhum consumo no período.',
        'consumption_by_provider' => 'Consumo por provedor (30d)',
        'no_consumption'          => 'Sem consumo nos últimos 30 dias',
    ],

    'provider_costs' => [
        'title'             => 'Custo do EasyEye nos provedores (lado supplier)',
        'subtitle'          => 'Quanto vocês gastam em USD nas APIs',
        'alerts_setup'      => 'Alertas externos',
        'mtd'               => 'Mês até hoje',
        'last_7d'           => 'Últimos 7 dias',
        'yesterday'         => 'Ontem',
        'forecast'          => 'Forecast mensal',
        'forecast_help'     => 'extrapolação dos últimos 7 dias',
        'estimated_balance' => 'Saldo estimado',
        'days_left'         => 'dias',
        'no_topups'         => 'Registre uma recarga para ver o saldo estimado',
        'recent_topups'     => 'Últimas recargas',
        'margin'            => [
            'title'   => 'Margem bruta (mês)',
            'revenue' => 'Receita estimada',
            'cost'    => 'Custo provedores',
            'gross'   => 'Lucro bruto',
        ],
        'checklist' => [
            'title'    => 'Configure alertas no painel dos provedores',
            'subtitle' => 'O EasyEye não consegue recarregar saldo automaticamente — você precisa configurar limites/alertas em cada provedor.',
        ],
    ],

    'topup' => [
        'add'                   => 'Registrar recarga',
        'modal_title'           => 'Registrar recarga no provedor',
        'modal_subtitle'        => 'Registre o valor que você carregou no painel do provedor. NÃO faz cobrança real — apenas atualiza o saldo estimado.',
        'provider'              => 'Provedor',
        'amount'                => 'Valor (USD)',
        'amount_brl'            => 'Valor pago (R$)',
        'amount_brl_help'       => 'O que você pagou no cartão/fatura (com IOF/spread).',
        'amount_usd'            => 'Creditado no provedor (US$)',
        'amount_usd_help'       => 'Saldo que o provedor adicionou — alimenta o saldo estimado.',
        'effective_rate'        => 'Cotação efetiva:',
        'rate_warning'          => 'Cotação fora do esperado — confira se não inverteu os campos R$ e US$.',
        'topped_up_at'          => 'Data da recarga',
        'reference'             => 'Referência (opcional)',
        'reference_placeholder' => 'Ex.: ch_3MtIxhXKt8, invoice #INV-001',
        'reference_help'        => 'ID/comprovante da transação no painel do provedor — útil para auditoria.',
        'note'                  => 'Observação (opcional)',
        'submit'                => 'Registrar recarga',
        'cancel'                => 'Cancelar',
    ],

    'internal_wallet' => [
        'title'                => 'Sua empresa — consumo interno',
        'subtitle'             => 'Saldo da entidade administrativa do SaaS para uso próprio.',
        'available'            => 'Disponível agora',
        'includes_quota'       => 'cota mensal + créditos comprados',
        'quota'                => 'Cota mensal',
        'quota_expired'        => 'expirada',
        'resets_at'            => 'Reseta em',
        'balance'              => 'Comprados (não expiram)',
        'reserved'             => 'reservados',
        'consumed_by_provider' => 'Consumo histórico por provedor',
        'lifetime_purchased'   => 'Comprado total',
        'lifetime_consumed'    => 'Consumido total',
        'add_credit'           => 'Adicionar créditos',
    ],

    'filters' => [
        'title'     => 'Filtros',
        'apply'     => 'Filtrar',
        'status'    => 'Status',
        'provider'  => 'Provedor',
        'entity'    => 'Empresa',
        'date_from' => 'De',
        'date_to'   => 'Até',
        'q'         => 'Buscar por nome da empresa…',
        'clear'     => 'Limpar filtros',
        'all'       => 'Todos',
    ],

    'columns' => [
        'created_at'   => 'Solicitado em',
        'entity'       => 'Empresa',
        'package'      => 'Tipo',
        'provider'     => 'Provedor',
        'credits'      => 'Créditos',
        'amount'       => 'Valor',
        'requested_by' => 'Solicitante',
        'status'       => 'Status',
        'actions'      => 'Ações',
    ],

    'actions' => [
        'view'           => 'Detalhes',
        'credit'         => 'Aprovar e creditar',
        'cancel'         => 'Cancelar pedido',
        'fail'           => 'Marcar como falha de gateway',
        'refund'         => 'Reembolsar (estornar créditos)',
        'create_manual'  => 'Conceder crédito à clínica',
        'create_topup'   => 'Registrar recarga no provedor',
        'credited'       => 'Créditos aprovados e creditados na carteira da empresa.',
        'cancelled'      => 'Pedido cancelado.',
        'marked_failed'  => 'Pedido marcado como falha de pagamento.',
        'refunded'       => 'Créditos estornados da carteira da empresa.',
        'manual_created' => 'Créditos manuais lançados com sucesso.',
        'topup_created'  => 'Recarga do provedor registrada com sucesso.',
        'topup_deleted'  => 'Registro de recarga removido.',
    ],

    'manual' => [
        'package_label'      => 'Manual / Cortesia',
        'modal_title'        => 'Conceder crédito a uma clínica',
        'modal_subtitle'     => 'O crédito vai direto para a carteira da clínica, sem gateway. Cortesia é grátis; compra registra um valor cobrado fora do app.',
        'select_entity'      => 'Clínica destinatária',
        'select_entity_help' => 'Selecione a clínica que receberá os créditos. Sua empresa aparece destacada com ★.',
        'kind'               => 'Como conceder',
        'kind_courtesy'      => 'Cortesia (grátis)',
        'kind_purchase'      => 'Compra (paga)',
        'kind_courtesy_help' => 'Crédito gratuito — sem valor financeiro. Use para brindes, testes ou ajuste.',
        'kind_purchase_help' => 'A clínica pagou fora do app (PIX, boleto, cartão). Informe o valor recebido.',
        'credits'            => 'Quantidade de créditos',
        'credits_help'       => 'Vão para o saldo único da clínica (não expiram).',
        'amount_reais'       => 'Valor recebido (R$)',
        'amount_reais_help'  => 'Quanto a clínica pagou por estes créditos.',
        'reason'             => 'Motivo (obrigatório)',
        'reason_help'        => 'Será registrado na trilha de auditoria. Mínimo 10 caracteres.',
        'submit'             => 'Conceder crédito',
        'cancel'             => 'Cancelar',
        'badge_internal'     => 'Sua empresa',
        'badge_client'       => 'Cliente',
        'limit_warning'      => 'Você é Support. Limite diário: :limit créditos (cortesia + compra). Usado hoje: :used.',
    ],

    'confirm' => [
        'credit_title' => 'Aprovar e creditar?',
        'credit_body'  => 'Os :credits créditos serão adicionados à carteira da clínica imediatamente. Esta ação fica registrada na trilha de auditoria.',
        'cancel_title' => 'Cancelar pedido pendente?',
        'cancel_body'  => 'A clínica não poderá pagar este pedido depois. Informe o motivo para a trilha de auditoria.',
        'fail_title'   => 'Marcar como falha de pagamento?',
        'fail_body'    => 'Use quando o gateway recusou a cobrança (cartão negado, etc.). Diferente de cancelado para métricas de funil.',
        'refund_title' => 'Reembolsar e estornar créditos?',
        'refund_body'  => 'Os :credits créditos serão DEBITADOS da carteira da clínica. Se ela já consumiu parte, o saldo pode ficar NEGATIVO — você precisará cobrar o débito ou absorvê-lo manualmente. Esta ação é destrutiva e fica permanentemente na auditoria.',
        'reason'       => 'Motivo (obrigatório, registrado na auditoria)',
        'reason_min'   => 'Descreva o motivo em pelo menos 5 caracteres.',
    ],

    'detail' => [
        'tab_info'        => 'Informações',
        'tab_timeline'    => 'Linha do tempo',
        'tab_metadata'    => 'Metadados',
        'package_code'    => 'Código do pacote',
        'idempotency_key' => 'Chave de idempotência',
        'subscription'    => 'Assinatura vinculada',
        'wallet_balance'  => 'Saldo atual da carteira',
    ],

    'timeline' => [
        'created'   => 'Pedido criado',
        'credited'  => 'Creditado',
        'cancelled' => 'Cancelado',
        'failed'    => 'Falha de gateway',
        'refunded'  => 'Reembolsado',
    ],

    'errors' => [
        'ai_credit_purchase_not_creditable'  => 'Pedido não está em estado pendente — não pode ser creditado.',
        'ai_credit_purchase_not_cancellable' => 'Pedido não está em estado pendente — não pode ser cancelado.',
        'ai_credit_purchase_not_failable'    => 'Pedido não está em estado pendente — não pode ser marcado como falha.',
        'ai_credit_purchase_not_refundable'  => 'Pedido não está creditado — não há nada para estornar.',
        'ai_credit_purchase_invalid_credits' => 'A quantidade de créditos precisa ser maior que zero.',
        'manual_internal_admin_only'         => 'Apenas administradores SaaS podem lançar créditos para a sua própria empresa.',
        'support_daily_limit_exceeded'       => 'Limite diário de :limit créditos atingido. Já lançou :used hoje.',
        'forbidden_role'                     => 'Seu perfil SaaS não tem permissão para esta ação.',
    ],

    'empty' => 'Nenhum pedido de compra de créditos IA encontrado para os filtros aplicados.',
];
