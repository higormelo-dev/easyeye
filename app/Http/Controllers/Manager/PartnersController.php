<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\{CommissionStatus, PartnerLeadStatus, PartnerType};
use App\Exports\PartnersReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PartnerRequest;
use App\Models\{Partner, PartnerCommission, PartnerLead, User};
use App\Services\Audit\AuditLogger;
use App\Services\PartnerService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{Auth, Hash, Password};
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PartnersController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): InertiaResponse
    {
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

        $funnel = collect(PartnerLeadStatus::cases())->map(fn (PartnerLeadStatus $s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'badge' => $s->badgeClass(),
            'count' => PartnerLead::where('status', $s)->count(),
        ]);

        $pendingTotal = (float) PartnerCommission::where('status', CommissionStatus::Pending)->sum('amount');
        $paidTotal    = (float) PartnerCommission::where('status', CommissionStatus::Paid)->sum('amount');

        $recentCommissions = PartnerCommission::with(['partner', 'entity'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return Inertia::render('Panel/Manager/Partners/Index', [
            'partners' => $partners->map(fn ($p) => $this->toRow($p)),
            'funnel'   => $funnel,
            'kpis'     => [
                'partners' => $partners->count(),
                'leads'    => $funnel->sum('count'),
                'pending'  => $pendingTotal,
                'paid'     => $paidTotal,
            ],
            'recentCommissions' => $recentCommissions->map(fn ($c) => [
                'id'           => $c->id,
                'partner_name' => $c->partner?->name ?? '—',
                'entity_name'  => $c->entity?->name ?? '—',
                'amount_fmt'   => 'R$ ' . number_format((float) $c->amount, 2, ',', '.'),
                'status'       => $c->status->value,
                'status_label' => $c->status->label(),
                'status_badge' => $c->status->badgeClass(),
                'due_at'       => $c->due_at?->format('d/m/Y'),
                'is_pending'   => $c->status === CommissionStatus::Pending,
                'pay_url'      => route('manager.partners.commission.pay', $c),
            ]),
            'partnerTypes' => collect(PartnerType::cases())->map(fn ($t) => [
                'value'        => $t->value,
                'label'        => $t->label(),
                'default_rate' => $t->defaultCommissionRate(),
            ]),
            't' => trans('manager_partners'),
        ]);
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

        $user = User::create([
            'name'     => $partner->name,
            'email'    => $partner->email,
            'password' => Hash::make(Str::random(32)),
        ]);

        $partner->update(['user_id' => $user->id]);

        Password::sendResetLink(['email' => $partner->email]);

        return response()->json([
            'message' => trans('manager_partners.account_created'),
            'data'    => ['id' => $partner->id, 'name' => $partner->name],
        ]);
    }

    public function show(Request $request, Partner $partner): InertiaResponse
    {
        $leadsPage       = max(1, (int) $request->input('leads_page', 1));
        $commissionsPage = max(1, (int) $request->input('commissions_page', 1));

        $leads = $partner->leads()
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'leads_page', $leadsPage)
            ->through(fn ($l) => [
                'id'           => $l->id,
                'name'         => $l->name,
                'email'        => $l->email,
                'city_state'   => $l->city ? $l->city . '/' . $l->state : null,
                'status'       => $l->status->value,
                'status_label' => $l->status->label(),
                'status_badge' => $l->status->badgeClass(),
                'is_active'    => $l->status->isActive(),
                'advance_url'  => route('manager.partners.leads.advance', [$partner, $l]),
                'created_at'   => $l->created_at->format('d/m/Y'),
            ]);

        $commissions = $partner->commissions()
            ->with('entity')
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'commissions_page', $commissionsPage)
            ->through(fn ($c) => [
                'id'           => $c->id,
                'entity_name'  => $c->entity?->name ?? '—',
                'amount_fmt'   => 'R$ ' . number_format((float) $c->amount, 2, ',', '.'),
                'rate'         => number_format((float) $c->rate, 1),
                'period'       => $c->period,
                'status'       => $c->status->value,
                'status_label' => $c->status->label(),
                'status_badge' => $c->status->badgeClass(),
                'due_at'       => $c->due_at?->format('d/m/Y'),
                'is_pending'   => $c->status === CommissionStatus::Pending,
                'pay_url'      => route('manager.partners.commission.pay', $c),
            ]);

        $statusBadge = match ($partner->status) {
            'active'   => 'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
            'inactive' => 'badge-soft-secondary rounded fs-13 fw-medium',
            default    => 'badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium',
        };

        return Inertia::render('Panel/Manager/Partners/Show', [
            'partner' => [
                'id'                => $partner->id,
                'name'              => $partner->name,
                'email'             => $partner->email,
                'type'              => $partner->type->value,
                'type_label'        => $partner->type->label(),
                'status'            => $partner->status,
                'status_label'      => trans('manager_partners.status_' . $partner->status),
                'status_badge'      => $statusBadge,
                'commission_rate'   => number_format((float) $partner->commission_rate, 1),
                'document'          => $partner->document,
                'token'             => $partner->token,
                'notes'             => $partner->notes,
                'created_at'        => $partner->created_at->format('d/m/Y'),
                'leads_total'       => $partner->leads()->count(),
                'commissions_total' => $partner->commissions()->count(),
                'edit_url'          => route('manager.partners.edit-data', $partner),
                'update_url'        => route('manager.partners.update', $partner),
            ],
            'leads'        => $leads,
            'commissions'  => $commissions,
            'leadStatuses' => collect(PartnerLeadStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'partnerTypes' => collect(PartnerType::cases())->map(fn ($t) => [
                'value'        => $t->value,
                'label'        => $t->label(),
                'default_rate' => $t->defaultCommissionRate(),
            ]),
            't' => trans('manager_partners'),
        ]);
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
            'message' => trans('manager_partners.partner_updated'),
            'data'    => ['id' => $partner->id, 'name' => $partner->name],
        ]);
    }

    public function destroy(Request $request, Partner $partner): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $partner->delete();

        $this->audit->recordAdminAction(
            event: 'manager.partner.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'partner',
            auditableId: (string) $partner->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['code' => $partner->code, 'name' => $partner->name],
            request: $request,
        );

        return response()->json(['message' => trans('manager_partners.partner_deleted')]);
    }

    public function advanceLead(Request $request, Partner $partner, PartnerLead $lead): JsonResponse
    {
        $request->validate(['status' => ['required', 'string']]);

        $this->partnerService->advanceLead(
            lead: $lead,
            status: PartnerLeadStatus::from($request->string('status')->value()),
            notes: $request->string('notes')->value() ?: null,
        );

        return response()->json(['message' => trans('manager_partners.lead_advanced')]);
    }

    public function payCommission(Request $request, PartnerCommission $commission): mixed
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $this->partnerService->payCommission($commission);

        // Audit: pagamento de comissão é evento financeiro auditável (contabilidade).
        $this->audit->recordAdminAction(
            event: 'manager.partner.commission.pay',
            targetEntityId: $commission->entity_id ? (string) $commission->entity_id : null,
            targetUserId: null,
            auditableType: 'partner_commission',
            auditableId: (string) $commission->id,
            reason: trim((string) $request->input('reason')),
            newValues: [
                'partner_id' => (string) $commission->partner_id,
                'amount'     => (float) $commission->amount,
                'new_status' => CommissionStatus::Paid->value,
            ],
            request: $request,
            oldValues: ['status' => $commission->status->value],
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => trans('manager_partners.commission_paid')]);
        }

        return back()->with('success', trans('manager_partners.commission_paid'));
    }

    /**
     * Exporta o relatório consolidado de parceiros em PDF (wkhtmltopdf via Snappy).
     *
     * Filtros via query string:
     *   from    : YYYY-MM-DD
     *   to      : YYYY-MM-DD
     *   status  : pending|paid|cancelled  (filtra apenas a seção de comissões)
     *
     * Mesmas regras de autorização do middleware do grupo (saas.admin).
     */
    public function exportPdf(Request $request): Response
    {
        [$from, $to, $statusFilter] = $this->parseExportFilters($request);

        $payload = $this->buildReportPayload($from, $to, $statusFilter);

        $pdf = SnappyPdf::loadView('pdf.partners_report', $payload)
            ->setPaper('a4')
            ->setOption('margin-top', 12)
            ->setOption('margin-bottom', 14)
            ->setOption('margin-left', 12)
            ->setOption('margin-right', 12)
            ->setOption('encoding', 'UTF-8');

        $filename = 'relatorio_parceiros_' . now()->format('Ymd_His') . '.pdf';

        return $pdf->inline($filename);
    }

    /**
     * Exporta o relatório consolidado em Excel multi-aba (Maatwebsite/Excel).
     *
     * Filtros idênticos ao PDF para consistência. Quatro abas:
     * Resumo, Parceiros, Leads, Comissões.
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        [$from, $to, $statusFilter] = $this->parseExportFilters($request);

        $filename = 'relatorio_parceiros_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new PartnersReportExport($from, $to, $statusFilter),
            $filename,
        );
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: ?string}
     */
    private function parseExportFilters(Request $request): array
    {
        $fromRaw = $request->string('from')->trim()->value();
        $toRaw   = $request->string('to')->trim()->value();
        $status  = $request->string('status')->trim()->value();

        $from = null;
        $to   = null;

        // Aceita silenciosamente datas inválidas — exportar tudo é mais útil que
        // explodir com 422 numa ação de relatório.
        try {
            if ($fromRaw !== '') {
                $from = CarbonImmutable::parse($fromRaw)->startOfDay();
            }
        } catch (Throwable) {
        }

        try {
            if ($toRaw !== '') {
                $to = CarbonImmutable::parse($toRaw)->endOfDay();
            }
        } catch (Throwable) {
        }

        $allowedStatuses = ['pending', 'paid', 'cancelled'];
        $statusFilter    = in_array($status, $allowedStatuses, true) ? $status : null;

        return [$from, $to, $statusFilter];
    }

    /**
     * Monta o payload do relatório respeitando os filtros recebidos.
     * Usado pelo PDF (e poderia ser usado por uma "preview" JSON no futuro).
     *
     * @return array<string, mixed>
     */
    private function buildReportPayload(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $statusFilter): array
    {
        $partners = Partner::query()
            ->withCount(['leads', 'commissions'])
            ->withSum(
                ['commissions as pending_amount' => fn ($q) => $q->where('status', CommissionStatus::Pending)],
                'amount',
            )
            ->withSum(
                ['commissions as paid_amount' => fn ($q) => $q->where('status', CommissionStatus::Paid)],
                'amount',
            )
            ->orderBy('name')
            ->get();

        $leadsQuery = PartnerLead::query();

        if ($from) {
            $leadsQuery->where('created_at', '>=', $from);
        }

        if ($to) {
            $leadsQuery->where('created_at', '<=', $to);
        }

        $funnel = collect(PartnerLeadStatus::cases())->map(function (PartnerLeadStatus $s) use ($from, $to) {
            $q = PartnerLead::query()->where('status', $s);

            if ($from) {
                $q->where('created_at', '>=', $from);
            }

            if ($to) {
                $q->where('created_at', '<=', $to);
            }

            return ['label' => $s->label(), 'count' => $q->count()];
        });

        $commissionsQuery = PartnerCommission::query();

        if ($from) {
            $commissionsQuery->where('created_at', '>=', $from);
        }

        if ($to) {
            $commissionsQuery->where('created_at', '<=', $to);
        }

        $pending = (float) (clone $commissionsQuery)
            ->where('status', CommissionStatus::Pending)
            ->sum('amount');
        $paid = (float) (clone $commissionsQuery)
            ->where('status', CommissionStatus::Paid)
            ->sum('amount');

        $recentCommissionsQuery = PartnerCommission::query()
            ->with(['partner:id,name', 'entity:id,name'])
            ->orderByDesc('created_at');

        if ($from) {
            $recentCommissionsQuery->where('created_at', '>=', $from);
        }

        if ($to) {
            $recentCommissionsQuery->where('created_at', '<=', $to);
        }

        if ($statusFilter) {
            $statusEnum = CommissionStatus::tryFrom($statusFilter);

            if ($statusEnum) {
                $recentCommissionsQuery->where('status', $statusEnum);
            }
        }

        return [
            'period'       => $this->formatPeriodLabel($from, $to),
            'statusFilter' => $statusFilter,
            'kpis'         => [
                'partners' => $partners->count(),
                'leads'    => $funnel->sum('count'),
                'pending'  => $pending,
                'paid'     => $paid,
            ],
            'partners'          => $partners,
            'funnel'            => $funnel,
            'recentCommissions' => $recentCommissionsQuery->limit(100)->get(),
            'generatedAt'       => now(),
        ];
    }

    private function formatPeriodLabel(?CarbonImmutable $from, ?CarbonImmutable $to): string
    {
        if ($from && $to) {
            return $from->format('d/m/Y') . ' a ' . $to->format('d/m/Y');
        }

        if ($from) {
            return 'A partir de ' . $from->format('d/m/Y');
        }

        if ($to) {
            return 'Até ' . $to->format('d/m/Y');
        }

        return 'Todo o histórico';
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function toRow(Partner $p): array
    {
        return [
            'id'                => $p->id,
            'name'              => $p->name,
            'email'             => $p->email,
            'type_label'        => $p->type->label(),
            'leads_count'       => $p->leads_count ?? 0,
            'commissions_count' => $p->commissions_count ?? 0,
            'pending_fmt'       => 'R$ ' . number_format((float) ($p->pending_amount ?? 0), 2, ',', '.'),
            'paid_fmt'          => 'R$ ' . number_format((float) ($p->paid_amount ?? 0), 2, ',', '.'),
            'show_url'          => route('manager.partners.show', $p),
            'edit_data_url'     => route('manager.partners.edit-data', $p),
            'update_url'        => route('manager.partners.update', $p),
            'destroy_url'       => route('manager.partners.destroy', $p),
        ];
    }
}
