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
        'max_users'                 => 'Máximo de usuários',
        'max_patients'              => 'Máximo de pacientes',
        'max_doctors'               => 'Máximo de médicos',
        'max_storage_gb'            => 'Armazenamento (GB)',
        'has_ai_exam_assistant'     => 'Assistente de IA para exames',
        'has_ai_report_drafting'    => 'Redação de laudos com IA',
        'has_ai_consensus'          => 'Revisão inteligente de consistência',
        'has_ai_eye_image_analysis' => 'Análise de imagem ocular com IA',
        'has_ai_chat_assistant'     => 'Assistente virtual de IA (chat flutuante)',
        'has_api_integrator'        => 'Integração com equipamentos oftalmológicos',
        'has_inventory_module'      => 'Módulo de estoque',
        'ai_monthly_credits'        => 'Créditos mensais de IA',
        'api_monthly_exam_sends'    => 'Envios via integrador (mensal)',
        'plan_upgrade_required'     => 'Seu plano não inclui integração com equipamentos. Faça upgrade para continuar.',

        /* Textos de exibição em cards de precificação */
        'max_doctors_unlimited'    => 'Médicos ilimitados',
        'max_doctors_count'        => 'Até :n médico|Até :n médicos',
        'max_patients_unlimited'   => 'Pacientes ilimitados',
        'max_patients_count'       => 'Até :n paciente|Até :n pacientes',
        'max_users_unlimited'      => 'Usuários ilimitados',
        'max_users_count'          => 'Até :n usuário|Até :n usuários',
        'max_storage_unlimited'    => 'Armazenamento ilimitado',
        'max_storage_count'        => ':n GB de armazenamento|:n GB de armazenamento',
        'ai_credits_none'          => 'Sem créditos de IA',
        'ai_credits_count'         => ':n crédito de IA por mês|:n créditos de IA por mês',
        'api_exam_sends_unlimited' => 'Exames ilimitados dentro do armazenamento contratado',
        'api_exam_sends_count'     => 'Até :n envio via integrador por mês|Até :n envios via integrador por mês',
        'generic_unlimited'        => ':label ilimitado(a)',
        'generic_count'            => ':label: :n',
    ],

    'pricing_credit_note' => [
        'title'         => 'IA no seu plano: o que consome créditos',
        'intro'         => 'Os recursos de IA do seu plano usam o mesmo saldo de créditos, compartilhado pelos médicos da clínica.',
        'actions_title' => 'Análises e rascunhos',
        'actions_body'  => 'Análises de exames e rascunhos de laudos usam créditos quando esses recursos estão incluídos no plano.',
        'chat_title'    => 'Dúvidas e textos no assistente',
        'chat_body'     => 'Cada pergunta ou pedido de texto no assistente virtual também usa esse saldo. As conversas não são ilimitadas.',
        'usage_title'   => 'Consumo variável',
        'usage_body'    => 'Uma solicitação pode consumir mais de um crédito, conforme a tarefa e o processamento necessário.',
        'renewal_title' => 'Franquia do plano',
        'renewal_body'  => 'A franquia do plano é concedida na ativação da assinatura e renovada a cada ciclo. Créditos não utilizados dessa franquia não acumulam.',
        'topup'         => 'Créditos extras comprados acumulam, não expiram e são usados após a franquia do plano. Sem saldo suficiente, apenas os recursos de IA ficam indisponíveis até a recarga ou renovação.',
        'trial_note'    => 'A franquia do plano não é liberada durante o período de teste.',
        'medical_note'  => 'A IA oferece apoio. O médico deve revisar o conteúdo gerado.',
    ],
];
