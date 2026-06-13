<?php

namespace App\Support;

use App\Enums\{ClientRule, FeatureKey};
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

        $nav = [
            [
                'key'   => 'dashboard',
                'route' => 'panel.dashboard',
                'icon'  => 'ti ti-layout-dashboard',
                'label' => __('actions.sidemenu.dashboard'),
                'match' => ['panel.dashboard'],
            ],
            [
                'key'   => 'schedules',
                'route' => 'panel.schedules.index',
                'icon'  => 'ti ti-calendar',
                'label' => __('actions.sidemenu.schedules'),
                'match' => ['panel.schedules.*'],
            ],
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

            $nav[] = [
                'section' => __('actions.sidemenu.reports'),
            ];
            $nav[] = [
                'key'   => 'reports',
                'route' => 'panel.reports.index',
                'icon'  => 'ti ti-chart-bar',
                'label' => __('actions.sidemenu.reports'),
                'match' => ['panel.reports.*'],
            ];
        }

        if ($isAdmin) {
            $entityId          = (string) session('selected_entity_id');
            $featureGate       = app(FeatureGateService::class);
            $canSeeOwnGateways = $entityId !== ''
                && $featureGate->can($entityId, FeatureKey::HasOwnPaymentGateways);

            $settingsChildren = [
                ['route' => 'panel.setting.covenants.index', 'label' => __('actions.sidemenu.covenants'), 'match' => ['panel.setting.covenants.*']],
                ['route' => 'panel.setting.skintypes.index', 'label' => __('actions.sidemenu.skintypes'), 'match' => ['panel.setting.skintypes.*']],
                ['route' => 'panel.setting.iristypes.index', 'label' => __('actions.sidemenu.iristypes'), 'match' => ['panel.setting.iristypes.*']],
                ['route' => 'panel.setting.visittypes.index', 'label' => __('actions.sidemenu.visittypes'), 'match' => ['panel.setting.visittypes.*']],
                ['route' => 'panel.setting.additiontypes.index', 'label' => __('actions.sidemenu.additiontypes'), 'match' => ['panel.setting.additiontypes.*']],
                ['route' => 'panel.setting.surgerytypes.index', 'label' => __('actions.sidemenu.surgerytypes'), 'match' => ['panel.setting.surgerytypes.*']],
                ['route' => 'panel.setting.covertesttypes.index', 'label' => __('actions.sidemenu.colorvisiontypes'), 'match' => ['panel.setting.covertesttypes.*']],
                ['route' => 'panel.setting.colorvisiontypes.index', 'label' => __('actions.sidemenu.colorvisiontypes'), 'match' => ['panel.setting.colorvisiontypes.*']],
                ['route' => 'panel.setting.visualacuitytypes.index', 'label' => __('actions.sidemenu.visualacuitytypes'), 'match' => ['panel.setting.visualacuitytypes.*']],
                ['route' => 'panel.setting.lenses.index', 'label' => __('actions.sidemenu.lenses'), 'match' => ['panel.setting.lenses.*']],
                ['route' => 'panel.setting.nearpointconvergences.index', 'label' => __('actions.sidemenu.nearpointconvergences'), 'match' => ['panel.setting.nearpointconvergences.*']],
                ['route' => 'panel.setting.report-settings.index', 'label' => __('actions.report_settings.title'), 'match' => ['panel.setting.report-settings.*']],
            ];

            // Gateways de pagamento — só aparece se o plano da clínica habilitar.
            if ($canSeeOwnGateways) {
                $settingsChildren[] = ['route' => 'panel.setting.gateways.index', 'label' => __('actions.sidemenu.payment_gateways'), 'match' => ['panel.setting.gateways.*']];
            }

            $settingsChildren[] = ['route' => 'panel.setting.resources.index', 'label' => __('actions.sidemenu.resources'), 'match' => ['panel.setting.resources.*']];

            $nav[] = ['section' => __('actions.sidemenu.settings')];
            $nav[] = [
                'key'      => 'settings',
                'icon'     => 'ti ti-settings',
                'label'    => __('actions.sidemenu.settings'),
                'match'    => ['panel.setting.*'],
                'children' => $settingsChildren,
            ];

            $nav[] = ['section' => __('actions.sidemenu.access_control')];
            $nav[] = [
                'key'   => 'users',
                'route' => 'panel.accesscontrol.users.index',
                'icon'  => 'ti ti-users-group',
                'label' => __('actions.users'),
                'match' => ['panel.accesscontrol.users.*'],
            ];
            // Segurança / 2FA por empresa fica em Controle de acesso —
            // semanticamente mais próximo de "usuários e autenticação" do
            // que de "configurações operacionais (convênios, lentes, etc.)".
            $nav[] = [
                'key'   => 'security',
                'route' => 'panel.setting.security.index',
                'icon'  => 'ti ti-shield-lock',
                'label' => __('manager_hardening.entity_2fa_section'),
                'match' => ['panel.setting.security.*'],
            ];
        }

        return $nav;
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

    private static function managerNav(): array
    {
        return [
            [
                'key'   => 'dashboard',
                'route' => 'panel.dashboard',
                'icon'  => 'ti ti-layout-dashboard',
                'label' => __('actions.sidemenu.dashboard'),
                'match' => ['panel.dashboard'],
            ],
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
                'key'   => 'ai-credit-purchases',
                'route' => 'manager.ai-credit-purchases.index',
                'icon'  => 'ti ti-coin',
                'label' => __('actions.sidemenu.ai_credit_purchases'),
                'match' => ['manager.ai-credit-purchases.*'],
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
        ];
    }
}
