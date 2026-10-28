<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Enums\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Models\{Partner, PartnerCommission};
use Inertia\{Inertia, Response as InertiaResponse};

class CommissionsController extends Controller
{
    public function index(): InertiaResponse
    {
        $partner = Partner::findOrFail(session('portal_partner_id'));

        $commissions = $partner->commissions()
            ->with('entity')
            ->orderByDesc('created_at')
            ->paginate(20);

        $pendingTotal = (float) $partner->commissions()->where('status', CommissionStatus::Pending)->sum('amount');
        $paidTotal    = (float) $partner->commissions()->where('status', CommissionStatus::Paid)->sum('amount');

        return Inertia::render('Portal/Commissions', [
            'commissions' => $commissions->through(fn (PartnerCommission $c) => [
                'id'           => (string) $c->id,
                'entity_name'  => $c->entity?->name ?? '—',
                'amount'       => (float) $c->amount,
                'amount_fmt'   => 'R$ ' . number_format((float) $c->amount, 2, ',', '.'),
                'rate'         => $c->rate ? number_format((float) $c->rate, 1) : null,
                'period'       => $c->period,
                'status'       => $c->status->value,
                'status_label' => $c->status->label(),
                'status_badge' => $c->status->badgeClass(),
                'due_at'       => $c->due_at?->format('d/m/Y'),
                'paid_at'      => $c->paid_at?->format('d/m/Y'),
                'created_at'   => $c->created_at?->format('d/m/Y'),
            ]),
            'totals' => [
                'pending'     => $pendingTotal,
                'pending_fmt' => 'R$ ' . number_format($pendingTotal, 2, ',', '.'),
                'paid'        => $paidTotal,
                'paid_fmt'    => 'R$ ' . number_format($paidTotal, 2, ',', '.'),
            ],
        ]);
    }
}
