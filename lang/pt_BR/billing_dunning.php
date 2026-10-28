<?php

// Régua de cobrança — e-mails à clínica (comando billing:dunning). Só dados
// da assinatura (empresa, valor, datas, link de pagamento) — nunca dado de
// paciente.
return [
    'greeting'            => 'Olá, :name!',
    'salutation'          => 'Equipe :app',
    'pay_now'             => 'Pagar agora',
    'contact'             => 'Falar com a nossa equipe',
    'manage_subscription' => 'Abrir Minha assinatura',
    // Lembrete antes do vencimento.
    'paid_note' => 'Se o pagamento já foi feito, não é preciso fazer mais nada: a confirmação pode levar até 3 dias úteis.',
    // Atraso, acesso limitado e 1ª cobrança vencida: o bloqueio não espera a
    // confirmação (que pode levar dias úteis), mas é desfeito sozinho com ela.
    'paid_note_overdue' => 'Se o pagamento já foi feito, a confirmação pode levar até 3 dias úteis e, até ela chegar, os bloqueios informados acima valem normalmente. Assim que o pagamento for confirmado, o acesso volta automaticamente.',
    // Aviso sem link da cobrança daquele vencimento: sem botão "Pagar agora".
    'no_link' => 'O link de pagamento desta cobrança não está disponível neste e-mail. Para pagar, fale com a nossa equipe.',

    'reminder' => [
        'subject' => 'Sua assinatura do :app vence em :date',
        'line'    => 'A próxima cobrança da assinatura de :entity, no valor de :amount, vence em :date.',
        // Sem a cobrança do vencimento emitida (ou sem link na resposta do gateway).
        'no_link'      => 'O link de pagamento desta cobrança ainda não está disponível. Para pagar até o vencimento, fale com a nossa equipe.',
        'card'         => 'Vamos cobrar :amount no cartão :brand final :last4 em :date, automaticamente — não é preciso fazer nada.',
        'card_in_full' => 'Atenção: a renovação no cartão é cobrada à vista (o parcelamento vale só para a contratação).',
        'card_change'  => 'Para trocar o cartão ou pagar de outra forma antes do vencimento, acesse Minha assinatura no painel.',
    ],

    'overdue' => [
        'subject'  => 'Não identificamos o pagamento da assinatura do :app',
        'line'     => 'Não identificamos o pagamento da cobrança de :amount da assinatura de :entity, que venceu em :date.',
        'deadline' => 'Em :limited_date, IA e módulo financeiro serão bloqueados; em :blocked_date, o acesso ao painel será suspenso.',
        'retry'    => 'Você pode pagar agora pelo link abaixo.',
    ],

    'limited' => [
        'subject'  => 'Acesso limitado: pagamento da assinatura do :app em atraso',
        'line'     => 'O pagamento de :amount da assinatura de :entity, vencido em :date, segue em aberto. Por isso, IA e módulo financeiro estão bloqueados; agenda, pacientes e prontuário seguem liberados.',
        'deadline' => 'Sem o pagamento, o acesso ao painel será suspenso em :blocked_date e a assinatura será encerrada.',
    ],

    'terminated' => [
        'subject' => 'Assinatura do :app encerrada por falta de pagamento',
        'line'    => 'A assinatura de :entity foi encerrada porque o pagamento de :amount, vencido em :date, não foi identificado.',
        'next'    => 'Para voltar a usar o :app, faça uma nova contratação com a nossa equipe.',
    ],

    'first_charge_overdue' => [
        'subject' => 'A 1ª cobrança da assinatura do :app venceu',
        'line'    => 'A 1ª cobrança da assinatura de :entity, no valor de :amount, venceu em :date sem pagamento identificado, e o acesso ao painel foi suspenso.',
        'retry'   => 'O acesso volta assim que o pagamento for confirmado. Sem o pagamento, a contratação será cancelada em :terminate_date.',
    ],

    'first_charge_terminated' => [
        'subject' => 'Contratação do :app cancelada por falta de pagamento',
        'line'    => 'A contratação da assinatura de :entity foi cancelada porque a 1ª cobrança, de :amount, vencida em :date, não foi paga.',
        'next'    => 'Para contratar de novo, fale com a nossa equipe.',
    ],

    // Encerramento: o que de fato aconteceu com as cobranças (DunningService::stopCharges).
    'charges' => [
        'recurrence_cancelled'  => 'A cobrança recorrente foi cancelada no meio de pagamento: nenhuma nova cobrança será gerada. Se ainda tiver um boleto/Pix desta assinatura em aberto, não o pague — o pagamento não reativa a assinatura.',
        'recurrence_cancelling' => 'Pedimos ao meio de pagamento o cancelamento da cobrança recorrente, e ele está em andamento. Se chegar alguma nova cobrança desta assinatura, não a pague — o pagamento não reativa a assinatura.',
        'open_charge'           => 'O boleto/Pix já emitido não pode ser cancelado automaticamente: não o pague, porque o pagamento não reativa a assinatura.',
        'open_charge_cancelled' => 'O boleto/Pix já emitido foi cancelado no meio de pagamento e nenhuma nova cobrança será emitida.',
        'none'                  => 'Nenhuma nova cobrança será emitida.',
    ],
];
