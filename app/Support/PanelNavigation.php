<?php

namespace App\Support;

use App\Enums\{ClientRule, FeatureKey, Permission, SaasRule};
use App\Models\Entity;
use App\Services\FeatureGateService;

class PanelNavigation
{
    public static function build(): array
    {
        $rule          = session('selected_entity_user_rule');
        $isClient      = (bool) session('selected_entity_is_client');
        $isAdmin       = $rule === ClientRule::Admin->value;
        $isFinancial   = in_array($rule, [ClientRule::Admin->value, ClientRule::Financial->value], true);
        $canSeeDoctors = in_array($rule, [ClientRule::Admin->value, ClientRule::Secretary->value], true);
        $canSeeAi      = self::canSeeAi($rule);

        if (! $isClient) {
            return self::managerNav();
        }

        // GAP fechado (revisão "relatório direto no módulo"):
        // App\Http\Controllers\ReportsController tinha uma página-hub
        // PRÓPRIA ("Relatórios", 2 cards — Produção e Absenteísmo)
        // inteiramente sobre dado de AGENDA, vivendo num menu SOLTO,
        // separado de "Agendas". Página-hub removida
        // (ReportsController::index() + Panel/Reports/Index.vue); URL das
        // 2 rotas migrou pra baixo de /panel/schedules (nome de rota
        // panel.schedules.reports.*).
        //
        // DECISÃO FINAL (pedido explícito do usuário, revertendo uma
        // tentativa anterior de virar submenu aqui): "Agendas" continua
        // link DIRETO — 1 clique pro calendário, a tela mais usada do
        // painel, sem virar dropdown. Acesso aos 2 relatórios não é mais
        // pelo menu lateral nenhum: fica só no dropdown "Relatórios" ao
        // lado do botão "Novo" dentro da própria tela de Agendas (ver
        // SchedulesController::index() prop `reportsUrls` +
        // Panel/Schedules/Index.vue) — mesmo gate (EntityGate::ViewFinancial)
        // decide se o botão aparece, então quem não tem acesso simplesmente
        // não vê a opção, sem precisar de lógica condicional aqui no menu.
        $schedulesItem = [
            'key'   => 'schedules',
            'route' => 'panel.schedules.index',
            'icon'  => 'ti ti-calendar',
            'label' => __('actions.sidemenu.schedules'),
            // Wildcard cobre panel.schedules.reports.* também (item
            // continua "ativo" no menu enquanto o usuário está num
            // relatório de agenda — não há mais submenu pra destacar em
            // separado).
            'match' => ['panel.schedules.*'],
        ];

        $nav = [
            [
                'key'   => 'dashboard',
                'route' => 'panel.dashboard',
                'icon'  => 'ti ti-layout-dashboard',
                'label' => __('actions.sidemenu.dashboard'),
                'match' => ['panel.dashboard'],
            ],
            $schedulesItem,
            [
                'key'   => 'patients',
                'route' => 'panel.patients.index',
                'icon'  => 'ti ti-users',
                'label' => __('actions.sidemenu.patients'),
                'match' => ['panel.patients.*'],
            ],
        ];

        if ($canSeeDoctors) {
            $nav[] = [
                'key'   => 'doctors',
                'route' => 'panel.doctors.index',
                'icon'  => 'ti ti-stethoscope',
                'label' => __('actions.sidemenu.doctors'),
                'match' => ['panel.doctors.*'],
            ];
        }

        $nav[] = [
            'key'   => 'eye-images',
            'route' => 'panel.eye-images.index',
            'icon'  => 'ti ti-eye',
            'label' => __('dashboard.module_eye_images'),
            'match' => ['panel.eye-images.*'],
        ];

        if ($canSeeAi) {
            $isDoctor = $rule === ClientRule::Doctor->value;

            // Médicos veem submenu (Dashboard + Meus prompts). Demais roles
            // só veem o link direto para o dashboard de uso.
            if ($isDoctor) {
                $nav[] = [
                    'key'      => 'ai',
                    'icon'     => 'ti ti-robot',
                    'label'    => __('actions.sidemenu.ai_assistant'),
                    'match'    => ['panel.ai-runs.*', 'panel.setting.ai-prompts.*'],
                    'children' => [
                        ['route' => 'panel.ai-runs.index', 'icon' => 'ti ti-chart-pie', 'label' => __('actions.sidemenu.ai_usage'), 'match' => ['panel.ai-runs.index']],
                        ['route' => 'panel.setting.ai-prompts.index', 'icon' => 'ti ti-bookmark', 'label' => __('actions.sidemenu.ai_prompts'), 'match' => ['panel.setting.ai-prompts.*']],
                    ],
                ];
            } else {
                $nav[] = [
                    'key'   => 'ai',
                    'route' => 'panel.ai-runs.index',
                    'icon'  => 'ti ti-robot',
                    'label' => __('actions.sidemenu.ai_assistant'),
                    'match' => ['panel.ai-runs.*'],
                ];
            }
        }

        // Estoque (Fase 1): produtos/materiais + movimentação. Gate duplo já
        // acontece no middleware da rota (permission:stock.manage +
        // feature:has_inventory_module); aqui decide VISIBILIDADE do item de
        // menu — e precisa espelhar OS DOIS, não só a permission.
        //
        // BUG REAL (achado ao investigar "clico no menu e não acontece
        // nada"): a condição aqui só checava $isAdmin/hasStockManagePermission()
        // — igual a AI Assistant (canSeeAi() abaixo) já fazia certo pro CASO
        // DELE, mas o Estoque tinha ficado sem o `&& hasInventoryModuleFeature()`.
        // Resultado: pra QUALQUER clínica cujo plano não tem
        // has_inventory_module habilitado (nenhum dos 3 planos reais tinha,
        // na revisão desta correção), o admin via o menu inteiro (bypass de
        // permission), clicava num filho, o middleware `feature:` da rota
        // barrava com FeatureDeniedException, e essa exception faz
        // `back()->withErrors(...)` — MAS nenhuma tela do painel renderiza
        // `errors.feature_denied`/`flash.feature_denied` (confirmado: zero
        // ocorrência em resources/js). O navegador dá a volta completa e
        // devolve o usuário pro MESMO lugar, sem nenhuma mensagem — daí a
        // sensação de "não acontece nada". Fix aqui deixa o menu já nascer
        // condizente com o que a rota realmente permite; o plano do usuário
        // também precisou ganhar a feature habilitada (dado, não código —
        // feito via Manager > Planos).
        //
        // Posição ACIMA de Financeiro (pedido explícito do usuário): estoque
        // é rotina operacional mais frequente que fluxo de caixa/faturamento
        // pro perfil que usa este módulo — ver ordem do array $nav abaixo.
        if (($isAdmin || self::hasStockManagePermission()) && self::hasInventoryModuleFeature()) {
            $nav[] = [
                'key' => 'stock',
                // BUG REAL (achado ao investigar "sem ícone nenhum" no
                // sidebar recolhido): `ti-boxes` NÃO EXISTE no set Tabler
                // Icons deste projeto (confirmado em
                // node_modules/@tabler/icons-webfont — só `ti-box`/
                // `ti-box-multiple`, nunca a forma plural "boxes"; provável
                // confusão com `fa-boxes` do FontAwesome, usado em outras
                // partes do painel). `<i>` sem classe CSS válida renderiza
                // vazio — some só no modo colapsado (ícone é a ÚNICA coisa
                // visível ali; expandido, o texto ao lado escondia o buraco).
                'icon'     => 'ti ti-building-warehouse',
                'label'    => __('actions.sidemenu.stock'),
                'match'    => ['panel.stock.*'],
                'children' => [
                    ['route' => 'panel.stock.products.index', 'icon' => 'ti ti-package', 'label' => __('actions.sidemenu.products'), 'match' => ['panel.stock.products.*']],
                    ['route' => 'panel.stock.movements.index', 'icon' => 'ti ti-transfer-in', 'label' => __('actions.sidemenu.stock_movements'), 'match' => ['panel.stock.movements.*']],
                    ['route' => 'panel.stock.purchase-orders.index', 'icon' => 'ti ti-shopping-cart', 'label' => __('actions.sidemenu.purchase_orders'), 'match' => ['panel.stock.purchase-orders.*']],
                    ['route' => 'panel.stock.suppliers.index', 'icon' => 'ti ti-truck-delivery', 'label' => __('actions.sidemenu.suppliers'), 'match' => ['panel.stock.suppliers.*']],
                    ['route' => 'panel.stock.reports.index', 'icon' => 'ti ti-chart-bar', 'label' => __('actions.sidemenu.stock_reports'), 'match' => ['panel.stock.reports.*']],
                ],
            ];
        }

        if ($isFinancial) {
            $nav[] = [
                'key'      => 'financial',
                'icon'     => 'ti ti-cash-register',
                'label'    => __('actions.sidemenu.financial'),
                'match'    => ['panel.financial.*'],
                'children' => [
                    ['route' => 'panel.financial.bi.index', 'icon' => 'ti ti-layout-dashboard', 'label' => __('actions.sidemenu.management_dashboard'), 'match' => ['panel.financial.bi.*']],
                    ['route' => 'panel.financial.cash-flow.index', 'icon' => 'ti ti-building-bank', 'label' => __('actions.sidemenu.cash_flow'), 'match' => ['panel.financial.cash-flow.*']],
                    ['route' => 'panel.financial.billing.index', 'icon' => 'ti ti-file-invoice', 'label' => __('actions.sidemenu.tiss_billing'), 'match' => ['panel.financial.billing.*']],
                    ['route' => 'panel.financial.tiss.glosas.index', 'icon' => 'ti ti-gavel', 'label' => __('actions.sidemenu.tiss_glosas'), 'match' => ['panel.financial.tiss.glosas.*']],
                    ['route' => 'panel.financial.reports.cash-flow', 'icon' => 'ti ti-chart-arcs', 'label' => __('actions.sidemenu.report_cash_flow'), 'match' => ['panel.financial.reports.cash-flow*']],
                    ['route' => 'panel.financial.reports.covenants', 'icon' => 'ti ti-report-money', 'label' => __('actions.sidemenu.report_billing'), 'match' => ['panel.financial.reports.covenants*']],
                ],
            ];
        }

        if ($isAdmin) {
            // ── Configurações reorganizadas em 5 categorias (era 1 grupo
            // flat com 12+ itens soltos + "Controle de acesso" à parte).
            // "Usuários" e "Segurança" saem do antigo grupo solto e entram
            // nas categorias semanticamente corretas abaixo.

            // Clínica: recursos físicos da clínica (salas/equipamentos) e
            // segurança/2FA. Gateways próprios da clínica: REMOVIDO — a
            // funcionalidade não existe mais para clínicas.
            $clinicalChildren = [
                // Rota/controller continuam "resources" — só o label do menu
                // muda para "Unidades / salas" (ClinicResource não é renomeado).
                ['route' => 'panel.setting.resources.index', 'label' => __('actions.sidemenu.clinic_resources'), 'match' => ['panel.setting.resources.*']],
                ['route' => 'panel.setting.security.index', 'label' => __('manager_hardening.entity_2fa_section'), 'match' => ['panel.setting.security.*']],
            ];

            // Atendimento: catálogos ligados ao fluxo de agendamento/atendimento.
            $attendanceChildren = [
                ['route' => 'panel.setting.call-panel.index', 'label' => __('schedules.call_panel_title'), 'match' => ['panel.setting.call-panel.*']],
                ['route' => 'panel.setting.covenants.index', 'label' => __('actions.sidemenu.covenants'), 'match' => ['panel.setting.covenants.*']],
                ['route' => 'panel.setting.visittypes.index', 'label' => __('actions.sidemenu.visittypes'), 'match' => ['panel.setting.visittypes.*']],
                ['route' => 'panel.setting.surgerytypes.index', 'label' => __('actions.sidemenu.surgerytypes'), 'match' => ['panel.setting.surgerytypes.*']],
                ['route' => 'panel.setting.iollenses.index', 'label' => __('actions.sidemenu.iol_lenses'), 'match' => ['panel.setting.iollenses.*']],
                ['route' => 'panel.setting.product-categories.index', 'label' => __('actions.sidemenu.product_categories'), 'match' => ['panel.setting.product-categories.*']],
            ];

            // Usuários e permissões: identidade + RBAC granular (roles).
            $usersChildren = [
                ['route' => 'panel.accesscontrol.users.index', 'label' => __('actions.users'), 'match' => ['panel.accesscontrol.users.*']],
                ['route' => 'panel.accesscontrol.roles.index', 'label' => __('actions.sidemenu.roles'), 'match' => ['panel.accesscontrol.roles.*']],
            ];

            // Documentos: modelos de documentação clínica (receituários,
            // laudos, atestados...) via DocumentationType — um único item.
            $documentsChildren = [
                ['route' => 'panel.setting.report-settings.index', 'label' => __('actions.report_settings.title'), 'match' => ['panel.setting.report-settings.*']],
            ];

            // Oftalmologia: os 8 sub-catálogos clínicos viram ABAS dentro de
            // uma única página ("Parâmetros oftalmológicos"), não itens soltos
            // no menu (ver BaseSettingController::$tabsGroup). O match cobre
            // as 8 rotas para o item de menu ficar "ativo" em qualquer aba.
            $ophthalmologyMatch = [
                'panel.setting.skintypes.*',
                'panel.setting.iristypes.*',
                'panel.setting.additiontypes.*',
                'panel.setting.visualacuitytypes.*',
                'panel.setting.colorvisiontypes.*',
                'panel.setting.nearpointconvergences.*',
                'panel.setting.covertesttypes.*',
                'panel.setting.lenses.*',
            ];
            $ophthalmologyChildren = [
                ['route' => 'panel.setting.skintypes.index', 'label' => __('actions.sidemenu.ophthalmology_parameters'), 'match' => $ophthalmologyMatch],
            ];

            $nav[] = ['section' => __('actions.sidemenu.settings')];
            $nav[] = [
                'key'      => 'settings-clinical',
                'icon'     => 'ti ti-building-hospital',
                'label'    => __('actions.sidemenu.settings_clinical'),
                'match'    => array_merge(...array_column($clinicalChildren, 'match')),
                'children' => $clinicalChildren,
            ];
            $nav[] = [
                'key'      => 'settings-attendance',
                'icon'     => 'ti ti-clipboard-list',
                'label'    => __('actions.sidemenu.settings_attendance'),
                'match'    => array_merge(...array_column($attendanceChildren, 'match')),
                'children' => $attendanceChildren,
            ];
            $nav[] = [
                'key'      => 'settings-users',
                'icon'     => 'ti ti-users-group',
                'label'    => __('actions.sidemenu.settings_users'),
                'match'    => array_merge(...array_column($usersChildren, 'match')),
                'children' => $usersChildren,
            ];
            $nav[] = [
                'key'      => 'settings-documents',
                'icon'     => 'ti ti-file-text',
                'label'    => __('actions.sidemenu.settings_documents'),
                'match'    => array_merge(...array_column($documentsChildren, 'match')),
                'children' => $documentsChildren,
            ];
            $nav[] = [
                'key'      => 'settings-ophthalmology',
                'icon'     => 'ti ti-eye',
                'label'    => __('actions.sidemenu.settings_ophthalmology'),
                'match'    => $ophthalmologyMatch,
                'children' => $ophthalmologyChildren,
            ];
        }

        return $nav;
    }

