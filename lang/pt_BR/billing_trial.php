<?php

// Fim do teste grátis — avisos à clínica (comando billing:trial-notices), por
// e-mail e WhatsApp. Só nome da clínica e datas — nunca dado de paciente.
return [
    'greeting'   => 'Olá, :name!',
    'salutation' => 'Equipe :app',
    'cta'        => 'Contratar agora',
    'after'      => 'Quando o teste terminar, o acesso ao painel fica suspenso até a contratação — seus dados continuam guardados.',
    'paid_note'  => 'Se você já contratou, desconsidere este aviso.',

    'three_days' => [
        'subject' => 'Seu teste grátis do :app termina em :days dias',
        'line'    => 'O teste grátis de :entity termina em :date. Para continuar usando o :app sem interrupção, contrate um plano pelo painel, em Minha assinatura.',
    ],
    'one_day' => [
        'subject' => 'Seu teste grátis do :app termina amanhã',
        'line'    => 'O teste grátis de :entity termina amanhã, :date. Contrate um plano pelo painel, em Minha assinatura, para não perder o acesso.',
    ],
    'today' => [
        'subject' => 'Seu teste grátis do :app termina hoje',
        'line'    => 'O teste grátis de :entity termina hoje, :date. Contrate um plano pelo painel, em Minha assinatura, para continuar usando o :app.',
    ],
];
