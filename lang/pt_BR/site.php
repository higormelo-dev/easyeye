<?php

return [
    'meta' => [
        'title'          => config('app.name', 'EasyEye') . ' — Sistema para Clínicas Oftalmológicas',
        'description'    => 'Gestão completa para clínicas oftalmológicas. Prontuário eletrônico, agenda, faturamento TISS e muito mais em um único sistema.',
        'og_title'       => config('app.name', 'EasyEye') . ' — Sistema para Clínicas Oftalmológicas',
        'og_description' => 'Gestão completa para clínicas oftalmológicas. Do agendamento ao faturamento TISS, tudo integrado.',
    ],

    'nav' => [
        'features'     => 'Funcionalidades',
        'how'          => 'Como funciona',
        'pricing'      => 'Preços',
        'testimonials' => 'Depoimentos',
        'faq'          => 'FAQ',
        'login'        => 'Entrar',
        'get_started'  => 'Começar grátis',
        'language'     => 'Idioma',
    ],

    'footer' => [
        'tagline'   => 'Sistema de gestão clínica especializado em oftalmologia. Agenda, prontuário, TISS e financeiro em uma única plataforma.',
        'product'   => 'Produto',
        'system'    => 'Sistema',
        'company'   => 'Empresa',
        'login'     => 'Acessar sistema',
        'register'  => 'Criar conta',
        'help'      => 'Central de ajuda',
        'status'    => 'Status da plataforma',
        'api'       => 'API & Integrações',
        'about'     => 'Sobre nós',
        'blog'      => 'Blog',
        'partners'  => 'Parceiros',
        'contact'   => 'Contato',
        'careers'   => 'Trabalhe conosco',
        'privacy'   => 'Privacidade',
        'terms'     => 'Termos de uso',
        'lgpd'      => 'LGPD',
        'copyright' => '© :year :name. Todos os direitos reservados.',
    ],

    'hero' => [
        'badge'               => 'Novo: TISS 3.06 integrado e homologado',
        'title'               => 'Gestão completa para clínicas',
        'title_em'            => 'oftalmológicas',
        'subtitle'            => 'Do agendamento ao prontuário eletrônico com TISS integrado. Automatize processos, reduza glosas e foque no que realmente importa: a saúde dos seus pacientes.',
        'cta_primary'         => 'Começar grátis',
        'cta_secondary'       => 'Ver demonstração',
        'trust'               => 'Mais de <strong style="color:#fff;">:count clínicas</strong> confiam no EasyEye',
        'card_today'          => 'Hoje',
        'card_appointments'   => ':count consultas agendadas',
        'card_compliance_lbl' => 'LGPD & CFM',
        'card_compliance_val' => 'Compliance garantido',
    ],

    'metrics' => [
        ['value' => '500+', 'label' => 'Clínicas ativas'],
        ['value' => '50k+', 'label' => 'Consultas por mês'],
        ['value' => '99,9%', 'label' => 'Uptime garantido'],
        ['value' => 'R$0', 'label' => 'Taxa de implantação'],
    ],

    'features' => [
        'label'    => 'Funcionalidades',
        'title'    => 'Tudo que sua clínica precisa, em um único lugar',
        'subtitle' => 'Desenvolvido especialmente para oftalmologia, com todas as ferramentas que clínicas modernas exigem.',
        'items'    => [
            ['icon' => 'bi-calendar3', 'color' => 'icon-teal', 'title' => 'Agenda Inteligente', 'text' => 'Gerencie múltiplos médicos, salas e recursos. Confirmação automática por WhatsApp e SMS, reduzindo no-shows em até 40%.'],
            ['icon' => 'bi-file-medical', 'color' => 'icon-blue', 'title' => 'Prontuário Eletrônico', 'text' => 'Prontuário específico para oftalmologia com refração, biomicroscopia, fundoscopia, campos visuais e laudos integrados.'],
            ['icon' => 'bi-receipt', 'color' => 'icon-mint', 'title' => 'Faturamento TISS', 'text' => 'Geração de guias TISS 3.06, lotes XML, envio eletrônico e processamento de retorno. Redução de glosas com pré-validação.'],
            ['icon' => 'bi-graph-up-arrow', 'color' => 'icon-purple', 'title' => 'Gestão Financeira', 'text' => 'Fluxo de caixa, contas a receber, relatórios gerenciais e múltiplos gateways de pagamento integrados.'],
            ['icon' => 'bi-shield-lock', 'color' => 'icon-orange', 'title' => 'Compliance CFM & LGPD', 'text' => 'Trilha de auditoria completa, versionamento de prontuário, assinatura digital e conformidade total com LGPD e resoluções CFM.'],
            ['icon' => 'bi-people', 'color' => 'icon-red', 'title' => 'Multi-clínica', 'text' => 'Gerencie múltiplas unidades com um único login. Relatórios consolidados e controle de acesso por papel e unidade.'],
        ],
    ],

    'how' => [
        'label'                  => 'Como funciona',
        'title'                  => 'Implantação simples, resultados imediatos',
        'subtitle'               => 'Em menos de um dia sua clínica já está operando com o EasyEye. Sem instalação, sem servidores, tudo na nuvem.',
        'screenshot_alt'         => 'Como funciona o EasyEye',
        'screenshot_placeholder' => 'Screenshot do sistema',
        'screenshot_hint'        => 'Adicione em public/site/images/how-it-works.png',
        'steps'                  => [
            ['title' => 'Crie sua conta em minutos', 'text' => 'Cadastro rápido, sem burocracia. Configure sua clínica, adicione médicos e defina horários de atendimento.'],
            ['title' => 'Importe seus pacientes', 'text' => 'Importe sua base de pacientes via CSV ou cadastre manualmente. Histórico e prontuários migrados com segurança.'],
            ['title' => 'Comece a atender', 'text' => 'Sua equipe treinada em horas. Suporte dedicado na implantação e atendimento contínuo para crescer com você.'],
        ],
    ],

    'compliance' => [
        'label'    => 'Segurança & Conformidade',
        'title'    => 'Seguro por design, compliant por padrão',
        'subtitle' => 'Desenvolvido em conformidade com as regulamentações do CFM, TISS da ANS e a Lei Geral de Proteção de Dados. Seus dados e os de seus pacientes estão protegidos.',
        'badges'   => [
            ['icon' => 'bi-patch-check-fill', 'label' => 'Resolução CFM'],
            ['icon' => 'bi-shield-fill-check', 'label' => 'LGPD Compliant'],
            ['icon' => 'bi-file-earmark-check-fill', 'label' => 'TISS 3.06 ANS'],
            ['icon' => 'bi-lock-fill', 'label' => 'Dados criptografados'],
            ['icon' => 'bi-cloud-check-fill', 'label' => 'Backup automático'],
            ['icon' => 'bi-journal-check', 'label' => 'Trilha de auditoria'],
        ],
    ],

    'testimonials' => [
        'label' => 'Depoimentos',
        'title' => 'O que nossos clientes dizem',
        'items' => [
            [
                'text'     => '"O EasyEye transformou nossa clínica. O faturamento TISS que levava dias agora é feito em horas. Reduzimos as glosas em 60% no primeiro mês."',
                'name'     => 'Dr. Ricardo Mendes',
                'role'     => 'Oftalmologista — Clínica Visão SP',
                'initials' => 'DR',
                'stars'    => 5,
            ],
            [
                'text'     => '"A agenda integrada com confirmação automática reduziu nosso no-show de 30% para menos de 8%. A equipe adorou a facilidade de uso."',
                'name'     => 'Dra. Ana Carvalho',
                'role'     => 'Diretora — Instituto Ocular BH',
                'initials' => 'AC',
                'stars'    => 5,
            ],
            [
                'text'     => '"Gerenciamos 3 unidades com o EasyEye. Os relatórios consolidados e o controle de acesso por unidade nos deram visibilidade total do negócio."',
                'name'     => 'Paulo Souza',
                'role'     => 'Gestor — Rede OftalmoClin RJ',
                'initials' => 'PS',
                'stars'    => 4,
            ],
        ],
    ],

    'pricing' => [
        'label'          => 'Planos',
        'title'          => 'Planos para cada tamanho de clínica',
        'subtitle'       => 'Sem taxas de implantação. Cancele quando quiser.',
        'trial_suffix'   => 'Experimente grátis por :days dias.',
        'featured_badge' => 'Mais popular',
        'contact_cta'    => 'Falar com especialista',
        'on_request'     => 'Sob consulta',
        'get_started'    => 'Começar grátis',
        'trial_text'     => ':days dias grátis para testar',
        'empty_title'    => 'Planos em breve',
        'empty_subtitle' => 'Estamos preparando os melhores planos para sua clínica. Entre em contato e saiba mais.',
    ],

    'faq' => [
        'label' => 'Dúvidas frequentes',
        'title' => 'Perguntas frequentes',
        'items' => [
            ['q' => 'Como é feita a migração dos dados da minha clínica?', 'a' => 'Oferecemos importação via CSV para pacientes e histórico. Nossa equipe de implantação auxilia na migração dos dados do sistema anterior sem interrupção do atendimento.'],
            ['q' => 'O EasyEye funciona offline?', 'a' => 'O EasyEye é uma solução 100% em nuvem, o que garante acesso de qualquer dispositivo. Para contingência, mantemos cache local das agendas do dia.'],
            ['q' => 'Como funciona o suporte técnico?', 'a' => 'Oferecemos suporte por chat, e-mail e telefone conforme o plano. No plano Professional, o atendimento é prioritário com SLA de 4 horas úteis.'],
            ['q' => 'O sistema é homologado pela ANS para TISS?', 'a' => 'Sim. O EasyEye é homologado para as versões TISS 3.05 e 3.06 da ANS, com geração de XML, envio e processamento de retorno totalmente automatizados.'],
            ['q' => 'Posso integrar com outros sistemas?', 'a' => 'Disponibilizamos API REST para integração com ERPs, sistemas de imagem (laudos), laboratórios e outros sistemas clínicos. Documentação disponível para desenvolvedores.'],
        ],
    ],

    'cta' => [
        'title'     => 'Pronto para transformar sua clínica oftalmológica?',
        'subtitle'  => '14 dias gratuitos, sem cartão de crédito. Configure em menos de um dia.',
        'primary'   => 'Criar conta grátis',
        'secondary' => 'Falar com um especialista',
        'note'      => 'Sem taxa de implantação • Cancele quando quiser • Suporte na implantação',
    ],
];