    /**
     * `is_owner` do usuário logado na entity SaaS selecionada — checagem sob
     * demanda (não cacheada em sessão, ao contrário de selected_entity_user_rule),
     * usada só para decidir se o item "Finanças" aparece no menu.
     */
    private static function currentUserOwnsSelectedEntity(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $entity = Entity::find(session('selected_entity_id'));

        return $entity && $user->isOwnerOfEntity($entity);
    }

    private static function canSeeAi(?string $rule): bool
    {
        if (! in_array($rule, [ClientRule::Admin->value, ClientRule::Doctor->value, ClientRule::Secretary->value], true)) {
            return false;
        }

        $entityId = session('selected_entity_id');

        if (! $entityId) {
            return false;
        }

        $featureGate = app(FeatureGateService::class);

        return $featureGate->can((string) $entityId, FeatureKey::HasAiExamAssistant)
            || $featureGate->can((string) $entityId, FeatureKey::HasAiReportDrafting);
    }

    /**
     * True pra admin (bypass interno de hasPermissionInEntity()) OU usuário
     * com Role customizada portando a permission granular `stock.manage`.
     * `auth()->user()` (não passado como parâmetro) porque build() é
     * chamado sem argumentos por HandleInertiaRequests — mesmo padrão de
     * canSeeAi() acima resolvendo tudo a partir de session()/helpers globais.
     */
    private static function hasStockManagePermission(): bool
    {
        $entityId = session('selected_entity_id');
        $user     = auth()->user();

        if (! $entityId || ! $user) {
            return false;
        }

        $entity = Entity::find($entityId);

        if (! $entity) {
            return false;
        }

        return $user->hasPermissionInEntity($entity, Permission::StockManage);
    }

