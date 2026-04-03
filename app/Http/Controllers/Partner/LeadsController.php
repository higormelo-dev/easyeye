<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\PartnerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\{RedirectResponse, Request};

class LeadsController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
    ) {
    }

    public function index(): View
    {
        $partner = Partner::findOrFail(session('portal_partner_id'));
        $leads   = $partner->leads()->orderByDesc('created_at')->paginate(20);

        return view('portal.leads.index', compact('partner', 'leads'));
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
