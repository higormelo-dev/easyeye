<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\{Partner, PartnerCommission, PartnerLead};
use App\Services\PartnerService;
use Inertia\{Inertia, Response as InertiaResponse};

class DashboardController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
    ) {
    }

    public function index(): InertiaResponse
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

        return Inertia::render('Portal/Dashboard', [
            'metrics'     => $metrics,
            'recentLeads' => $recentLeads->map(fn (PartnerLead $l) => [
                'id'           => (string) $l->id,
                'name'         => $l->name,
                'email'        => $l->email,
                'phone'        => $l->phone,
                'city_state'   => $l->city ? $l->city . '/' . $l->state : null,
                'status'       => $l->status->value,
                'status_label' => $l->status->label(),
                'status_badge' => $l->status->badgeClass(),
                'created_at'   => $l->created_at?->format('d/m/Y'),
            ]),
            'recentCommissions' => $recentCommissions->map(fn (PartnerCommission $c) => [
                'id'           => (string) $c->id,
                'entity_name'  => $c->entity?->name ?? '—',
                'amount'       => (float) $c->amount,
                'amount_fmt'   => 'R$ ' . number_format((float) $c->amount, 2, ',', '.'),
                'status'       => $c->status->value,
                'status_label' => $c->status->label(),
                'status_badge' => $c->status->badgeClass(),
                'created_at'   => $c->created_at?->format('d/m/Y'),
            ]),
        ]);
    }
}
