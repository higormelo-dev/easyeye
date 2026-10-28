<?php

// Cobrança enviada pelo manager à clínica (e-mail + WhatsApp). Só plano,
// valor e vencimento — nunca dado de paciente. O link abre a fatura em
// Minha assinatura, dentro do sistema.
return [
    'greeting'   => 'Olá, :name!',
    'salutation' => 'Equipe :app',
    'subject'    => 'Cobrança da assinatura do :app: :amount',
    'line'       => 'Há uma cobrança em aberto da assinatura de :entity (plano :plan), no valor de :amount, com vencimento em :date.',
    // Diferença proporcional da troca de plano (upgrade).
    'line_plan_change' => 'A troca da assinatura de :entity para o plano :plan foi solicitada. O plano muda assim que a diferença de :amount (vencimento em :date) for paga.',
    'how'              => 'O pagamento é feito dentro do painel, em Minha assinatura, por Pix, boleto ou cartão.',
    'pay'              => 'Pagar no painel',
    'paid_note'        => 'Se o pagamento já foi feito, desconsidere este aviso.',
];
