<?php

// Saída dos comandos de cobrança para o time SaaS (billing:dunning e
// billing:reconcile-legacy). Só dados da assinatura e da empresa — nunca
// dado de paciente.
return [
    'trial_notices' => [
        'disabled'     => 'Avisos de fim do teste grátis desligados (BILLING_TRIAL_NOTICES_ENABLED=false).',
        'done'         => 'Avisos de fim do teste grátis enviados: :count',
        'dry_run_done' => 'Simulação: :count aviso(s) de fim do teste grátis sairiam. Nada foi gravado nem enviado.',
    ],

    'dunning' => [
        'disabled'                    => 'Régua de cobrança desligada (BILLING_DUNNING_ENABLED=false): nenhum aviso, encerramento ou cancelamento no gateway.',
        'done'                        => 'Etapas cumpridas: :count',
        'dry_run_done'                => 'Simulação: :count etapa(s) seriam cumpridas. Nada foi gravado, enviado ou cancelado.',
        'col_subscription'            => 'Assinatura',
        'col_entity'                  => 'Empresa',
        'col_step'                    => 'Etapa',
        'col_due_on'                  => 'Vencimento',
        'col_days_overdue'            => 'Dias de atraso',
        'col_action'                  => 'O que faria',
        'action_notify'               => 'Avisar :count destinatário(s)',
        'action_terminate'            => 'Encerrar a assinatura',
        'action_terminate_and_cancel' => 'Encerrar a assinatura e cancelar a recorrência no :gateway',
    ],

    'reconcile' => [
        'nothing'         => 'Nenhuma assinatura aguardando conciliação.',
        'dry_run'         => 'Simulação: nada foi gravado. Confira a tabela e rode de novo com --apply para gravar.',
        'applied'         => 'Conciliadas: :count. As linhas "Revisar manualmente" continuam marcadas (a vigente com acesso liberado) até a revisão.',
        'not_flagged'     => 'A assinatura :id não está aguardando conciliação.',
        'reason_required' => 'Informe o motivo com --reason (pelo menos 10 caracteres).',

        // Notas do relatório: como a régua trata o que foi conciliado.
        'note_past_due'   => 'Em atraso: a régua conta a partir da conciliação (D0 = dia do --apply), não do vencimento real: aviso de pagamento não identificado, acesso limitado no D+3 e encerramento no D+7 (com a recorrência cancelada no gateway). O aviso no painel aparece desde já; os e-mails saem com a régua ligada (BILLING_DUNNING_ENABLED=true) — ligue-a no mesmo dia do --apply. O vencimento real fica na coluna "Vencimento não pago" e no histórico (original_due_date).',
        'note_never_paid' => 'Contratação nunca paga com cobrança vencida: o prazo recomeça como numa contratação feita no dia do --apply (coluna "Próximo vencimento"). Até o fim desse dia o acesso segue, com o aviso de pagamento no painel; sem pagamento, o acesso acaba e a contratação é encerrada no D+7.',
        'note_superseded' => 'Linhas com "vigente: …" não são a assinatura vigente da empresa: nunca recebem a régua nem liberam acesso. Com a recorrência ativa no gateway, confira no gateway qual recorrência o cliente realmente paga ANTES de cancelar qualquer uma: se for a antiga, crie a nova assinatura a partir dela no manager; se for duplicada, use --close=ID --action=cancel.',
        'current_is'      => 'vigente: :id',

        // Encerramento da revisão manual (--close=ID --action=...).
        'action_required'     => 'Informe a saída da revisão com --action: "cancel" (cancela a assinatura e para a recorrência no gateway) ou "paid-until" com --until=AAAA-MM-DD (ativa, paga até o fim desse dia, e daí em diante segue o fluxo novo). Sem a saída, nada é alterado.',
        'until_required'      => 'Com --action=paid-until, informe --until=AAAA-MM-DD: o último dia já pago (hoje ou depois).',
        'close_gateway_error' => 'Não foi possível consultar o gateway para a linha :id: nada foi alterado. Rode de novo.',
        'until_past'          => 'A data de --until (:date) já passou: a clínica seria bloqueada na hora. Informe hoje ou uma data futura.',
        'not_current'         => 'A assinatura :id não é a vigente da empresa (a vigente é :current): ela não volta a valer. Use --action=cancel.',
        'orphan_found'        => 'Há recorrência no gateway criada com a referência da assinatura :id (:ids). Cancele-a no painel do gateway (ou concilie à mão) antes de encerrar a revisão.',
        'orphan_check_failed' => 'Não foi possível conferir no gateway se ficou recorrência criada para a assinatura :id (:error). Rode de novo.',
        'closed_cancel'       => 'Revisão encerrada: :id cancelada; o cancelamento da recorrência no gateway foi pedido.',
        'closed_paid_until'   => 'Revisão encerrada: :id ativa, paga até :date; daí em diante valem as regras novas.',

        'col_subscription' => 'Assinatura',
        'col_entity'       => 'Empresa',
        'col_gateway'      => 'Gateway',
        'col_status'       => 'Situação atual',
        'col_outcome'      => 'Resultado',
        'col_reason'       => 'Motivo',
        'col_terms'        => 'Ciclo · valor',
        'col_next_due'     => 'Próximo vencimento',
        'col_unpaid_due'   => 'Vencimento não pago',
        'col_days_overdue' => 'Dias de atraso',
        'col_last_payment' => 'Último pagamento',

        'outcome' => [
            'active'           => 'Ativa (em dia)',
            'past_due'         => 'Em atraso (régua a partir de hoje)',
            'awaiting_payment' => 'Aguardando 1º pagamento',
            'manual'           => 'Revisar manualmente',
            'error'            => 'Erro na consulta (rode de novo)',
        ],

        'reason' => [
            'in_good_standing'           => 'Recorrência ativa e em dia',
            'reactivated'                => 'Expirada pelo job antigo; recorrência ativa e em dia',
            'overdue'                    => 'Cobrança vencida sem pagamento',
            'overdue_never_paid'         => 'Contratação nunca paga com cobrança vencida; novo prazo a partir de hoje',
            'awaiting_first_payment'     => 'Contratação ainda sem pagamento; 1ª cobrança a vencer',
            'never_issued'               => 'Cobrança nunca emitida no gateway',
            'no_query_api'               => 'Gateway sem consulta da recorrência pela integração',
            'no_recurrence'              => 'Sem recorrência no gateway (renovação local)',
            'recurrence_not_found'       => 'Recorrência não existe no gateway',
            'recurrence_inactive'        => 'Recorrência inativa ou removida no gateway',
            'unsupported_cycle'          => 'Ciclo da recorrência não vendido pelo produto',
            'overdue_with_later_payment' => 'Cobrança vencida, mas há pagamento de cobrança posterior',
            'no_next_due'                => 'Recorrência sem próximo vencimento',
            'gateway_error'              => 'Falha ao consultar o gateway',
            'duplicated_recurrence'      => 'Recorrência duplicada: ativa no gateway, mas a empresa já tem outra assinatura vigente',
            'orphan_recurrence'          => 'Recorrência órfã no gateway (achada pela referência)',
        ],
    ],
];
