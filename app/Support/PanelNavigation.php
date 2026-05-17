<?php

namespace App\Support;

use App\Enums\ClientRule;

class PanelNavigation
{
    public static function build(): array
    {
        $rule       = session('selected_entity_user_rule');
        $isClient   = (bool) session('selected_entity_is_client');
        $isAdmin    = $rule === ClientRule::Admin->value;
        $isFinancial = in_array($rule, [ClientRule::Admin->value, ClientRule::Financial->value], true);
        $canSeeDoctors = in_array($rule, [ClientRule::Admin->value, ClientRule::Secretary->value], true);

        if (!$isClient) {
            return self::managerNav();
        }

        $nav = [
            [
                'key'    => 'dashboard',
                'route'  => 'panel.dashboard',
                'icon'   => 'ti ti-layout-dashboard',
                'label'  => __('actions.sidemenu.dashboard'),
                'match'  => ['panel.dashboard'],
            ],
            [
                'key'    => 'schedules',
                'route'  => 'panel.schedules.index',
                'icon'   => 'ti ti-calendar',
                'label'  => __('actions.sidemenu.schedules'),
                'match'  => ['panel.schedules.*'],
            ],
            [
                'key'    => 'patients',
                'route'  => 'panel.patients.index',
                'icon'   => 'ti ti-users',
                'label'  => __('actions.sidemenu.patients'),
                'match'  => ['panel.patients.*'],
            ],
            [
                'key'    => 'eye-images',
                'route'  => 'panel.eye-images.index',
                'icon'   => 'ti ti-eye',
                'label'  => __('dashboard.module_eye_images'),
                'match'  => ['panel.eye-images.*'],
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

        if ($isFinancial) {
            $nav[] = [
                'key'      => 'financial',
                'icon'     => 'ti ti-cash-register',
                'label'    => __('actions.sidemenu.financial'),
                'match'    => ['panel.financial.*'],
                'children' => [
                    ['route' => 'panel.financial.bi.index',              'icon' => 'ti ti-layout-dashboard', 'label' => __('actions.sidemenu.management_dashboard'),  'match' => ['panel.financial.bi.*']],
                    ['route' => 'panel.financial.cash-flow.index',       'icon' => 'ti ti-building-bank',    'label' => __('actions.sidemenu.cash_flow'),             'match' => ['panel.financial.cash-flow.*']],
                    ['route' => 'panel.financial.billing.index',         'icon' => 'ti ti-file-invoice',     'label' => __('actions.sidemenu.tiss_billing'),          'match' => ['panel.financial.billing.*']],
                    ['route' => 'panel.financial.tiss.glosas.index',     'icon' => 'ti ti-gavel',            'label' => __('actions.sidemenu.tiss_glosas'),           'match' => ['panel.financial.tiss.glosas.*']],
                    ['route' => 'panel.financial.reports.cash-flow',     'icon' => 'ti ti-chart-arcs',       'label' => __('actions.sidemenu.report_cash_flow'),      'match' => ['panel.financial.reports.cash-flow*']],
                    ['route' => 'panel.financial.reports.covenants',     'icon' => 'ti ti-report-money',     'label' => __('actions.sidemenu.report_billing'),        'match' => ['panel.financial.reports.covenants*']],
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
            $nav[] = ['section' => __('actions.sidemenu.settings')];
            $nav[] = [
                'key'      => 'settings',
                'icon'     => 'ti ti-settings',
                'label'    => __('actions.sidemenu.settings'),
                'match'    => ['panel.setting.*'],
                'children' => [
                    ['route' => 'panel.setting.covenants.index',           'label' => __('actions.sidemenu.covenants'),         'match' => ['panel.setting.covenants.*']],
                    ['route' => 'panel.setting.skintypes.index',           'label' => __('actions.sidemenu.skintypes'),         'match' => ['panel.setting.skintypes.*']],
                    ['route' => 'panel.setting.iristypes.index',           'label' => __('actions.sidemenu.iristypes'),         'match' => ['panel.setting.iristypes.*']],
                    ['route' => 'panel.setting.visittypes.index',          'label' => __('actions.sidemenu.visittypes'),        'match' => ['panel.setting.visittypes.*']],
                    ['route' => 'panel.setting.additiontypes.index',       'label' => __('actions.sidemenu.additiontypes'),     'match' => ['panel.setting.additiontypes.*']],
                    ['route' => 'panel.setting.surgerytypes.index',        'label' => __('actions.sidemenu.surgerytypes'),      'match' => ['panel.setting.surgerytypes.*']],
                    ['route' => 'panel.setting.covertesttypes.index',      'label' => __('actions.sidemenu.colorvisiontypes'),  'match' => ['panel.setting.covertesttypes.*']],
                    ['route' => 'panel.setting.colorvisiontypes.index',    'label' => __('actions.sidemenu.colorvisiontypes'),  'match' => ['panel.setting.colorvisiontypes.*']],
                    ['route' => 'panel.setting.visualacuitytypes.index',   'label' => __('actions.sidemenu.visualacuitytypes'), 'match' => ['panel.setting.visualacuitytypes.*']],
                    ['route' => 'panel.setting.lenses.index',              'label' => __('actions.sidemenu.lenses'),            'match' => ['panel.setting.lenses.*']],
                    ['route' => 'panel.setting.nearpointconvergences.index','label'=> __('actions.sidemenu.nearpointconvergences'),'match'=>['panel.setting.nearpointconvergences.*']],
                    ['route' => 'panel.setting.report-settings.index',     'label' => __('actions.report_settings.title'),      'match' => ['panel.setting.report-settings.*']],
                    ['route' => 'panel.setting.gateways.index',            'label' => __('actions.sidemenu.payment_gateways'),  'match' => ['panel.setting.gateways.*']],
                    ['route' => 'panel.setting.resources.index',           'label' => __('actions.sidemenu.resources'),         'match' => ['panel.setting.resources.*']],
                ],
            ];

            $nav[] = ['section' => __('actions.sidemenu.access_control')];
            $nav[] = [
                'key'   => 'users',
                'route' => 'panel.accesscontrol.users.index',
                'icon'  => 'ti ti-users-group',
                'label' => __('actions.users'),
                'match' => ['panel.accesscontrol.users.*'],
            ];
        }

        return $nav;
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
                'route' => 'panel.manager.entities.index',
                'icon'  => 'ti ti-building',
                'label' => __('actions.sidemenu.entities'),
                'match' => ['panel.manager.entities.*'],
            ],
            [
                'key'   => 'plans',
                'route' => 'panel.manager.plans.index',
                'icon'  => 'ti ti-package',
                'label' => __('actions.sidemenu.plans'),
                'match' => ['panel.manager.plans.*'],
            ],
            [
                'key'   => 'subscriptions',
                'route' => 'panel.manager.subscriptions.index',
                'icon'  => 'ti ti-file-invoice',
                'label' => __('actions.sidemenu.subscriptions'),
                'match' => ['panel.manager.subscriptions.*'],
            ],
            [
                'key'   => 'gateways',
                'route' => 'panel.manager.gateways.index',
                'icon'  => 'ti ti-credit-card',
                'label' => __('actions.sidemenu.gateways'),
                'match' => ['panel.manager.gateways.*'],
            ],
            [
                'key'   => 'users',
                'route' => 'panel.accesscontrol.users.index',
                'icon'  => 'ti ti-users-group',
                'label' => __('actions.sidemenu.users'),
                'match' => ['panel.accesscontrol.users.*'],
            ],
            [
                'key'   => 'report-settings',
                'route' => 'panel.manager.report-settings.index',
                'icon'  => 'ti ti-file-description',
                'label' => __('actions.report_settings.title'),
                'match' => ['panel.manager.report-settings.*'],
            ],
            [
                'key'   => 'partners',
                'route' => 'panel.manager.partners.index',
                'icon'  => 'ti ti-affiliate',
                'label' => __('actions.sidemenu.partners'),
                'match' => ['panel.manager.partners.*'],
            ],
        ];
    }
}
