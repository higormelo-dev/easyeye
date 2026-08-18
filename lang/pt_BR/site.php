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
        'contact'      => 'Contato',
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

    // ── Problemas que o EasyEye resolve ──────────────────────────────────
    // Posicionada logo após o hero/métricas: mostra as dores da rotina ANTES
    // de qualquer funcionalidade ou preço, para o visitante se reconhecer no
    // problema antes de ver a solução.
    'problems' => [
        'label'    => 'O dia a dia sem o EasyEye',
        'title'    => 'Sua clínica ainda perde tempo (e dinheiro) com isso?',
        'subtitle' => 'Problemas comuns em clínicas oftalmológicas que ainda dependem de papel, planilhas soltas e sistemas genéricos.',
        'items'    => [
            ['icon' => 'bi-file-earmark-x', 'title' => 'Prontuário em papel ou planilhas soltas', 'text' => 'Histórico do paciente espalhado, difícil de consultar na consulta seguinte e sujeito a perda.'],
            ['icon' => 'bi-images', 'title' => 'Exames e imagens sem organização', 'text' => 'Fotos e exames de aparelhos espalhados em pen-drive, e-mail ou pastas — ninguém encontra rápido.'],
            ['icon' => 'bi-calendar-x', 'title' => 'Agenda manual, no-show e retrabalho', 'text' => 'Sem confirmação automática, faltas derrubam a produtividade do dia e da equipe.'],
            ['icon' => 'bi-clock-history', 'title' => 'Laudos e documentos demorados', 'text' => 'Cada atestado, receituário ou laudo é redigido do zero, sem modelo nem padronização.'],
            ['icon' => 'bi-receipt-cutoff', 'title' => 'Faturamento TISS manual e cheio de glosa', 'text' => 'Guias preenchidas à mão, retrabalho com convênio e receita que demora a entrar no caixa.'],
            ['icon' => 'bi-diagram-3', 'title' => 'Sistemas soltos, sem visão única do paciente', 'text' => 'Agenda, prontuário e exames em ferramentas diferentes — a equipe perde tempo cruzando informação.'],
        ],
        'bridge' => 'É exatamente isso que o EasyEye resolve.',
    ],

    // ── Benefícios (o que a clínica GANHA) ───────────────────────────────
    // Framing de resultado/benefício — distinto de "features" abaixo, que é
    // a lista técnica/operacional de capacidades. Ordem e itens definidos
    // pelo pedido de produto (gerenciador de imagens, prontuário, agenda,
    // integração com equipamentos, laudos, IA, gestão da clínica, histórico).
    'benefits' => [
        'label'    => 'Benefícios',
        'title'    => 'O que sua clínica ganha com o EasyEye',
        'subtitle' => 'Cada módulo foi pensado para a rotina real de uma clínica oftalmológica — do consultório à recepção.',
        'items'    => [
            ['icon' => 'bi-images', 'color' => 'icon-teal', 'title' => 'Gerenciador de imagens oftalmológicas', 'text' => 'Centralize e organize exames e imagens dos pacientes num único lugar, por olho e por data — sem pen-drive, sem pasta perdida.'],
            ['icon' => 'bi-file-medical', 'color' => 'icon-blue', 'title' => 'Prontuário oftalmológico', 'text' => 'Informações clínicas, histórico e acompanhamento do paciente sempre organizados e disponíveis na consulta.'],
            ['icon' => 'bi-calendar3', 'color' => 'icon-mint', 'title' => 'Agenda', 'text' => 'Organize médicos, horários e atendimentos da clínica com confirmação automática e menos no-show.'],
            ['icon' => 'bi-hdd-network', 'color' => 'icon-purple', 'title' => 'Integração com equipamentos', 'text' => 'Facilite o envio e armazenamento dos exames realizados diretamente nos aparelhos da clínica.'],
            ['icon' => 'bi-file-earmark-medical', 'color' => 'icon-orange', 'title' => 'Laudos e documentos', 'text' => 'Crie e mantenha laudos, prescrições, relatórios e demais documentos clínicos dentro do próprio sistema.'],
            ['icon' => 'bi-stars', 'color' => 'icon-red', 'title' => 'Assistente de IA', 'text' => 'Apoio ao médico na rotina e na elaboração de documentos — sempre como apoio à decisão, nunca substituindo o julgamento clínico.'],
            ['icon' => 'bi-building-gear', 'color' => 'icon-blue', 'title' => 'Gestão da clínica', 'text' => 'Centralize informações administrativas e operacionais da clínica em um único ambiente.'],
            ['icon' => 'bi-clock-history', 'color' => 'icon-teal', 'title' => 'Histórico do paciente', 'text' => 'Consultas, exames, imagens e documentos reunidos em um único histórico, acessível a qualquer momento.'],
        ],
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

    // ── Demonstração visual (tour do produto) ────────────────────────────
    // 4 abas com mockup do próprio módulo — mesmo padrão de upgrade
    // automático para screenshot real do `how.screenshot_hint` (ver
    // demoImages no controller: public/site/images/demo-{key}.png).
    'demo' => [
        'label'    => 'Conheça o sistema',
        'title'    => 'Veja o EasyEye por dentro',
        'subtitle' => 'Uma prévia das telas que sua equipe vai usar todos os dias.',
        'tabs'     => [
            ['key' => 'prontuario', 'icon' => 'bi-file-medical', 'label' => 'Prontuário', 'caption' => 'Anamnese, refração, biomicroscopia e fundoscopia organizados por consulta.'],
            ['key' => 'agenda', 'icon' => 'bi-calendar3', 'label' => 'Agenda', 'caption' => 'Múltiplos médicos e salas na mesma visão, com status de cada atendimento.'],
            ['key' => 'imagens', 'icon' => 'bi-images', 'label' => 'Gerenciador de imagens', 'caption' => 'Exames e fotos organizados por olho, tipo e data — busca rápida por paciente.'],
            ['key' => 'laudos', 'icon' => 'bi-file-earmark-medical', 'label' => 'Laudos & Documentos', 'caption' => 'Modelos prontos para laudo, receituário, atestado e encaminhamento.'],
        ],
        'screenshot_placeholder' => 'Prévia do módulo',
    ],

    // ── Diferenciais ──────────────────────────────────────────────────────
    'differentiators' => [
        'label'    => 'Diferenciais',
        'title'    => 'Por que clínicas escolhem o EasyEye',
        'subtitle' => 'Não é um sistema de gestão genérico adaptado para saúde — é feito para a rotina oftalmológica desde o primeiro dia.',
        'items'    => [
            ['icon' => 'bi-eye', 'title' => 'Especialista em oftalmologia', 'text' => 'Campos, laudos e fluxos pensados para a rotina do consultório oftalmológico — não um EMR genérico adaptado.'],
            ['icon' => 'bi-file-earmark-check-fill', 'title' => 'TISS 3.06 homologado', 'text' => 'Geração, envio e retorno de guias TISS homologados pela ANS, com pré-validação para reduzir glosas.'],
            ['icon' => 'bi-shield-fill-check', 'title' => 'Compliance CFM & LGPD nativo', 'text' => 'Trilha de auditoria, versionamento de prontuário e assinatura digital desde a arquitetura — não é um adendo.'],
            ['icon' => 'bi-stars', 'title' => 'IA como apoio, não como decisão', 'text' => 'Assistente de IA sempre com fontes quando possível e conduta final validada pelo médico responsável.'],
            ['icon' => 'bi-headset', 'title' => 'Suporte que entende de clínica', 'text' => 'Time de suporte especializado em rotina oftalmológica, não um atendimento genérico de TI.'],
            ['icon' => 'bi-diagram-3-fill', 'title' => 'Multi-clínica com visão única', 'text' => 'Gerencie várias unidades com um único login e relatórios consolidados.'],
        ],

        // Callout de destaque — exclusividades do Plano Pro. Optotipos e
        // estoque ainda não existem no produto: framing deliberado como
        // "novidade / em breve" (decisão de produto), nunca "já incluído".
        'pro_callout' => [
            'eyebrow' => 'Exclusivo do Plano Pro',
            'title'   => 'O Pro vai além do prontuário',
            'text'    => 'Quem assina o Plano Pro tem acesso a ferramentas de gestão adicionais que nenhum outro plano oferece.',
            'items'   => [
                [
                    'icon'  => 'bi-eye',
                    'badge' => 'Novidade',
                    'title' => 'Programa completo de optotipos',
                    'text'  => 'Os principais testes da rotina oftalmológica dentro do próprio ecossistema EasyEye: ETDRS, Snellen, Ishihara, C de Landolt, E direcional, optotipos infantis, testes de contraste e outros.',
                ],
                [
                    'icon'  => 'bi-box-seam',
                    'badge' => 'Novidade',
                    'title' => 'Gerenciamento e controle de estoque',
                    'text'  => 'Acompanhe e organize materiais e produtos utilizados na operação da clínica, direto no sistema.',
                ],
            ],
            'cta' => 'Conhecer o Plano Pro',
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
        // Mini-callout dentro do card Pro — mesmos itens do differentiators.pro_callout,
        // versão compacta pra reforçar no exato momento da comparação de planos.
        'pro_exclusive_label' => 'Recursos adicionais do Pro',
        'pro_exclusive_new'   => 'Novidade',
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

    'contact' => [
        'label'         => 'Contato',
        'headline_pre'  => 'Quer falar com o',
        'headline_post' => 'Nossa equipe está pronta para te atender!',
        'title'         => 'Fale com nossa equipe',
        'subtitle'      => 'Especialistas em gestão oftalmológica prontos para ajudar você a transformar sua clínica.',

        'sales' => [
            'title'   => 'Central de Vendas',
            'desc'    => 'Tire dúvidas sobre planos, funcionalidades e integrações. Nossa equipe conhece a fundo a rotina clínica.',
            'cta'     => 'Falar no WhatsApp',
            'hours'   => 'Seg–Sex, 8h às 18h',
            'channel' => 'Atendimento via WhatsApp',
        ],
        'support' => [
            'title'   => 'Suporte Técnico',
            'desc'    => 'Atendimento por chat e e-mail com SLA definido por plano. Planos Pro e Premium têm prioridade.',
            'cta'     => 'Enviar e-mail',
            'hours'   => 'Seg–Sex, 8h às 18h',
            'channel' => 'suporte@easyeye.com.br',
        ],
        'trial' => [
            'title' => 'Comece Gratuitamente',
            'desc'  => ':days dias sem cartão de crédito. Configure sua clínica em menos de um dia e comece a atender com prontuário digital.',
            'cta'   => 'Criar conta grátis',
            'badge' => 'Mais popular',
            'note'  => 'Sem taxa de implantação',
        ],

        'aside' => [
            'hours_title'  => 'Horário de atendimento',
            'hours_body'   => 'Segunda a sexta-feira, das 8h às 18h (Horário de Brasília).',
            'chat_title'   => 'Chat no sistema',
            'chat_body'    => 'Clientes ativos têm acesso ao suporte por chat diretamente no painel EasyEye.',
            'quote_text'   => 'O suporte do EasyEye resolveu nosso problema em menos de uma hora. Equipe super preparada em oftalmologia.',
            'quote_author' => 'Dra. Mariana Costa — Clínica Visão SP',
        ],

        'form' => [
            'title'          => 'Envie sua mensagem',
            'subtitle'       => 'Preencha o formulário e retornamos em até 1 dia útil.',
            'name'           => 'Nome completo',
            'name_ph'        => 'Seu nome',
            'email'          => 'E-mail',
            'phone'          => 'WhatsApp com DDD',
            'is_client'      => 'Você é cliente?',
            'is_client_opts' => ['Sim', 'Não', 'Ex-cliente'],
            'role'           => 'Cargo',
            'role_opts'      => ['Médico(a) Oftalmologista', 'Gestor(a) de Clínica', 'Administrativo', 'TI / Tecnologia', 'Outro'],
            'segment'        => 'Tipo de estabelecimento',
            'segment_opts'   => ['Consultório individual', 'Clínica oftalmológica', 'Rede de clínicas', 'Hospital / Ambulatório', 'Plano de saúde', 'Outro'],
            'select'         => 'Selecione',
            'terms'          => 'Li e concordo com a <a href="#">Política de Privacidade</a> e autorizo o EasyEye a entrar em contato comigo.',
            'submit'         => 'Enviar mensagem',
            'sending'        => 'Enviando...',
            'success_title'  => 'Mensagem enviada!',
            'success_body'   => 'Nossa equipe entrará em contato em até 1 dia útil. Fique de olho no seu e-mail!',
        ],

        'trust_ssl'  => 'Criptografia SSL',
        'trust_lgpd' => 'Conformidade LGPD',
        'trust_cfm'  => 'Homologado CFM',
        'trust_nps'  => '97% de satisfação',
    ],
];
