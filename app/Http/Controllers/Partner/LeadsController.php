<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\{Partner, PartnerLead};
use App\Services\PartnerService;
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

class LeadsController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
    ) {
    }

    public function index(): InertiaResponse
    {
        $partner = Partner::findOrFail(session('portal_partner_id'));
        $leads   = $partner->leads()->orderByDesc('created_at')->paginate(20);

        return Inertia::render('Portal/Leads', [
            'leads' => $leads->through(fn (PartnerLead $l) => [
                'id'           => (string) $l->id,
                'name'         => $l->name,
                'email'        => $l->email,
                'phone'        => $l->phone,
                'city'         => $l->city,
                'state'        => $l->state,
                'city_state'   => $l->city ? $l->city . '/' . $l->state : null,
                'status'       => $l->status->value,
                'status_label' => $l->status->label(),
                'status_badge' => $l->status->badgeClass(),
                'notes'        => $l->notes,
                'created_at'   => $l->created_at?->format('d/m/Y H:i'),
            ]),
            'urls' => [
                'store' => route('portal.leads.store'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'city'  => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $partner = Partner::findOrFail(session('portal_partner_id'));

        $this->partnerService->registerLead(
            partner: $partner,
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return back()->with('success', __('actions.partners.lead_registered'));
    }
}