    /**
     * Espelha o middleware `feature:has_inventory_module` das rotas de
     * estoque (routes/web.php) — mesmo padrão de canSeeAi() acima pras
     * features de IA. Sem isso o menu aparece pra quem tem
     * stock.manage/é admin mas o PLANO da clínica não inclui o módulo, e
     * clicar em qualquer item bate no gate da rota e devolve o usuário pro
     * mesmo lugar sem aviso nenhum (ver comentário no bloco de montagem do
     * menu acima).
     */
    private static function hasInventoryModuleFeature(): bool
    {
        $entityId = session('selected_entity_id');

        if (! $entityId) {
            return false;
        }

        return app(FeatureGateService::class)->can((string) $entityId, FeatureKey::HasInventoryModule);
    }

    private static function managerNav(): array
    {
        $nav = [
            [
                'key'   => 'dashboard',
                'route' => 'panel.dashboard',
                'icon'  => 'ti ti-layout-dashboard',
                'label' => __('actions.sidemenu.dashboard'),
                'match' => ['panel.dashboard'],
            ],
        ];

        // P&L interno do EasyEye: item de menu só aparece pra quem realmente
        // vai passar no Gate SaasOwnerFinancial (admin OU dono) — evita um
        // Financial/Support ver o link e cair num 403 ao clicar. Única
        // checagem deste arquivo que consulta o banco (is_owner não é
        // cacheado em sessão como selected_entity_user_rule) — aceitável:
        // só roda pra staff do manager, não no hot path das clínicas.
        $rule = session('selected_entity_user_rule');

        if ($rule === SaasRule::Admin->value || self::currentUserOwnsSelectedEntity()) {
            $nav[] = [
                'key'   => 'finance',
                'route' => 'manager.finance.index',
                'icon'  => 'ti ti-report-money',
                'label' => __('actions.sidemenu.finance'),
                'match' => ['manager.finance.*'],
            ];
        }

        return array_merge($nav, [
            [
                'key'   => 'entities',
                'route' => 'manager.entities.index',
                'icon'  => 'ti ti-building',
                'label' => __('actions.sidemenu.entities'),
                'match' => ['manager.entities.*'],
            ],
            [
                'key'   => 'plans',
                'route' => 'manager.plans.index',
                'icon'  => 'ti ti-package',
                'label' => __('actions.sidemenu.plans'),
                'match' => ['manager.plans.*'],
            ],
            [
                'key'   => 'subscriptions',
                'route' => 'manager.subscriptions.index',
                'icon'  => 'ti ti-file-invoice',
                'label' => __('actions.sidemenu.subscriptions'),
                'match' => ['manager.subscriptions.*'],
            ],
            [
                'key'   => 'gateways',
                'route' => 'manager.gateways.index',
                'icon'  => 'ti ti-credit-card',
                'label' => __('actions.sidemenu.gateways'),
                'match' => ['manager.gateways.*'],
            ],
            [
                'key'   => 'report-settings',
                'route' => 'manager.report-settings.index',
                'icon'  => 'ti ti-file-description',
                'label' => __('actions.report_settings.title'),
                'match' => ['manager.report-settings.*'],
            ],
            [
                'key'   => 'partners',
                'route' => 'manager.partners.index',
                'icon'  => 'ti ti-affiliate',
                'label' => __('actions.sidemenu.partners'),
                'match' => ['manager.partners.*'],
            ],
            [
                'key'   => 'ai-providers',
                'route' => 'manager.ai-providers.index',
                'icon'  => 'ti ti-sparkles',
                'label' => __('actions.sidemenu.ai_providers'),
                'match' => ['manager.ai-providers.*'],
            ],
            [
                'key'   => 'ai-credit-purchases',
                'route' => 'manager.ai-credit-purchases.index',
                'icon'  => 'ti ti-coin',
                'label' => __('actions.sidemenu.ai_credit_purchases'),
                'match' => ['manager.ai-credit-purchases.*'],
            ],
            [
                'key'   => 'whatsapp',
                'route' => 'manager.whatsapp.index',
                'icon'  => 'ti ti-brand-whatsapp',
                'label' => __('whatsapp.title'),
                'match' => ['manager.whatsapp.*'],
            ],

            // ── Controle de acesso ──────────────────────────────────────────
            // Agrupa identidade + autenticação (usuários + 2FA da entity SaaS).
            // Forçar 2FA em uma clínica cliente continua sendo via aba
            // "Segurança" no EntityFormModal de /panel/manager/entities.
            ['section' => __('actions.sidemenu.access_control')],
            [
                'key'   => 'users',
                'route' => 'panel.accesscontrol.users.index',
                'icon'  => 'ti ti-users-group',
                'label' => __('actions.sidemenu.users'),
                'match' => ['panel.accesscontrol.users.*'],
            ],
            [
                'key'   => 'security',
                'route' => 'panel.setting.security.index',
                'icon'  => 'ti ti-shield-lock',
                'label' => __('manager_hardening.entity_2fa_section'),
                'match' => ['panel.setting.security.*'],
            ],
        ]);
    }
}
