<?php

return [
    'access_blocked' => 'Sua assinatura está inativa. Renove para continuar usando o sistema.',
    'access_limited' => 'Pagamento da assinatura em atraso: o acesso está limitado. IA e módulo financeiro ficam bloqueados até o pagamento ser confirmado; agenda, pacientes e prontuário seguem liberados.',

    'status' => [
        'trial'     => 'Trial',
        'active'    => 'Ativo',
        'expired'   => 'Expirado',
        'cancelled' => 'Cancelado',
        'past_due'  => 'Em atraso',
    ],

    // Contratação pela cobrança automática ainda sem o 1º pagamento (não está "em atraso").
    'status_awaiting_first_payment' => 'Aguardando 1º pagamento',

    'billing_cycle' => [
        'monthly'    => 'Mensal',
        'quarterly'  => 'Trimestral',
        'semiannual' => 'Semestral',
        'yearly'     => 'Anual',
        'lifetime'   => 'Vitalício',
    ],

    // Sufixo do preço por ciclo ("R$ 299,90/mês").
    'billing_period' => [
        'monthly'    => '/mês',
        'quarterly'  => '/trimestre',
        'semiannual' => '/semestre',
        'yearly'     => '/ano',
        'lifetime'   => '/vitalício',
    ],

    // Como a assinatura é paga.
    'billing_mode' => [
        'gateway'       => 'Cobrança automática',
        'complimentary' => 'Cortesia',
    ],

    'expired_page' => [
        'title'              => 'Assinatura expirada',
        'heading'            => 'Sua assinatura está inativa',
        'heading_trial'      => 'Seu período de teste terminou',
        'heading_payment'    => 'Aguardando o pagamento',
        'blocked_trial'      => 'O teste grátis de :name acabou. Escolha um plano para voltar a usar o EasyEye.',
        'blocked_payment'    => 'O acesso de :name volta assim que o pagamento da assinatura for confirmado.',
        'last_plan'          => 'Último plano',
        'choose_plan'        => 'Escolha um plano para continuar',
        'most_popular'       => 'Mais popular',
        'unlimited'          => 'Ilimitado',
        'upgrade_cta'        => 'Contratar',
        'contact_support'    => 'Dúvidas?',
        'contact_link'       => 'Fale com a nossa equipe',
        'blocked_entity'     => 'A empresa :name está com o acesso bloqueado. Renove para continuar usando o EasyEye.',
        'blocked_generic'    => 'O acesso ao sistema está bloqueado. Renove para continuar usando o EasyEye.',
        'ended_on'           => 'encerrada em :date',
        'due_on'             => 'venceu em :date',
        'no_plans'           => 'Nenhum plano disponível no momento. Fale com o suporte.',
        'monthly_equivalent' => 'equivale a :price/mês',
        'savings'            => 'Economize :percent%',
        'logout'             => 'Sair',

        // Acesso limitado (cliente pagante em atraso na régua de cobrança).
        'heading_limited'           => 'Acesso limitado por pagamento em atraso',
        'blocked_limited'           => 'O pagamento da assinatura de :name está em atraso. Até a confirmação do pagamento, alguns recursos ficam bloqueados.',
        'limited_blocked_title'     => 'Bloqueados até o pagamento',
        'limited_blocked_ai'        => 'Inteligência artificial (análises, assistente e créditos)',
        'limited_blocked_financial' => 'Módulo financeiro (caixa, faturamento TISS, glosas, repasses e relatórios financeiros)',
        'limited_allowed'           => 'Agenda, pacientes e prontuário seguem liberados.',
        'limited_deadline'          => 'Sem o pagamento, o acesso ao painel será suspenso em :date.',
        'back_to_panel'             => 'Voltar ao painel',

        // Cobrança em aberto com link de pagamento.
        'payment_title' => 'Cobrança em aberto',
        'payment_due'   => ':amount — vencimento em :date',
        'payment_hint'  => 'O acesso é liberado automaticamente assim que o pagamento for confirmado.',
        'pay_now'       => 'Pagar agora',
        'opens_new_tab' => '(abre em nova aba)',
        // Sem o link da cobrança (gateway não devolve, ou ainda não chegou).
        'no_link' => 'O link de pagamento desta cobrança não está disponível aqui. Para pagar, fale com a nossa equipe.',
        // Perfis sem acesso à cobrança (só admin, financeiro e dono veem valor e link).
        'ask_admin' => 'Para regularizar o pagamento, procure o administrador da clínica.',
    ],

    // Aviso no topo do painel (AppLayout) — situação da assinatura.
    'banner' => [
        'pay_now'     => 'Pagar agora',
        'choose_plan' => 'Falar com o comercial',
        // Trial terminando, visto por quem paga: contratar dentro do sistema.
        'subscribe_now' => 'Contratar agora',
        // Aviso de pagamento sem o link da cobrança: o caminho é falar com a equipe.
        'contact'             => 'Falar com a nossa equipe',
        'no_link'             => 'O link desta cobrança não está disponível aqui; para pagar, fale com a nossa equipe.',
        'dismiss'             => 'Fechar aviso',
        'opens_new_tab'       => '(abre em nova aba)',
        'first_payment_title' => 'Pagamento pendente',
        'first_payment_body'  => 'A 1ª cobrança da assinatura vence em :date. Sem o pagamento, o acesso ao painel é suspenso no fim desse dia.',
        'overdue_title'       => 'Pagamento em atraso',
        'overdue_body'        => 'O pagamento da assinatura está em atraso :days. Em :limited_date, IA e módulo financeiro serão bloqueados; em :blocked_date, o acesso ao painel será suspenso.',
        'limited_title'       => 'Acesso limitado',
        'limited_body'        => 'Pagamento em atraso :days: IA e módulo financeiro estão bloqueados. Agenda, pacientes e prontuário seguem liberados. Em :blocked_date, o acesso ao painel será suspenso.',
        'days_overdue'        => 'há :days dia|há :days dias',
        'days_overdue_today'  => 'desde hoje',
        'trial_title'         => 'Teste grátis terminando',
        'trial_body'          => 'Seu teste grátis termina em :date (:days). Escolha um plano para continuar usando o EasyEye.',
        'trial_days_left'     => 'falta :days dia|faltam :days dias',
        'trial_today'         => 'termina hoje',
        // Perfis sem acesso à cobrança (só admin, financeiro e dono veem valor e link).
        'ask_admin' => 'Peça ao administrador da clínica para regularizar o pagamento.',
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
        'renewal_body'  => 'A franquia do plano é concedida na ativação da assinatura paga e renovada todo mês, também nos planos trimestral, semestral e anual. Créditos não utilizados dessa franquia não acumulam.',
        'topup'         => 'Créditos extras comprados acumulam, não expiram e são usados após a franquia do plano. Sem saldo suficiente, apenas os recursos de IA ficam indisponíveis até a recarga ou renovação.',
        'trial_note'    => 'A franquia do plano não é liberada durante o período de teste nem em cortesias.',
        'medical_note'  => 'A IA oferece apoio. O médico deve revisar o conteúdo gerado.',
    ],
];
