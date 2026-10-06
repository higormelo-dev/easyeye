<?php

// Alertas de cobrança para o time do SaaS (manager). Só dados da assinatura.
return [
    'greeting' => 'Olá, :name!',

    'recurrence_lost' => [
        'subject' => '[Alerta] :gateway desativou a recorrência da assinatura de :entity',
        'line'    => 'O :gateway desativou sozinho a cobrança recorrente da assinatura de :entity (plano :plan). A cobrança passou para a renovação pelo sistema: a próxima fatura é emitida pelo EasyEye e paga em Minha assinatura.',
        'access'  => 'A clínica não foi bloqueada: o acesso segue até o fim do período pago (:until).',
        'next'    => 'Próximo vencimento: :next. Sem pagamento, vale a régua de cobrança normal (lembrete, atraso, acesso limitado e encerramento).',
        'action'  => 'Abrir Assinaturas no manager',
        'hint'    => 'Confira no painel do gateway o motivo da desativação (ex.: cartão recusado várias vezes, assinatura removida).',
    ],

    // Alertas operacionais de gateway (GatewayAlertService).
    'gateway' => [
        'action' => 'Abrir Gateways no manager',

        'credential_rejected' => [
            'subject' => '[Alerta] :gateway recusou a chave de API (HTTP :status)',
            'line'    => 'O :gateway respondeu HTTP :status a uma chamada do EasyEye: a chave de API foi recusada. Detalhe: :detail',
            'hint'    => 'Causas comuns: chave expirada ou desabilitada por falta de uso, chave do sandbox em produção (ou o contrário), chave revogada ou o IP do servidor fora da whitelist. Cobranças e checkout falham até trocar a chave em Manager → Gateways.',
        ],
        'environment_mismatch' => [
            'subject' => '[Alerta] Chave do :gateway do ambiente errado',
            'line'    => 'A chave configurada do :gateway é de outro ambiente que a URL da API (sandbox × produção). Detalhe: :detail',
            'hint'    => 'Produção usa chave $aact_prod_ com https://api.asaas.com; sandbox usa $aact_hmlg_ com https://api-sandbox.asaas.com.',
        ],
        'health_failed' => [
            'subject' => '[Alerta] Health check do :gateway falhou (:status)',
            'line'    => 'A conferência diária do :gateway falhou (:status). Detalhe: :detail',
            'hint'    => 'Confira a configuração (URL e chave) em Manager → Gateways e no .env.',
        ],
        'access_token' => [
            'subject' => '[Alerta] Chave de API do :gateway: :event',
            'line'    => 'O :gateway avisou sobre a chave de API ":name": :event. Motivo: :reason. Expira: :expires.',
            'hint'    => 'Sem uso, a chave é desabilitada em 3 meses e expira em 6 (o health check diário mantém a chave em uso). Reative ou troque a chave no painel do gateway e em Manager → Gateways.',
        ],
        'recurrence_alignment' => [
            'subject' => '[Alerta] Ajustar o vencimento da recorrência :subscription no :gateway',
            'line'    => 'A assinatura no cartão de :entity (:subscription) nasceu com o próximo vencimento em :from, mas o período pago vai até :to. O EasyEye não conseguiu ajustar pela API.',
            'hint'    => 'Ajuste o próximo vencimento da assinatura no painel do gateway para :to (no cartão, a alteração pela API exige a tokenização habilitada na conta).',
        ],
        'recurrence_diverged' => [
            'subject' => '[Alerta] Recorrência :subscription alterada no :gateway',
            'line'    => 'A recorrência de :entity (:subscription) foi alterada direto no :gateway e diverge da assinatura no EasyEye (:fields).',
            'hint'    => 'O que vale é o contratado no EasyEye: desfaça a alteração no painel do gateway ou ajuste a assinatura pelo manager.',
        ],
        'refund_credits' => [
            'subject' => '[Alerta] Estorno parcial do pacote de IA :reference sem saldo de créditos',
            'line'    => 'O estorno parcial do pacote de créditos de IA :reference de :entity pedia retirar créditos que a clínica já usou: :revoked retirados, :shortfall não puderam ser retirados.',
            'hint'    => 'Confira com a clínica e ajuste a carteira de créditos pelo manager, se for o caso.',
        ],
        'checkout_terms_changed' => [
            'subject' => '[Alerta] Checkout de cartão de :entity pago com os termos anteriores à troca de plano',
            'line'    => 'O checkout de cartão de :entity foi pago depois da troca de plano, nos termos antigos (:amount, ciclo :cycle). A recorrência no cartão que ele criou (:subscription) foi desfeita e a recorrência vigente (termos novos) foi mantida.',
            'hint'    => 'O pagamento da fatura vale normalmente. Confira com a clínica a forma de pagamento das próximas faturas.',
        ],
        'card_reregister' => [
            'subject' => '[Aviso] Recorrência de :entity refeita sem o cartão na troca de plano',
            'line'    => 'A troca de plano de :entity refez a recorrência no :gateway (:subscription), mas sem o cartão: a API não cria a assinatura no cartão sem os dados dele.',
            'hint'    => 'A clínica foi avisada em Minha assinatura para pagar a próxima fatura com cartão (o que volta a recorrência para o cartão). Até lá, as faturas saem com boleto/Pix/cartão pela fatura.',
        ],
        'dunning_check_failed' => [
            'subject' => '[Alerta] Régua parada: não foi possível conferir o pagamento de :entity no :gateway',
            'line'    => 'A etapa :step da régua de :entity foi adiada :count vezes porque o :gateway não respondeu de forma conclusiva (:detail). A régua não limita nem encerra sem conferir.',
            'hint'    => 'Confira o pagamento no painel do gateway e a chave/conexão em Manager → Gateways. Pago: registre; não pago: a régua segue quando a conferência voltar a funcionar.',
        ],
        'refund_unconfirmed' => [
            'subject' => '[Alerta] Estorno de :amount segue solicitado sem confirmação no :gateway',
            'line'    => 'O estorno de R$ :amount (cobrança :payment) está como solicitado há tempo demais e não pôde ser conferido pela API do :gateway.',
            'hint'    => 'Confira no painel do gateway se o estorno foi feito e use "Conferir" no detalhe da assinatura.',
        ],
    ],
];
