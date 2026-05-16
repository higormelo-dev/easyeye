<?php

return [
    'access_blocked' => 'Sua assinatura está inativa. Renove para continuar usando o sistema.',

    'status' => [
        'trial'     => 'Trial',
        'active'    => 'Ativo',
        'expired'   => 'Expirado',
        'cancelled' => 'Cancelado',
        'past_due'  => 'Em atraso',
    ],

    'billing_cycle' => [
        'monthly'    => 'Mensal',
        'quarterly'  => 'Trimestral',
        'semiannual' => 'Semestral',
        'yearly'     => 'Anual',
        'lifetime'   => 'Vitalício',
    ],

    'expired_page' => [
        'title'           => 'Assinatura expirada',
        'heading'         => 'Sua assinatura está inativa',
        'grace_period'    => 'Você ainda tem acesso até :date (período de graça). Renove para não perder o acesso.',
        'last_plan'       => 'Último plano',
        'choose_plan'     => 'Escolha um plano para continuar',
        'most_popular'    => 'Mais popular',
        'unlimited'       => 'Ilimitado',
        'upgrade_cta'     => 'Contratar',
        'contact_support' => 'Dúvidas? Entre em contato:',
    ],

    'feature_not_included'  => 'O recurso ":feature" não está disponível no seu plano atual. Faça upgrade para continuar.',
    'feature_limit_reached' => 'O limite de ":feature" foi atingido (:limit). Faça upgrade do plano para continuar.',

    'features' => [
        'max_users'              => 'Máximo de usuários',
        'max_patients'           => 'Máximo de pacientes',
        'max_doctors'            => 'Máximo de médicos',
        'max_storage_gb'         => 'Armazenamento (GB)',
        'has_ai_exam_assistant'  => 'Assistente de IA para exames',
        'has_ai_report_drafting' => 'Redação de laudos com IA',
        'has_api_integrator'     => 'Integração com equipamentos oftalmológicos',
        'ai_monthly_credits'     => 'Créditos mensais de IA',
        'api_monthly_exam_sends' => 'Envios de exames pela API (mensal)',
        'plan_upgrade_required'  => 'Seu plano não inclui integração com equipamentos. Faça upgrade para continuar.',

        /* Textos de exibição em cards de precificação */
        'max_doctors_unlimited'       => 'Médicos ilimitados',
        'max_doctors_count'           => 'Até :n médico(s)',
        'max_patients_unlimited'      => 'Pacientes ilimitados',
        'max_patients_count'          => 'Até :n pacientes',
        'max_users_unlimited'         => 'Usuários ilimitados',
        'max_users_count'             => 'Até :n usuários',
        'max_storage_unlimited'       => 'Armazenamento ilimitado',
        'max_storage_count'           => ':n GB de armazenamento',
        'ai_credits_none'             => 'Sem créditos de IA',
        'ai_credits_count'            => ':n créditos de IA/mês',
        'api_exam_sends_unlimited'    => 'Envios de exame ilimitados',
        'api_exam_sends_count'        => 'Até :n envios via API/mês',
        'generic_unlimited'           => ':label ilimitado(a)',
        'generic_count'               => ':label: :n',
    ],

    'pricing_credit_note' => [
        'title'  => 'Como funcionam os créditos de IA?',
        'body'   => 'Cada crédito corresponde a 1 análise de exame ou 1 rascunho de laudo gerado pelo assistente. Os créditos são renovados mensalmente e não acumulam entre ciclos.',
        'topup'  => 'Ao atingir o limite do plano, é possível adquirir <strong>pacotes de recarga avulsos</strong> sem interromper o atendimento.',
    ],
];
