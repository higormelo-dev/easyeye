<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Services\{ActivationService, ManagerDashboardService};
use Carbon\Carbon;
use Inertia\{Inertia, Response};

class ManagerDashboardController extends Controller
{
    public function __invoke(
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

        return Inertia::render('Panel/ManagerDashboard', [
            'greeting'         => $greeting,
            'primaryKpis'      => fn () => $dashboard->getPrimaryKpis(),
            'subscriptionKpis' => fn () => $dashboard->getSubscriptionKpis(),
            'financialKpis'    => fn () => $dashboard->getFinancialKpis(),
            'mrrTrend'         => fn () => $dashboard->getMrrTrend(),
            'conversionFunnel' => fn () => $dashboard->getConversionFunnel(),
            'growthTrend'      => fn () => $dashboard->getGrowthTrend(),
            'trialsExpiring'   => fn () => $dashboard->getTrialsExpiring($activation),
            'recentEntities'   => fn () => $dashboard->getRecentEntities($activation),
            'topEntities'      => fn () => $dashboard->getTopEntities(),
            'partnersSummary'  => fn () => $dashboard->getPartnersSummary(),
            't'                => trans('manager_dashboard'),
        ]);
    }
}
