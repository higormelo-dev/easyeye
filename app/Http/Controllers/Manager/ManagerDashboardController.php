<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Services\{ActivationService, ManagerDashboardService};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response};

class ManagerDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        ManagerDashboardService $dashboard,
        ActivationService $activation,
    ): Response {
        $now  = Carbon::now();
        $hour = (int) $now->format('H');

        $greeting = match (true) {
            $hour < 12 => __('manager_dashboard.good_morning'),
            $hour < 18 => __('manager_dashboard.good_afternoon'),
            default    => __('manager_dashboard.good_evening'),
        };

        // Período do funil de conversão vem via query string (?funnel_period=30),
        // disparado pelo seletor no card — reload parcial via Inertia (only:
        // ['conversionFunnel']), então nenhum outro widget do dashboard recarrega.
        $funnelPeriod = $request->integer('funnel_period', 90);

        return Inertia::render('Panel/ManagerDashboard', [
            'greeting'         => $greeting,
            'primaryKpis'      => fn () => $dashboard->getPrimaryKpis(),
            'subscriptionKpis' => fn () => $dashboard->getSubscriptionKpis(),
            'financialKpis'    => fn () => $dashboard->getFinancialKpis(),
            'mrrTrend'         => fn () => $dashboard->getMrrTrend(),
            'conversionFunnel' => fn () => $dashboard->getConversionFunnel($funnelPeriod),
            'growthTrend'      => fn () => $dashboard->getGrowthTrend(),
            'trialsExpiring'   => fn () => $dashboard->getTrialsExpiring($activation),
            'recentEntities'   => fn () => $dashboard->getRecentEntities($activation),
            'topEntities'      => fn () => $dashboard->getTopEntities(),
            'partnersSummary'  => fn () => $dashboard->getPartnersSummary(),
            't'                => trans('manager_dashboard'),
        ]);
    }
}
