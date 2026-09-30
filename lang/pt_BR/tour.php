<?php

declare(strict_types=1);

/*
 * Tour guiado do painel da clínica (driver.js — App\Support\PanelTour e
 * resources/js/composables/usePanelTour.js). Enviado como `tour.t`.
 *
 * `nav`: descrição de cada item do menu lateral pela `key` do
 * App\Support\PanelNavigation (o título do passo é o próprio rótulo do menu).
 * Item sem descrição aqui não entra no tour. Texto puro (sem HTML).
 * Mudou o conteúdo de forma relevante? Suba PanelTour::VERSION.
 */
return [
    'ui' => [
        'start'    => 'Fazer o tour guiado',
        'next'     => 'Próximo',
        'previous' => 'Anterior',
        'done'     => 'Concluir',
        'close'    => 'Fechar o tour',
        // {{current}} e {{total}} são preenchidos pelo driver.js.
        'progress' => '{{current}} de {{total}}',
    ],

    'intro' => [
        'title'       => 'Bem-vindo ao EasyEye',
        'description' => 'Vamos mostrar, passo a passo, o que cada parte do sistema faz. Use as setas do teclado para avançar ou voltar e Esc para sair.',
        // Telas de toque (sem teclado).
        'description_touch' => 'Vamos mostrar, passo a passo, o que cada parte do sistema faz. Use os botões do balão para avançar ou voltar e o X para sair.',
    ],

    'mobile_menu' => [
        'title'       => 'Menu',
        'description' => 'Depois do tour, toque neste botão para abrir o menu: nele ficam a clínica atual e todas as áreas do sistema. A seguir, o que cada uma faz.',
    ],

    'nav' => [
        'dashboard'              => 'Resumo do dia da clínica: indicadores, agenda de hoje ao vivo, atalhos, últimos pacientes cadastrados e, enquanto houver etapas obrigatórias pendentes, o andamento da configuração inicial.',
        'schedules'              => 'Agenda dos médicos: marque, confirme, remarque e acompanhe os atendimentos do dia, com lista de espera.',
        'patients'               => 'Cadastro dos pacientes com histórico de atendimentos, prontuário e documentos.',
        'doctors'                => 'Cadastro dos médicos da clínica, com horários de atendimento, bloqueios e ausências e os dados usados nos documentos, como o CRM.',
        'eye-images'             => 'Imagens dos exames feitos nos equipamentos oftalmológicos, organizadas por paciente.',
        'ai'                     => 'Assistente de IA: acompanhe o consumo e o saldo de créditos (o administrador também compra créditos aqui) e, se você é médico, gerencie seus prompts.',
        'stock'                  => 'Estoque: produtos e lentes, entradas e saídas, compras, fornecedores, contagem e relatórios.',
        'financial'              => 'Financeiro: painel gerencial, fluxo e fechamento de caixa, faturamento TISS, repasse médico, tabela de preços, glosas e relatórios.',
        'settings-clinical'      => 'Configurações da clínica: salas e equipamentos que podem ser reservados nos agendamentos e o painel de chamadas exibido na TV da sala de espera.',
        'settings-attendance'    => 'Configurações de atendimento: convênios e tipos de atendimento usados na agenda.',
        'settings-users'         => 'Usuários da clínica, perfis de acesso e a exigência de autenticação em dois fatores para todos os usuários.',
        'settings-documents'     => 'Modelos dos documentos clínicos, como receitas, laudos e atestados.',
        'settings-ophthalmology' => 'Parâmetros oftalmológicos: listas usadas no cadastro do paciente e no prontuário, como acuidade visual, visão cromática, lentes e tipos de cirurgia.',
        'my-payouts'             => 'Seus repasses: fechamentos feitos pela clínica, pagamentos recebidos e o demonstrativo em PDF.',
    ],

    /*
     * Passos da tela atual, pela rota (entram depois das boas-vindas e antes
     * do menu). A chave de cada passo é o data-tour do elemento; o tour segue
     * a ordem em que os elementos aparecem na tela (o usuário pode reordenar
     * as seções); elemento que não aparece para o usuário (perfil, plano,
     * dados) fica de fora sozinho.
     */
    'pages' => [
        'panel.dashboard' => [
            'dashboard-customize' => [
                'title'       => 'Personalizar o painel',
                'description' => 'Escolha a ordem das seções desta tela (indicadores, atalhos, agenda de hoje, pacientes recentes e, quando houver, alertas de estoque): arraste pela alça ou use as setas. "Restaurar padrão" volta à ordem original. A ordem fica salva para você.',
            ],
            'dashboard-live' => [
                'title'       => 'Atualização ao vivo',
                'description' => 'Os números e a agenda desta tela se atualizam sozinhos a cada 30 segundos. Aqui aparece o horário da última atualização e o botão para atualizar na hora.',
            ],
            'dashboard-welcome' => [
                'title'       => 'Boas-vindas',
                'description' => 'Saudação com o nome da clínica e, se você tem acesso a pacientes, atalhos para a lista de pacientes e para cadastrar um novo paciente.',
            ],
            'dashboard-activation' => [
                'title'       => 'Configure sua clínica',
                'description' => 'Mostra quanto da configuração inicial já foi feito e as etapas que faltam, com o peso de cada uma. O cartão some quando as etapas obrigatórias terminam; as opcionais não o prendem.',
            ],
            'dashboard-kpis' => [
                'title'       => 'Indicadores',
                'description' => 'Pacientes ativos, consultas marcadas para hoje e médicos ativos da clínica. Clique num indicador para abrir a lista, quando você tem acesso à tela. "Cirurgias hoje" ainda está em preparação ("Em breve").',
            ],
            'dashboard-kpis-soon' => [
                'title'       => 'Indicadores em breve',
                'description' => 'Indicadores em preparação, marcados com "Em breve": ainda não mostram números.',
            ],
            'dashboard-shortcuts-customize' => [
                'title'       => 'Escolher atalhos',
                'description' => 'Escolha os atalhos abaixo: o olho mostra ou oculta cada um; arraste pela alça ou use as setas para mudar a ordem. "Restaurar padrão" volta ao original. A escolha fica salva para você.',
            ],
            'dashboard-shortcuts' => [
                'title'       => 'Atalhos',
                'description' => 'Acesso rápido aos módulos que o seu perfil pode abrir: Eye Images para todos, Agenda para quem atende ou agenda (administração, médicos e recepção) e Guias TISS e Financeiro para administração e financeiro. Os marcados "Em breve" ainda não estão disponíveis.',
            ],
            'dashboard-schedule-today' => [
                'title'       => 'Agenda de hoje',
                'description' => 'Consultas do dia por horário, com paciente, situação e, em telas maiores, o médico. O ícone verde indica que o paciente chegou; as linhas destacadas e o selo ao lado do título mostram os atendimentos que ainda não terminaram. Em dias cheios, a lista mostra as primeiras consultas e avisa quantas são no total. O botão abre a agenda completa, quando você tem acesso.',
            ],
            'dashboard-day-summary' => [
                'title'       => 'Resumo do dia',
                'description' => 'Total de consultas de hoje e quantas foram atendidas, estão em andamento ou aguardando e foram canceladas ou faltaram.',
            ],
            'dashboard-recent-patients' => [
                'title'       => 'Últimos pacientes cadastrados',
                'description' => 'Os pacientes cadastrados mais recentemente e, em telas maiores, o telefone e o código de cada um. Abra o cadastro de cada um ("Ver") ou a lista completa ("Ver todos"), quando você tem acesso a pacientes.',
            ],
            'dashboard-stock-alerts' => [
                'title'       => 'Alertas de estoque',
                'description' => 'Aparece quando há produtos abaixo do estoque mínimo ou com lote vencido ou vencendo nos próximos 30 dias. Clique num alerta para ver os produtos dele ou em "Ver estoque" para a lista completa.',
            ],
        ],
    ],

    'layout' => [
        'sidebar-toggle' => [
            'title'       => 'Recolher ou expandir o menu',
            'description' => 'Recolhe o menu lateral para deixar só os ícones e ganhar espaço na tela; use de novo para expandir. Com o menu recolhido, passe o mouse sobre ele para ver os nomes.',
        ],
        'entity-switcher' => [
            'title'       => 'Clínica atual',
            'description' => 'No topo do menu lateral aparece em qual clínica você está. Se você trabalha em mais de uma, troque de clínica por ali. Com o menu recolhido, ela aparece ao expandir o menu.',
        ],
        'locale' => [
            'title'       => 'Idioma',
            'description' => 'Escolha o idioma do sistema.',
        ],
        'theme' => [
            'title'       => 'Modo claro ou escuro',
            'description' => 'Alterne entre o tema claro e o escuro.',
        ],
        'user-menu' => [
            'title'       => 'Sua conta',
            'description' => 'Edite seu perfil e saia do sistema com segurança.',
        ],
        'ai-assistant' => [
            'title'       => 'Assistente virtual',
            'description' => 'Converse com a IA em qualquer tela: tire dúvidas clínicas e crie documentos. No prontuário e nos exames do paciente, ela também analisa o caso ou o exame, se você ativar o contexto. Confira sempre as respostas antes de usar.',
        ],
        'help' => [
            'title'       => 'Rever o tour',
            'description' => 'Use este botão quando quiser rever o tour. Na tela inicial (Dashboard), ele também explica cada parte do painel.',
        ],
    ],

    'outro' => [
        'title'       => 'Pronto!',
        'description' => 'Agora você já sabe onde fica cada área. Bom trabalho!',
    ],
];
