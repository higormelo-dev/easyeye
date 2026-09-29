<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\{CashEntryReferenceType, EntityGate, FinancialEntryStatus, FinancialEntryType, PaymentMethod};
use App\Exceptions\Financial\CashPeriodClosedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\CashEntryRequest;
use App\Models\{CashClose, Covenant, Entity, FinancialCashEntry, FinancialCategory, Schedule};
use App\Services\Financial\CashFlowService;
use App\Support\ReportPeriod;
use BackedEnum;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

class CashFlowController extends Controller
{
    /** Motivos de trava por linha enviados à UI (lock_reason). */
    public const LOCK_BILLING_CLAIM = 'billing_claim';

    public const LOCK_CLOSED_PERIOD = 'closed_period';

    public const LOCK_DOCTOR_PAYOUT = 'doctor_payout';

    /** Origem do lançamento por linha (origin), a partir do vínculo de sistema. */
    public const ORIGIN_SCHEDULE = 'schedule';

    public const ORIGIN_CLAIM = 'claim';

    public const ORIGIN_PURCHASE = 'purchase';

    public const ORIGIN_DOCTOR_PAYOUT = 'doctor_payout';

    public const ORIGIN_MANUAL = 'manual';

    /** Ordenação aceita da query string (whitelist) → coluna. */
    public const SORTABLE = [
        'entry_date'  => 'entry_date',
        'code'        => 'code',
        'description' => 'description',
        'type'        => 'type',
        'status'      => 'status',
        'amount'      => 'amount',
    ];

    public const PER_PAGE = 30;

    /** Busca maior que isso é cortada (descrição tem 255; código, 32). */
    private const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        private readonly CashFlowService $cashFlowService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        // Query string nunca chega crua ao PostgreSQL: antes ?from=abc ou
        // ?category_id=x viravam erro 500 (22P02/22007).
        [$from, $to] = ReportPeriod::resolve($request->query('from'), $request->query('to'));
        $filters     = $this->listFilters($request);
        $sort        = array_key_exists(ReportPeriod::text($request->query('sort')), self::SORTABLE)
            ? ReportPeriod::text($request->query('sort'))
            : 'entry_date';
        $direction = ReportPeriod::text($request->query('direction')) === 'asc' ? 'asc' : 'desc';

        $closedPeriods = $this->closedPeriodsWithin($entityId, $from, $to);

        $entries = $this->cashFlowService->entriesQuery($entityId, $from, $to, $filters)
            ->with([
                'category:id,name',
                'covenant:id,name',
                // LGPD: do paciente, só o nome (People.full_name) — e só da própria clínica.
                'patient' => fn ($q) => $q->select(['id', 'person_id', 'entity_id'])->where('entity_id', $entityId),
                'patient.person:id,full_name',
            ])
            ->orderBy(self::SORTABLE[$sort], $direction)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $scheduleDates = $this->scheduleDates($entityId, $entries->getCollection());

        $entries->through(fn (FinancialCashEntry $e) => $this->row($e, $closedPeriods, $scheduleDates));

