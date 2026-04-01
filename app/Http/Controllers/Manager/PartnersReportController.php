<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Manager;

use App\Enums\{CommissionStatus, PartnerLeadStatus};
use App\Http\Controllers\Controller;
use App\Models\{Partner, PartnerCommission, PartnerLead};
use App\Services\PartnerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PartnersReportController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
    ) {}

    public function index(): View
    {
        $meta = [
            'title'       => 'Parceiros',
            'action'      => 'Relatório de Parceiros',
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Parceiros', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        $partners = Partner::withCount(['leads', 'commissions'])
            ->withSum(
                ['commissions as pending_amount' => fn ($q) => $q->where('status', CommissionStatus::Pending)],
                'amount'
            )
            ->withSum(
                ['commissions as paid_amount' => fn ($q) => $q->where('status', CommissionStatus::Paid)],
                'amount'
            )
            ->orderByDesc('created_at')
            ->get();

        $funnel = collect(PartnerLeadStatus::cases())->mapWithKeys(
            fn (PartnerLeadStatus $status) => [
                $status->value => [
                    'label' => $status->label(),
                    'badge' => $status->badgeClass(),
                    'count' => PartnerLead::where('status', $status)->count(),
                ],
            ]
        );

        $pendingTotal = (float) PartnerCommission::where('status', CommissionStatus::Pending)->sum('amount');
        $paidTotal    = (float) PartnerCommission::where('status', CommissionStatus::Paid)->sum('amount');

        $recentCommissions = PartnerCommission::with(['partner', 'entity'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('system.manager.partners.index', compact(
            'meta', 'partners', 'funnel', 'pendingTotal', 'paidTotal', 'recentCommissions'
        ));
    }

    public function payCommission(PartnerCommission $commission): RedirectResponse
    {
        $this->partnerService->payCommission($commission);

        return back()->with('success', 'Comissão marcada como paga.');
    }
}
