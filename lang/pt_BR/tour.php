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
        'dashboard'              => 'Resumo do seu dia: indicadores, agenda de hoje ao vivo, atalhos e pacientes recentes — o médico vê só o que é dele (próximo paciente e pendências) e cada perfil, só o que pode abrir. Enquanto houver etapas obrigatórias pendentes, o administrador vê o andamento da configuração inicial.',
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
            'dashboard-welcome' => [
                'title'       => 'Seu posto de trabalho',
                'description' => 'O painel abre no posto de trabalho do seu perfil — "Meu consultório" (médico), "Recepção" (secretária), "Gestão" (administração), "Financeiro" ou "Visão geral" —, com a data de hoje e as ações do dia a dia: novo agendamento e novo paciente na recepção e na gestão, iniciar atendimento para o médico, lançar no caixa para o financeiro. Só aparece o que você pode abrir.',
            ],
            'dashboard-live' => [
                'title'       => 'Atualização ao vivo',
                'description' => 'A operação de hoje (agenda, sala de espera, confirmações, caixa do dia) se atualiza sozinha a cada 30 segundos. Os números do mês e as tendências não: vêm ao abrir o painel (guardados por até 10 minutos) e o botão de atualizar recalcula tudo na hora.',
            ],
            'dashboard-customize' => [
                'title'       => 'Personalizar o painel',
                'description' => 'Escolha a ordem das seções desta tela e oculte as que não usa (o olho mostra ou oculta cada uma) — cada perfil vê só as seções dele. Arraste pela alça ou use as setas; "Restaurar padrão" volta ao original. A escolha fica salva para você.',
            ],
            'dashboard-activation' => [
                'title'       => 'Configure sua clínica',
                'description' => 'Mostra quanto da configuração inicial já foi feito e as etapas que faltam, com o peso de cada uma. O cartão some quando as etapas obrigatórias terminam; as opcionais não o prendem.',
            ],
            'dashboard-next-patient' => [
                'title'       => 'Próximo paciente',
                'description' => 'Só para o médico: quem já chegou e espera por você (primeiro os prontos para a consulta, depois os que estão dilatando ou em exame, por ordem de chegada) ou, se ninguém chegou, o próximo horário marcado. "Iniciar atendimento" abre o prontuário da consulta.',
            ],
            'dashboard-kpis' => [
                'title'       => 'Indicadores',
                'description' => 'Os números do seu posto de trabalho. Médico: consultas de hoje, quem aguarda você, seus atendimentos e sua taxa de falta no mês, exames sem laudo e laudos de IA a revisar. Recepção: consultas de hoje, quem aguarda, confirmadas hoje, consultas de amanhã, lista de espera. Gestão e financeiro: o mês até hoje comparado ao MESMO período do mês anterior (ex.: 1–6/10 × 1–6/09) — a seta mostra a variação e a cor diz se é bom (verde) ou ruim (vermelho): falta subir é ruim, receita subir é bom. Clique num indicador para abrir a lista, quando você tem acesso à tela.',
            ],
            'dashboard-finance-today' => [
                'title'       => 'Caixa de hoje',
                'description' => 'Só para o financeiro: entradas e saídas pagas hoje, o saldo do dia e o que ainda vence hoje. "Abrir caixa" leva ao fluxo de caixa já no dia de hoje.',
            ],
            'dashboard-receivables' => [
                'title'       => 'A receber',
                'description' => 'Posição de hoje: lançamentos de receita pendentes no caixa (a vencer e vencidos) e guias de convênio enviadas aguardando pagamento (com as vencidas à parte). Cada linha abre a lista com o mesmo recorte.',
            ],
            'dashboard-glosas' => [
                'title'       => 'Glosas a tratar',
                'description' => 'Glosas em aberto e em recurso, com as de prazo de recurso vencido ou vencendo nos próximos dias em destaque. Clique para abrir a fila de glosas já filtrada.',
            ],
            'dashboard-trends' => [
                'title'       => 'Tendências',
                'description' => 'Gestão: consultas atendidas × faltas por dia nos últimos 30 dias e receita × despesa dos últimos 6 meses (o mesmo gráfico do BI). Financeiro: receita × despesa e faturado × recebido do mês por convênio. "Ver dados" mostra a tabela com os números.',
            ],
            'dashboard-schedule-today' => [
                'title'       => 'Agenda de hoje',
                'description' => 'Consultas do dia separadas em abas por turno (Manhã até 13h, Tarde até 18h e Noite), com os horários agrupados por hora. A aba do turno atual abre sozinha; a hora atual aparece destacada como "Agora". Cada linha mostra horário, situação, paciente (com tipo de consulta, convênio e hora de chegada) e, em telas maiores, o médico — para o médico, só as consultas dele. O ícone verde indica que o paciente chegou. O botão abre a agenda completa já no turno da aba.',
            ],
            'dashboard-waiting-room' => [
                'title'       => 'Sala de espera',
                'description' => 'Só para a recepção: quem chegou e aguarda agora, por ordem de chegada, com o médico, a situação e há quanto tempo espera (âmbar a partir de 30 minutos, vermelho a partir de 1 hora).',
            ],
            'dashboard-day-summary' => [
                'title'       => 'Resumo do dia',
                'description' => 'Agendadas, atendidas, a atender e faltas/cancelamentos de hoje. O andamento conta os atendidos sobre o que ainda vale ("3 de 14 atendidos") — faltas e cancelamentos ficam fora da conta e aparecem à parte —, com a quebra por turno. Para o médico, só as dele ("Meu dia").',
            ],
            'dashboard-doctors-today' => [
                'title'       => 'Atendimentos por médico',
                'description' => 'Só para a gestão: por médico, quantos foram atendidos do que estava previsto hoje, quantos pacientes estão na clínica e as faltas.',
            ],
            'dashboard-confirmations' => [
                'title'       => 'Confirmações',
                'description' => 'Só para a recepção: consultas de hoje e de amanhã confirmadas × sem confirmação, a situação da confirmação por WhatsApp das que faltam (aguardando resposta, falhou, sem envio), as consultas por turno e a lista "Ligar para confirmar", com o telefone para discar. Hoje só entram os horários que ainda não passaram.',
            ],
            'dashboard-waitlist' => [
                'title'       => 'Lista de espera',
                'description' => 'Quantos pacientes aguardam vaga e os primeiros da lista (mesma ordem do painel da Agenda), com médico, período desejado e telefone.',
            ],
            'dashboard-birthdays' => [
                'title'       => 'Aniversariantes de hoje',
                'description' => 'Pacientes da clínica que fazem aniversário hoje, com a idade e o telefone — só para quem tem acesso a Pacientes.',
            ],
            'dashboard-ai-waiting' => [
                'title'       => 'Laudos de IA aguardando aprovação',
                'description' => 'Só para o médico, quando a clínica usa IA: análises de IA pedidas por você ou de exames e prontuários seus que esperam a sua revisão. "Revisar" abre o exame no Eye Images (ou a tela de IA), onde você aprova ou rejeita. Sem nada pendente, vira uma linha "tudo em dia".',
            ],
            'dashboard-unsigned-records' => [
                'title'       => 'Prontuários sem assinatura',
                'description' => 'Só para o médico: seus prontuários dos últimos 30 dias que ainda não foram assinados, com o total e os mais recentes. "Abrir" leva ao prontuário. Sem nada pendente, vira uma linha "tudo em dia".',
            ],
            'dashboard-recent-patients' => [
                'title'       => 'Pacientes recentes',
                'description' => 'Na recepção e na gestão, os pacientes cadastrados mais recentemente, com telefone e código. Para o médico, os últimos pacientes que ele atendeu, com a data da última consulta. Abra o cadastro de cada um ("Ver") ou a lista completa ("Ver todos").',
            ],
            'dashboard-stock-alerts' => [
                'title'       => 'Alertas de estoque',
                'description' => 'Aparece quando há produtos abaixo do estoque mínimo ou com lote vencido ou vencendo nos próximos 30 dias. Clique num alerta para ver os produtos dele ou em "Ver estoque" para a lista completa.',
            ],
            'dashboard-shortcuts-customize' => [
                'title'       => 'Escolher atalhos',
                'description' => 'Escolha os atalhos abaixo: o olho mostra ou oculta cada um; arraste pela alça ou use as setas para mudar a ordem. "Restaurar padrão" volta ao original. A escolha fica salva para você.',
            ],
            'dashboard-shortcuts' => [
                'title'       => 'Atalhos',
                'description' => 'Acesso rápido aos módulos que o seu perfil pode abrir: Agenda e Pacientes para quem atende ou agenda, Eye Images para todos e Financeiro, Guias TISS, Glosas e BI para a gestão e o financeiro (que os vê primeiro). "Centro Cirúrgico" ("Em breve") aparece só para gestão e recepção.',
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
