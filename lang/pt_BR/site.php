<?php

return [
    'meta' => [
        // O callback de título do Inertia (site.js) já prefixa "EasyEye — ".
        'title'          => 'Sistema para clínicas oftalmológicas',
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
        'menu'         => 'Menu',
        'skip'         => 'Pular para o conteúdo',
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

    // Páginas /privacidade e /termos (versão vigente de term_versions).
    'legal' => [
        'privacy_title'       => 'Política de Privacidade',
        'terms_title'         => 'Termos de Uso',
        'privacy_description' => 'Política de Privacidade do EasyEye: como tratamos dados pessoais de clínicas, profissionais e pacientes.',
        'terms_description'   => 'Termos de Uso da plataforma EasyEye.',
        'version'             => 'Versão :version · vigente desde :date',
        'contents'            => 'Neste documento',
        'unavailable_title'   => 'Documento em publicação',
        'unavailable_text'    => 'A versão oficial deste documento ainda não foi publicada aqui. Para recebê-la agora, escreva para',
        'back_home'           => 'Voltar para o início',
        // Avisos de idioma: o texto oficial é o em português; em outro idioma a
        // página mostra a tradução de cortesia (ou o original, se não houver).
        'translation_notice' => 'Tradução de cortesia. Em caso de divergência, prevalece a versão original em português.',
        'read_original'      => 'Ler o original em português',
        'original_notice'    => 'Você está lendo o texto original em português, que é a versão que vale.',
        'read_translation'   => 'Voltar para a tradução',
        'original_only'      => 'Este documento está disponível apenas em português, que é a versão que vale.',
    ],

    'hero' => [
        'badge'    => 'Novo: TISS 3.06 integrado e homologado',
        'title'    => 'Gestão completa para clínicas',
        'title_em' => 'oftalmológicas',
        'subtitle' => 'Do agendamento ao prontuário eletrônico com TISS integrado. Automatize processos, reduza glosas e foque no que realmente importa: a saúde dos seus pacientes.',
        // Microcopy sob os botões, só quando algum plano tem teste grátis (dias vêm do banco).
        'cta_note'      => ':days dias grátis · sem cartão de crédito',
        'cta_primary'   => 'Começar grátis',
        'cta_secondary' => 'Ver o sistema por dentro',
        'trust'         => 'Mais de <strong style="color:#fff;">:count clínicas</strong> confiam no EasyEye',
        // Iniciais de quem deu os depoimentos (Dr. Ricardo Mendes, Dra. Ana Carvalho, Paulo Souza, Dra. Mariana Costa).
        'trust_initials' => ['RM', 'AC', 'PS', 'MC'],
        // Recorte real do prontuário (public/site/images/hero-prontuario.webp), sem dados de paciente.
        'visual_alt'   => 'Painel inicial do EasyEye com total de pacientes, consultas e médicos do dia, atalhos e a agenda de hoje (dados fictícios)',
        'card_top_lbl' => 'Imagens por olho',
        'card_top_val' => 'OCT, retinografia e biometria',
        'card_bot_lbl' => 'CFM e LGPD',
        'card_bot_val' => 'Prontuário assinado e versionado',
    ],

    'metrics' => [
        ['value' => '500+', 'amount' => 500, 'suffix' => '+', 'label' => 'Clínicas ativas'],
        ['value' => '50k+', 'amount' => 50, 'suffix' => 'k+', 'label' => 'Consultas por mês'],
        ['value' => '99,9%', 'amount' => 99.9, 'decimals' => 1, 'suffix' => '%', 'label' => 'Disponibilidade garantida'],
        ['value' => 'R$0', 'amount' => 0, 'prefix' => 'R$', 'label' => 'Taxa de implantação'],
    ],

    // ── Problemas que o EasyEye resolve ──────────────────────────────────
    // 4 dores, uma por público (recepção, consultório, faturamento) mais a
    // de sistemas soltos; laudos demorados foi para o bloco do consultório.
    'problems' => [
        'label'    => 'O dia a dia sem o EasyEye',
        'title'    => 'Sua clínica ainda perde tempo (e dinheiro) com isso?',
        'subtitle' => 'Problemas comuns em clínicas oftalmológicas que ainda dependem de papel, planilhas soltas e sistemas genéricos.',
        'items'    => [
            ['icon' => 'ti-calendar-x', 'title' => 'Agenda manual, no-show e retrabalho', 'text' => 'Sem confirmação automática, faltas derrubam a produtividade do dia e da equipe.'],
            ['icon' => 'ti-files', 'title' => 'Prontuário e exames espalhados', 'text' => 'Histórico em papel ou planilhas e exames de aparelhos em pen-drive, e-mail ou pastas: difícil de achar na consulta seguinte e sujeito a perda.'],
            ['icon' => 'ti-receipt-off', 'title' => 'Faturamento TISS manual e cheio de glosa', 'text' => 'Guias preenchidas à mão, retrabalho com convênio e receita que demora a entrar no caixa.'],
            ['icon' => 'ti-topology-star-3', 'title' => 'Sistemas soltos, sem visão única do paciente', 'text' => 'Agenda, prontuário e exames em ferramentas diferentes — a equipe perde tempo cruzando informação.'],
        ],
        'bridge' => 'É exatamente isso que o EasyEye resolve.',
    ],

    // ── Funcionalidades por público (antes: Benefícios + Funcionalidades,
    // 14 cards repetindo as mesmas capacidades) ─────────────────────────
    // `feature` liga o item a uma FeatureKey: a tela mostra "Disponível no …"
    // a partir dos planos do banco quando nem todos os planos incluem.
    'audiences' => [
        'label'        => 'Funcionalidades',
        'title'        => 'Tudo que sua clínica precisa, em um único lugar',
        'subtitle'     => 'Cada módulo foi pensado para a rotina real de uma clínica oftalmológica — do consultório à recepção.',
        'available_in' => 'Disponível no :plans',
        'groups'       => [
            [
                'key'      => 'recepcao',
                'icon'     => 'ti-calendar-check',
                'title'    => 'Recepção e agenda',
                'audience' => 'Para quem organiza o dia da clínica',
                'items'    => [
                    ['text' => 'Vários médicos, salas e equipamentos na mesma agenda, com lista de espera e bloqueios.'],
                    ['text' => 'Confirmação automática por WhatsApp e SMS, reduzindo no-shows em até 40%.'],
                    ['text' => 'Histórico do paciente reunido: consultas, exames, imagens e documentos.'],
                ],
            ],
            [
                'key'      => 'consultorio',
                'icon'     => 'ti-stethoscope',
                'title'    => 'Consultório',
                'audience' => 'Para o oftalmologista',
                'items'    => [
                    ['text' => 'Prontuário oftalmológico com refração, biomicroscopia, fundoscopia e campos visuais.'],
                    ['text' => 'Imagens e exames organizados por olho e por data, sem pen-drive nem pasta perdida.'],
                    ['text' => 'Integração com os aparelhos da clínica para enviar e guardar os exames.', 'feature' => 'has_api_integrator'],
                    ['text' => 'Modelos prontos de laudo, receituário e atestado.'],
                    ['text' => 'Assistente de IA na redação de laudos — sempre como apoio; a conduta final é do médico.', 'feature' => 'has_ai_report_drafting'],
                ],
            ],
            [
                'key'      => 'faturamento',
                'icon'     => 'ti-receipt',
                'title'    => 'Faturamento e gestão',
                'audience' => 'Para quem cuida dos convênios e do caixa',
                'items'    => [
                    ['text' => 'Guias TISS 3.06, lotes XML, envio eletrônico e retorno, com pré-validação para reduzir glosas.'],
                    ['text' => 'Fluxo de caixa, contas a receber, relatórios gerenciais e gateways de pagamento integrados.'],
                    ['text' => 'Várias unidades com um único login, relatórios consolidados e acesso por perfil.'],
                ],
                'flow_label' => 'Fluxo TISS no EasyEye',
                'flow'       => ['Guia', 'Pré-validação', 'Lote XML', 'Envio', 'Retorno e glosa'],
            ],
        ],
    ],

    'how' => [
        'label'          => 'Como funciona',
        'title'          => 'Implantação simples, resultados imediatos',
        'subtitle'       => 'Em menos de um dia sua clínica já está operando com o EasyEye. Sem instalação, sem servidores, tudo na nuvem.',
        'screenshot_alt' => 'Checklist "Configure sua clínica" do EasyEye, com os passos de implantação marcados como concluídos',
        'steps'          => [
            ['title' => 'Crie sua conta em minutos', 'text' => 'Cadastro rápido, sem burocracia. Configure sua clínica, adicione médicos e defina horários de atendimento.'],
            ['title' => 'Importe seus pacientes', 'text' => 'Importe sua base de pacientes via CSV ou cadastre manualmente. Histórico e prontuários migrados com segurança.'],
            ['title' => 'Comece a atender', 'text' => 'Sua equipe treinada em horas. Suporte dedicado na implantação e atendimento contínuo para crescer com você.'],
        ],
    ],

    // ── Demonstração visual (tour do produto) ────────────────────────────
    // Recortes reais em public/site/images/demo-{key}.webp (sem dados de
    // teste). Aba sem imagem não aparece.
    'demo' => [
        'label'      => 'Conheça o sistema',
        'title'      => 'Veja o EasyEye por dentro',
        'subtitle'   => 'Uma prévia das telas que sua equipe vai usar todos os dias.',
        'fictitious' => 'Dados fictícios.',
        'enlarge'    => 'Ampliar imagem',
        'tabs'       => [
            ['key' => 'prontuario', 'icon' => 'ti-report-medical', 'label' => 'Prontuário', 'caption' => 'Acuidade visual, tonometria, refração, biomicroscopia e fundoscopia por olho.'],
            ['key' => 'imagens', 'icon' => 'ti-photo', 'label' => 'Gerenciador de imagens', 'caption' => 'Exames por data e tipo — OCT, biometria, retinografia — com OD, OE e AO.'],
            ['key' => 'agenda', 'fictitious' => true, 'icon' => 'ti-calendar', 'label' => 'Agenda', 'caption' => 'Agenda do dia com horário, tipo de consulta, convênio e status de cada atendimento.'],
            ['key' => 'laudos', 'icon' => 'ti-file-text', 'label' => 'Laudos e documentos', 'caption' => 'Modelos prontos de laudos, atestados e exames especializados, com cabeçalho e assinatura.'],
        ],
    ],
    'metrics_context' => 'Dados baseados em pesquisa interna com usuários ativos e monitoramento de sistema referente ao ano de 2026.',

    // ── Diferenciais (os 4 do PRODUCT.md) + conformidade na prática ─────
    'differentiators' => [
        'label'    => 'Diferenciais',
        'title'    => 'Por que clínicas escolhem o EasyEye',
        'subtitle' => 'Não é um sistema de gestão genérico adaptado para saúde — é feito para a rotina oftalmológica desde o primeiro dia.',
        'items'    => [
            ['icon' => 'ti-eye', 'title' => 'Feito para oftalmologia', 'text' => 'Campos, laudos e fluxos pensados para a rotina do consultório oftalmológico — não um prontuário genérico adaptado.'],
            ['icon' => 'ti-file-certificate', 'title' => 'TISS 3.06 homologado', 'text' => 'Geração, envio e retorno de guias TISS homologados pela ANS, com pré-validação para reduzir glosas.'],
            ['icon' => 'ti-layout-grid', 'title' => 'Tudo num só sistema', 'text' => 'Agenda, prontuário, imagens, documentos e financeiro no mesmo lugar, sem ferramentas soltas.'],
            ['icon' => 'ti-shield-check', 'title' => 'Conformidade CFM e LGPD desde a arquitetura', 'text' => 'Trilha de auditoria, versionamento de prontuário e assinatura digital desde a arquitetura — não é um adendo.'],
        ],
        'proof_title' => 'Como a conformidade funciona na prática',
        'proof'       => [
            ['icon' => 'ti-history', 'label' => 'Trilha de auditoria de cada alteração'],
            ['icon' => 'ti-lock', 'label' => 'Prontuário travado após a assinatura'],
            ['icon' => 'ti-versions', 'label' => 'Histórico de versões do prontuário'],
            ['icon' => 'ti-eye-check', 'label' => 'Registro de acesso a dados sensíveis'],
            ['icon' => 'ti-file-check', 'label' => 'Consentimentos LGPD do paciente'],
            ['icon' => 'ti-cloud-lock', 'label' => 'Dados criptografados e backup automático'],
        ],
    ],

    'testimonials' => [
        'label'   => 'Depoimentos',
        'title'   => 'O que nossos clientes dizem',
        'context' => 'Relatos reais de gestores e oftalmologistas. Os resultados podem variar de acordo com o porte da clínica.',
        'rating'  => ':stars de 5 estrelas',
        'items'   => [
            [
                'text'     => '"O EasyEye transformou nossa clínica. O faturamento TISS que levava dias agora é feito em horas. Reduzimos as glosas em 60% no primeiro mês."',
                'name'     => 'Dr. Ricardo Mendes',
                'role'     => 'Oftalmologista — Clínica Visão SP',
                'initials' => 'RM',
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
        // Módulos sem trava por plano (conferido nas rotas: só estoque é por plano).
        'included_all_label' => 'Em todos os planos',
        'included_all'       => 'Agenda, prontuário oftalmológico, gerenciador de imagens, laudos e documentos, faturamento TISS e financeiro.',
        // Planos acima do primeiro listam só o que acrescentam.
        'everything_in' => 'Tudo do :plan, mais:',
        // Optotipos ainda não existe no produto: sempre "Em breve", só no card do Premium.
        'upcoming_label' => 'Em breve no Premium',
        'upcoming'       => [
            ['icon' => 'ti-eye', 'title' => 'Programa completo de optotipos', 'badge' => 'Em breve'],
        ],
    ],

    'faq' => [
        'label' => 'Dúvidas frequentes',
        'title' => 'Perguntas frequentes',
        'items' => [
            ['q' => 'Como é feita a migração dos dados da minha clínica?', 'a' => 'Oferecemos importação via CSV para pacientes e histórico. Nossa equipe de implantação auxilia na migração dos dados do sistema anterior sem interrupção do atendimento.'],
            // GAP fechado (correção de conteúdo — a resposta antiga
            // afirmava um cache local de contingência que nunca existiu no
            // produto; confirmado no código: nenhum service worker,
            // localStorage ou IndexedDB guarda dado de agenda/prontuário
            // pra uso offline, só preferência de UI (ex.: modo de
            // visualização da agenda). Resposta corrigida pra refletir o
            // comportamento real.
            ['q' => 'O EasyEye funciona offline?', 'a' => 'O EasyEye é uma solução 100% em nuvem — funciona em qualquer dispositivo com navegador e internet. Não há modo offline no momento: sem conexão, não é possível acessar prontuários, agenda ou os demais dados do sistema.'],
            ['q' => 'Como funciona o suporte técnico?', 'a' => 'Oferecemos suporte por e-mail e WhatsApp (em planos específicos). Nos planos Pro e Premium, o atendimento é prioritário, com SLA de 4 horas úteis.'],
            ['q' => 'O sistema é homologado pela ANS para TISS?', 'a' => 'Sim. O EasyEye é homologado para as versões TISS 3.05 e 3.06 da ANS, com geração de XML, envio e processamento de retorno totalmente automatizados.'],
            ['q' => 'Posso integrar com outros sistemas?', 'a' => 'Disponibilizamos API REST para integração com ERPs, sistemas de imagem (laudos), laboratórios e outros sistemas clínicos. Documentação disponível para desenvolvedores.'],
        ],
    ],

    'cta' => [
        'title' => 'Pronto para transformar sua clínica oftalmológica?',
        // Com teste grátis (dias do banco) ou sem ele.
        'subtitle_trial' => ':days dias gratuitos, sem cartão de crédito. Configure em menos de um dia.',
        'subtitle'       => 'Sem cartão de crédito. Configure em menos de um dia.',
        'primary'        => 'Criar conta grátis',
        'secondary'      => 'Falar com um especialista',
        'note'           => 'Sem taxa de implantação • Cancele quando quiser • Suporte na implantação',
    ],

    'contact' => [
        'label'         => 'Contato',
        'headline_pre'  => 'Quer falar com o',
        'headline_post' => 'Nossa equipe está pronta para te atender!',
        'title'         => 'Fale com nossa equipe',
        'subtitle'      => 'Especialistas em gestão oftalmológica prontos para ajudar você a transformar sua clínica.',

        'sales' => [
            'title'   => 'Central de vendas',
            'desc'    => 'Tire dúvidas sobre planos, funcionalidades e integrações. Nossa equipe conhece a fundo a rotina clínica.',
            'cta'     => 'Falar no WhatsApp',
            'hours'   => 'Seg–Sex, 8h às 18h',
            'channel' => '+55 61 98467-6485',
        ],
        'support' => [
            'title' => 'Suporte técnico',
            'desc'  => 'Atendimento por e-mail com SLA definido por plano. Planos Pro e Premium têm prioridade.',
            'cta'   => 'Enviar e-mail',
            'hours' => 'Seg–Sex, 8h às 18h',
            // E-mail vem de config('mail.support_address') (prop contact.support).
        ],
        'trial' => [
            'title'         => 'Comece gratuitamente',
            'desc'          => ':days dias sem cartão de crédito. Configure sua clínica em menos de um dia e comece a atender com prontuário digital.',
            'desc_no_trial' => 'Sem cartão de crédito. Configure sua clínica em menos de um dia e comece a atender com prontuário digital.',
            'cta'           => 'Criar conta grátis',
            'badge'         => 'Mais popular',
            'note'          => 'Sem taxa de implantação',
        ],

        'aside' => [
            'quote_text'   => 'O suporte do EasyEye resolveu nosso problema em menos de uma hora. Equipe super preparada em oftalmologia.',
            'quote_author' => 'Dra. Mariana Costa — Clínica Visão SP',
        ],

        'form' => [
            'title'          => 'Envie sua mensagem',
            'subtitle'       => 'Preencha o formulário e retornamos em até 1 dia útil.',
            'name'           => 'Nome completo',
            'name_ph'        => 'Seu nome',
            'email'          => 'E-mail',
            'email_ph'       => 'voce@exemplo.com',
            'phone'          => 'WhatsApp com DDD',
            'phone_ph'       => '(00) 00000-0000',
            'message'        => 'Mensagem',
            'message_ph'     => 'Como podemos ajudar?',
            'message_hint'   => 'Até 5.000 caracteres. Não inclua dados de pacientes.',
            'is_client'      => 'Você é cliente?',
            'is_client_opts' => ['Sim', 'Não', 'Ex-cliente'],
            'role'           => 'Cargo',
            'role_opts'      => ['Médico(a) Oftalmologista', 'Gestor(a) de Clínica', 'Administrativo', 'TI / Tecnologia', 'Outro'],
            'segment'        => 'Tipo de estabelecimento',
            'segment_opts'   => ['Consultório individual', 'Clínica oftalmológica', 'Rede de clínicas', 'Hospital / Ambulatório', 'Plano de saúde', 'Outro'],
            'select'         => 'Selecione',
            'terms'          => 'Li e concordo com a <a href="/privacidade" target="_blank">Política de Privacidade</a> e autorizo o EasyEye a entrar em contato comigo.',
            'submit'         => 'Enviar mensagem',
            'sending'        => 'Enviando...',
            'success_title'  => 'Mensagem enviada!',
            'success_body'   => 'Nossa equipe entrará em contato em até 1 dia útil. Fique de olho no seu e-mail!',
            'mail_subject'   => 'Nova mensagem pelo site EasyEye',
            'errors'         => [
                'required'   => 'Preencha este campo.',
                'email'      => 'Informe um e-mail válido.',
                'terms'      => 'Confirme que leu e concorda com os termos para enviar.',
                'invalid'    => 'Revise o valor informado neste campo.',
                'validation' => 'Revise os campos indicados e envie novamente.',
                'server'     => 'Não foi possível confirmar o envio. Seus dados foram mantidos. Tente novamente em instantes.',
                'network'    => 'Não foi possível confirmar o envio. Verifique sua conexão e tente novamente. Seus dados foram mantidos.',
                'timeout'    => 'O envio demorou mais que o esperado e não foi possível confirmá-lo. Seus dados foram mantidos para nova tentativa.',
                'session'    => 'Sua sessão expirou. Copie a mensagem e os dados preenchidos antes de atualizar a página e tentar novamente.',
                'rate_limit' => 'Muitas tentativas em pouco tempo. Aguarde um minuto e tente novamente. Seus dados foram mantidos.',
            ],
        ],

        'trust_ssl'  => 'Criptografia SSL',
        'trust_lgpd' => 'Conformidade LGPD',
        'trust_cfm'  => 'Conformidade CFM',
        'trust_nps'  => '97% de satisfação',
    ],
];