        return Inertia::render('Panel/Financial/CashFlow/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_cash_flow.breadcrumb_financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial_cash_flow.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'entries'    => $entries,
            'categories' => fn () => FinancialCategory::query()
                ->availableForEntity($entityId)
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            // Convênios aceitos pelo CashEntryRequest (da clínica ou globais, não excluídos).
            'covenants' => fn () => Covenant::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'payment_methods' => fn () => collect(PaymentMethod::cases())
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->values()
                ->all(),
            // KPIs e rodapé com os MESMOS filtros da tabela (cancelados fora).
            // summary() segue intocado para snapshot do fechamento, relatórios e BI.
            'overview'       => $this->cashFlowService->overview($entityId, $from, $to, $filters),
            'closed_periods' => $closedPeriods,
            'filters'        => [
                'from'        => $from,
                'to'          => $to,
                'type'        => $filters['type'],
                'status'      => $filters['status'],
                'category_id' => $filters['category_id'],
                'search'      => $filters['search'],
                'sort'        => $sort,
                'direction'   => $direction,
            ],
            // Link "editar pela agenda" só para quem acessa a agenda (mesmo gate das rotas).
            'can_edit_schedule' => fn () => Gate::allows(EntityGate::EditSchedule->value, $entity),
            // "Hoje" no fuso da clínica (APP_TIMEZONE) — o modal não usa mais
            // toISOString(), que depois das 21h (UTC-3) já é o dia seguinte.
            'today' => now()->toDateString(),
            't'     => fn () => trans('financial_cash_flow') + ['shared' => trans('financial_shared')],
        ]);
    }

    /**
     * Filtros da listagem normalizados (enum/UUID inválidos → null; busca
     * aparada e limitada). Os mesmos valores vão para a consulta, o overview()
     * e de volta à tela.
     *
     * @return array{type: ?string, status: ?string, category_id: ?string, search: string}
     */
    private function listFilters(Request $request): array
    {
        return [
            'type'        => FinancialEntryType::tryFrom(ReportPeriod::text($request->query('type')))?->value,
            'status'      => FinancialEntryStatus::tryFrom(ReportPeriod::text($request->query('status')))?->value,
            'category_id' => ReportPeriod::uuidOrNull($request->query('category_id')),
            'search'      => mb_substr(ReportPeriod::text($request->query('search')), 0, self::SEARCH_MAX_LENGTH),
        ];
    }

    /**
     * @param list<array{period_start: string, period_end: string}> $closedPeriods
     * @param array<string, string>                                 $scheduleDates
     *
     * @return array<string, mixed>
     */
    private function row(FinancialCashEntry $e, array $closedPeriods, array $scheduleDates): array
    {
        $origin = $this->origin($e);

        return [
            'id'                   => $e->id,
            'code'                 => $e->code,
            'entry_date'           => $e->entry_date?->format('Y-m-d'),
            'description'          => $e->description,
            'type'                 => $e->type instanceof BackedEnum ? $e->type->value : $e->type,
            'status'               => $e->status instanceof BackedEnum ? $e->status->value : $e->status,
            'amount'               => (float) $e->amount,
            'category_name'        => $e->category?->name,
            'category_id'          => $e->category_id,
            'covenant_name'        => $e->covenant?->name,
            'covenant_id'          => $e->covenant_id,
            'patient_name'         => $e->patient?->person?->full_name,
            'payment_method'       => $e->payment_method?->value,
            'payment_method_label' => $e->payment_method?->label(),
            'origin'               => $origin,
            'schedule_date'        => $origin === self::ORIGIN_SCHEDULE ? ($scheduleDates[(string) $e->reference_id] ?? null) : null,
            // Recebimento da agenda com dinheiro + cartão: valor e forma só pela agenda.
            'has_split'   => CashEntryRequest::isScheduleSplit($e),
            'has_claim'   => $e->billing_claim_id !== null,
            'lock_reason' => $this->lockReason($e, $closedPeriods),
            'notes'       => $e->notes,
        ];
    }

    /** Agenda / guia / compra / manual, pelo vínculo de sistema (CashEntryReferenceType). */
    private function origin(FinancialCashEntry $entry): string
    {
        if ($entry->billing_claim_id !== null) {
            return self::ORIGIN_CLAIM;
        }

        return match (CashEntryReferenceType::tryFrom((string) $entry->reference_type)) {
            CashEntryReferenceType::Schedule      => self::ORIGIN_SCHEDULE,
            CashEntryReferenceType::BillingClaim  => self::ORIGIN_CLAIM,
            CashEntryReferenceType::PurchaseOrder => self::ORIGIN_PURCHASE,
            CashEntryReferenceType::DoctorPayout  => self::ORIGIN_DOCTOR_PAYOUT,
            null                                  => self::ORIGIN_MANUAL,
        };
    }

    /**
     * Dia do agendamento de cada recebimento da agenda na página (uma
     * consulta, só agendamentos da MESMA clínica — referência forjada de outra
     * clínica fica sem data e sem link).
     *
     * @param Collection<int, FinancialCashEntry> $entries
     *
     * @return array<string, string> schedule_id => Y-m-d
     */
    private function scheduleDates(string $entityId, Collection $entries): array
    {
        $ids = $entries
            ->filter(fn (FinancialCashEntry $e) => $e->reference_type === CashEntryReferenceType::Schedule->value
                && Str::isUuid((string) $e->reference_id))
            ->map(fn (FinancialCashEntry $e) => (string) $e->reference_id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Schedule::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', $ids->all())
            ->get(['id', 'date_time'])
            ->mapWithKeys(fn (Schedule $s) => [(string) $s->id => $s->date_time?->toDateString()])
            ->filter()
            ->all();
    }

    public function store(CashEntryRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorizeFinancial();

        try {
            $entry = $this->cashFlowService->create($request->validated());
        } catch (CashPeriodClosedException $e) {
            return $this->periodClosedResponse($request, $e);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('financial_cash_flow.created'),
                'data'    => $entry->fresh(['category', 'covenant']),
            ]);
        }

        return back()->with('message', __('financial_cash_flow.created'));
    }

    /**
     * Lançamento vinculado a guia (billing_claim_id) é recusado com 422 pelo
     * CashFlowService (ValidationException), antes de qualquer alteração.
     */
    public function update(CashEntryRequest $request, FinancialCashEntry $entry): RedirectResponse|JsonResponse
    {
        $this->authorizeFinancial();

        try {
            $entry = $this->cashFlowService->update($entry, $request->validated());
        } catch (CashPeriodClosedException $e) {
            return $this->periodClosedResponse($request, $e);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('financial_cash_flow.updated'),
                'data'    => $entry->fresh(['category', 'covenant']),
            ]);
        }

        return back()->with('message', __('financial_cash_flow.updated'));
    }

    private function periodClosedResponse(Request $request, CashPeriodClosedException $e): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            // `errors` junto do `message`: o modal mostra o motivo no campo Data
            // (antes o 422 vinha só com message e a tela não exibia nada).
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => ['entry_date' => [$e->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['entry_date' => $e->getMessage()]);
    }

    public function destroy(Request $request, FinancialCashEntry $entry): RedirectResponse|JsonResponse
    {
        $this->authorizeFinancial();

        try {
            $this->cashFlowService->delete($entry);
        } catch (CashPeriodClosedException $e) {
            return $this->periodClosedResponse($request, $e);
        }

        // A UI exclui via fetch(DELETE) com Accept: application/json — um
        // redirect back() faria o fetch REPETIR o DELETE na URL de destino
        // (302 preserva método para não-POST) e terminar em 405.
        if ($request->expectsJson()) {
            return response()->json(['message' => __('financial_cash_flow.destroyed')]);
        }

        return back()->with('message', __('financial_cash_flow.destroyed'));
    }

    /**
     * Fechamentos ativos que cruzam [from, to], carregados UMA vez por página
     * (sem N+1) para calcular o lock_reason de cada linha em PHP.
     *
     * @return list<array{period_start: string, period_end: string}>
     */
    private function closedPeriodsWithin(string $entityId, string $from, string $to): array
    {
        return CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->orderBy('period_start')
            ->get(['period_start', 'period_end'])
            ->map(fn (CashClose $c) => [
                'period_start' => (string) $c->period_start?->toDateString(),
                'period_end'   => (string) $c->period_end?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Por que a linha não pode ser editada/excluída (null = livre). Espelha as
     * guardas do CashFlowService: guia vinculada e período de caixa fechado.
     *
     * @param list<array{period_start: string, period_end: string}> $closedPeriods
     */
    private function lockReason(FinancialCashEntry $entry, array $closedPeriods): ?string
    {
        if ($entry->billing_claim_id !== null) {
            return self::LOCK_BILLING_CLAIM;
        }

        if ($entry->reference_type === CashEntryReferenceType::DoctorPayout->value) {
            return self::LOCK_DOCTOR_PAYOUT;
        }

        $date = $entry->entry_date?->toDateString();

        if ($date === null) {
            return null;
        }

        foreach ($closedPeriods as $period) {
            if ($period['period_start'] <= $date && $date <= $period['period_end']) {
                return self::LOCK_CLOSED_PERIOD;
            }
        }

        return null;
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
