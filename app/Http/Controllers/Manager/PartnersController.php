<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\{CommissionStatus, PartnerLeadStatus, PartnerType};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PartnerRequest;
use App\Models\{Partner, PartnerCommission, PartnerLead, User};
use App\Services\PartnerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Hash, Password};
use Illuminate\Support\Str;

class PartnersController extends Controller
{
    protected string $titleController = 'Parceiros';

    public function __construct(
        private readonly PartnerService $partnerService,
    ) {
    }

    public function index(): View
    {
        $meta = [
            'title'            => $this->titleController,
            'action'           => 'Gestão de Parceiros',
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => 'Dashboard', 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => $this->titleController, 'url' => route('panel.manager.partners.index'), 'active' => true],
            ],
        ];

        $partners = Partner::withCount(['leads', 'commissions'])
            ->withSum(
                ['commissions as pending_amount' => fn ($q) => $q->where('status', CommissionStatus::Pending)],
                'amount',
            )
            ->withSum(
                ['commissions as paid_amount' => fn ($q) => $q->where('status', CommissionStatus::Paid)],
                'amount',
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
            ],
        );

        $pendingTotal = (float) PartnerCommission::where('status', CommissionStatus::Pending)->sum('amount');
        $paidTotal    = (float) PartnerCommission::where('status', CommissionStatus::Paid)->sum('amount');

        $recentCommissions = PartnerCommission::with(['partner', 'entity'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $partnerTypes = PartnerType::cases();
        $storeUrl     = route('panel.manager.partners.store');
        $baseUrl      = url('panel/manager/partners');

        return view('system.manager.partners.index', compact(
            'meta',
            'partners',
            'funnel',
            'pendingTotal',
            'paidTotal',
            'recentCommissions',
            'partnerTypes',
            'storeUrl',
            'baseUrl',
        ));
    }

    public function store(PartnerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $partner = $this->partnerService->create(
            name: $data['name'],
            email: $data['email'],
            type: PartnerType::from($data['type']),
            document: $data['document'] ?? null,
            commissionRate: isset($data['commission_rate']) ? (float) $data['commission_rate'] : null,
            notes: $data['notes'] ?? null,
            createdBy: Auth::id(),
        );

        // Create linked User account
        $user = User::create([
            'name'     => $partner->name,
            'email'    => $partner->email,
            'password' => Hash::make(Str::random(32)),
        ]);

        $partner->update(['user_id' => $user->id]);

        // Send password-reset email so partner sets their own password
        Password::sendResetLink(['email' => $partner->email]);

        return response()->json([
            'message' => __('actions.partners.account_created'),
            'data'    => ['id' => $partner->id, 'name' => $partner->name],
        ]);
    }

    public function show(Partner $partner): View
    {
        $leads        = $partner->leads()->with('entity')->orderByDesc('created_at')->paginate(15);
        $commissions  = $partner->commissions()->with('entity')->orderByDesc('created_at')->paginate(15);
        $leadStatuses = PartnerLeadStatus::cases();

        $meta = [
            'title'       => $partner->name,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Parceiros', 'url' => route('panel.manager.partners.index'), 'active' => false],
                ['label' => $partner->name, 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.manager.partners.show', compact(
            'meta',
            'partner',
            'leads',
            'commissions',
            'leadStatuses',
        ));
    }

    public function editData(Partner $partner): JsonResponse
    {
        return response()->json(['data' => [
            'name'            => $partner->name,
            'email'           => $partner->email,
            'type'            => $partner->type->value,
            'document'        => $partner->document,
            'commission_rate' => $partner->commission_rate,
            'notes'           => $partner->notes,
            'status'          => $partner->status,
        ]]);
    }

    public function update(PartnerRequest $request, Partner $partner): JsonResponse
    {
        $data = $request->validated();

        $partner->update([
            'name'            => $data['name'],
            'type'            => PartnerType::from($data['type']),
            'document'        => $data['document'] ?? $partner->document,
            'commission_rate' => isset($data['commission_rate']) ? (float) $data['commission_rate'] : $partner->commission_rate,
            'notes'           => $data['notes'] ?? $partner->notes,
            'status'          => $data['status'] ?? $partner->status,
        ]);

        return response()->json([
            'message' => 'Parceiro atualizado com sucesso.',
            'data'    => ['id' => $partner->id, 'name' => $partner->name],
        ]);
    }

    public function destroy(Partner $partner): JsonResponse
    {
        $partner->delete();

        return response()->json(['message' => 'Parceiro removido com sucesso.']);
    }

    public function advanceLead(Request $request, Partner $partner, PartnerLead $lead): JsonResponse
    {
        $request->validate(['status' => ['required', 'string']]);

        $this->partnerService->advanceLead(
            lead: $lead,
            status: PartnerLeadStatus::from($request->string('status')->value()),
            notes: $request->string('notes')->value() ?: null,
        );

        return response()->json(['message' => 'Status do lead atualizado.']);
    }

    public function payCommission(PartnerCommission $commission): RedirectResponse
    {
        $this->partnerService->payCommission($commission);

        return back()->with('success', 'Comissão marcada como paga.');
    }
}
