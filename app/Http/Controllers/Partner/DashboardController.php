<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\{Partner, PartnerCommission, PartnerLead};
use App\Services\PartnerService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
    ) {
    }

    public function index(): View
    {
        $partner = Partner::findOrFail(session('portal_partner_id'));
        $metrics = $this->partnerService->metrics($partner);

        $recentLeads = PartnerLead::where('partner_id', $partner->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentCommissions = PartnerCommission::with('entity')
            ->where('partner_id', $partner->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return view('portal.dashboard', compact('partner', 'metrics', 'recentLeads', 'recentCommissions'));
    }
}
