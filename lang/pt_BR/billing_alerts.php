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
];
