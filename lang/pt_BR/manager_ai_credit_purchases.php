<?php

return [
    'title'    => 'Compras de créditos IA',
    'subtitle' => 'Gerencie pedidos de compra de créditos IA das clínicas — aprovar, cancelar, marcar falha ou reembolsar.',

    'kpi' => [
        'pending'          => 'Pendentes',
        'pending_help'     => 'Aguardando aprovação manual',
        'credited_30d'     => 'Creditados (30 dias)',
        'credited_30d_help' => 'Receita realizada no período',
        'credits_sold'     => 'créditos vendidos',
        'conversion'       => 'Taxa de conversão (30d)',
        'conversion_help'  => 'Pedidos que viraram receita',
        'abandonment'      => 'Cancelados/Falhados (30d)',
        'top_consumers'    => 'Top 5 clínicas (30 dias)',
        'no_consumers'     => 'Nenhum consumo no período.',
    ],

    'filters' => [
        'status'    => 'Status',
        'entity'    => 'Clínica',
        'date_from' => 'De',
        'date_to'   => 'Até',
        'q'         => 'Buscar por nome da clínica…',
        'clear'     => 'Limpar filtros',
        'all'       => 'Todos',
    ],

    'columns' => [
        'created_at'  => 'Solicitado em',
        'entity'      => 'Clínica',
        'package'     => 'Pacote',
        'credits'     => 'Créditos',
        'amount'      => 'Valor',
        'requested_by' => 'Solicitante',
        'status'      => 'Status',
        'actions'     => 'Ações',
    ],

    'actions' => [
        'view'        => 'Detalhes',
        'credit'      => 'Aprovar e creditar',
        'cancel'      => 'Cancelar pedido',
        'fail'        => 'Marcar como falha de gateway',
        'refund'      => 'Reembolsar (estornar créditos)',
        'credited'    => 'Créditos aprovados e creditados na carteira da clínica.',
        'cancelled'   => 'Pedido cancelado.',
        'marked_failed' => 'Pedido marcado como falha de pagamento.',
        'refunded'    => 'Créditos estornados da carteira da clínica.',
    ],

    'confirm' => [
        'credit_title'  => 'Aprovar e creditar?',
        'credit_body'   => 'Os :credits créditos serão adicionados à carteira da clínica imediatamente. Esta ação fica registrada na trilha de auditoria.',
        'cancel_title'  => 'Cancelar pedido pendente?',
        'cancel_body'   => 'A clínica não poderá pagar este pedido depois. Informe o motivo para a trilha de auditoria.',
        'fail_title'    => 'Marcar como falha de pagamento?',
        'fail_body'     => 'Use quando o gateway recusou a cobrança (cartão negado, etc.). Diferente de cancelado para métricas de funil.',
        'refund_title'  => 'Reembolsar e estornar créditos?',
        'refund_body'   => 'Os :credits créditos serão DEBITADOS da carteira da clínica. Se ela já consumiu parte, o saldo pode ficar NEGATIVO — você precisará cobrar o débito ou absorvê-lo manualmente. Esta ação é destrutiva e fica permanentemente na auditoria.',
        'reason'        => 'Motivo (obrigatório, registrado na auditoria)',
        'reason_min'    => 'Descreva o motivo em pelo menos 5 caracteres.',
    ],

    'detail' => [
        'tab_info'         => 'Informações',
        'tab_timeline'     => 'Linha do tempo',
        'tab_metadata'     => 'Metadados',
        'package_code'     => 'Código do pacote',
        'idempotency_key'  => 'Chave de idempotência',
        'subscription'     => 'Assinatura vinculada',
        'wallet_balance'   => 'Saldo atual da carteira',
    ],

    'timeline' => [
        'created'    => 'Pedido criado',
        'credited'   => 'Creditado',
        'cancelled'  => 'Cancelado',
        'failed'     => 'Falha de gateway',
        'refunded'   => 'Reembolsado',
    ],

    'errors' => [
        'ai_credit_purchase_not_creditable'    => 'Pedido não está em estado pendente — não pode ser creditado.',
        'ai_credit_purchase_not_cancellable'   => 'Pedido não está em estado pendente — não pode ser cancelado.',
        'ai_credit_purchase_not_failable'      => 'Pedido não está em estado pendente — não pode ser marcado como falha.',
        'ai_credit_purchase_not_refundable'    => 'Pedido não está creditado — não há nada para estornar.',
        'forbidden_role'                       => 'Seu perfil SaaS não tem permissão para esta ação.',
    ],

    'empty' => 'Nenhum pedido de compra de créditos IA encontrado para os filtros aplicados.',
];
