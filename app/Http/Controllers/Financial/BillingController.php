<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Models\{TissGlosaReason, TissTussCode};
use App\Domains\Tiss\Services\TissWorkflowService;
use App\Enums\{BillingBatchStatus, BillingClaimStatus, EntityGate, PaymentMethod};
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\PresentsTissValidation;
use App\Http\Requests\Financial\{BillingBatchRequest, BillingIndividualRequest, MarkClaimDeniedRequest, MarkClaimPaidRequest};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Schedule};
use App\Services\Financial\{BillingBatchAttachService, BillingBulkReceiptService, BillingService, ProcedurePriceService};
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{DB, Gate, Log, Storage};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response as InertiaResponse};
use RuntimeException;
use Throwable;

class BillingController extends Controller
{
    use PresentsTissValidation;

    /** Abas da tela — a ativa vai na URL (?tab=) e volta normalizada em `filters`. */
    private const TABS = ['eligible', 'claims', 'batches'];

    /**
     * Linhas por página de cada aba. Cada aba pagina sozinha (?eligible_page=,
     * ?claims_page=, ?batches_page=) e tem busca/ordem próprias
     * (?{aba}_search=, ?{aba}_sort=, ?{aba}_direction=).
     */
    private const PER_PAGE = ['eligible' => 50, 'claims' => 50, 'batches' => 25];

    /** Busca: termo literal (%, _ e \ escapados), cortado neste tamanho. */
    private const SEARCH_MAX_LENGTH = 100;

    /**
     * Ordenação aceita por aba (whitelist): chave da URL → coluna. `patient` e
     * `covenant` ordenam por subconsulta (sem join; o eager load fica igual).
     */
    private const SORTS = [
        'eligible' => ['date' => 'schedules.date_time', 'patient' => 'patient', 'covenant' => 'covenant'],
        'claims'   => ['created' => 'billing_claims.created_at', 'attendance' => 'billing_claims.attendance_date', 'patient' => 'patient', 'amount' => 'billing_claims.amount'],
        'batches'  => ['created' => 'billing_batches.created_at', 'period' => 'billing_batches.period_start', 'total' => 'billing_batches.total_amount'],
    ];

    /** Ordem padrão de cada aba (a de antes da paginação). */
    private const DEFAULT_SORTS = [
        'eligible' => ['date', 'asc'],
        'claims'   => ['created', 'desc'],
        'batches'  => ['created', 'desc'],
    ];

    /** Badges seguros no modo escuro (badge-soft-*), por status da guia. */
    private const CLAIM_BADGES = [
        'draft'     => 'badge-soft-secondary',
        'submitted' => 'badge-soft-info',
        'paid'      => 'badge-soft-success',
        'denied'    => 'badge-soft-danger',
        'cancelled' => 'badge-soft-secondary text-decoration-line-through',
    ];

    /** Badges seguros no modo escuro (badge-soft-*), por status do lote. */
    private const BATCH_BADGES = [
        'draft'     => 'badge-soft-secondary',
        'submitted' => 'badge-soft-info',
        'processed' => 'badge-soft-primary',
        'paid'      => 'badge-soft-success',
        'rejected'  => 'badge-soft-danger',
        'cancelled' => 'badge-soft-secondary text-decoration-line-through',
    ];

    public function __construct(
        private readonly BillingService $billingService,
        private readonly ProcedurePriceService $procedurePrices,
        private readonly BillingBatchAttachService $attach,
    ) {
        $this->titleController = 'Faturamento TISS';
    }

    /**
     * Período (data do atendimento) e convênio valem para as três abas e para
     * os KPIs; o status só para as guias; o lote (link do código na aba Guias)
     * filtra guias e lotes daquele lote, independentemente do período. Cada
     * aba é paginada no servidor, com busca e ordem próprias (ver PER_PAGE,
     * SORTS); os KPIs ignoram página e busca.
     */
    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        // Valores inválidos na URL (data 'abc', ids que não são UUID, status
        // inexistente) caem no padrão em vez de virar erro 500 no banco.
        [$from, $to] = ReportPeriod::resolve($request->input('from'), $request->input('to'));
        $covenantId  = ReportPeriod::uuidOrNull($request->input('covenant_id'));
        $claimStatus = $this->claimStatusFilter($request->input('claim_status'));
        $tab         = ReportPeriod::text($request->input('tab'));
        $tab         = in_array($tab, self::TABS, true) ? $tab : self::TABS[0];

        // Lote só vale se for desta clínica (id de outra clínica = sem filtro).
        $batchId   = ReportPeriod::uuidOrNull($request->input('batch_id'));
        $batchCode = $batchId === null ? null : BillingBatch::query()
            ->where('entity_id', $entityId)
            ->whereKey($batchId)
            ->value('code');
        $batchId = $batchCode === null ? null : $batchId;

        // Mapa de preços uma vez: estimativa do KPI "A faturar" e preço sugerido por linha.
        // Os KPIs são do conjunto filtrado inteiro (período/convênio), nunca da
        // página nem da busca de uma aba.
        $priceMap = $this->procedurePrices->priceMap($entityId);
        $kpis     = $this->billingService->billingKpis($entityId, $from, $to, $covenantId, $priceMap);

        $lists    = $this->listParams($request);
        $eligible = $this->eligiblePage($entityId, $from, $to, $covenantId, $lists['eligible'], $priceMap);
        $claims   = $this->claimsPage($entityId, $from, $to, $covenantId, $claimStatus, $batchId, $lists['claims']);
        $batches  = $this->batchesPage($entityId, $from, $to, $covenantId, $batchId, $lists['batches']);

        $covenants = Covenant::query()
            ->where(function ($q) use ($entityId): void {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })
            ->where('active', true)
            ->where('table', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        if ($covenants->isEmpty()) {
            $covenants = Covenant::query()
                ->where(function ($q) use ($entityId): void {
                    $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                })
                ->where('active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();
        }

        return Inertia::render('Panel/Financial/Billing/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_billing.financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial_billing.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            // Paginators do Laravel (data, links, total...), um por aba.
            'eligibleSchedules' => $eligible,
            'claims'            => $claims,
            'batches'           => $batches,
            'kpis'              => $kpis,
            'totals'            => [
                'eligible' => $eligible->total(),
                'claims'   => $claims->total(),
                'batches'  => $batches->total(),
            ],
            // Busca/ordem aplicadas em cada aba (normalizadas) e os padrões.
            'lists' => $lists,
            // Convênio do filtro fora da lista (inativo/excluído, ex.: vindo do
            // relatório de convênios): só o select do filtro o mostra — os modais
            // de novo lote/guia continuam só com os convênios ativos.
            'filteredCovenant' => $covenantId !== null && ! $covenants->contains('id', $covenantId)
                ? Covenant::query()->withTrashed()
                    ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereKey($covenantId)
                    ->first(['id', 'name'])
                    ?->only(['id', 'name'])
                : null,
            'covenants' => $covenants->map(fn ($c) => [
                'id'                => $c->id,
                'name'              => $c->name,
                'has_ans_registry'  => filled($c->ans_registry),
                'has_tiss_operator' => filled($c->tiss_operator_id),
                'tiss_operator_id'  => $c->tiss_operator_id,
            ]),
            'filters' => [
                'from'         => $from,
                'to'           => $to,
                'covenant_id'  => $covenantId,
                'claim_status' => $claimStatus,
                'batch_id'     => $batchId,
                'batch_code'   => $batchCode,
                'tab'          => $tab,
            ],
            'claimStatuses' => [
                ...collect(BillingClaimStatus::cases())
                    ->map(fn (BillingClaimStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                    ->all(),
                ['value' => BillingService::CLAIM_FILTER_TISS_PENDING, 'label' => __('financial_billing.filter_tiss_pending')],
            ],
            'paymentMethods' => collect(BillingService::RECEIPT_PAYMENT_METHODS)
                ->map(fn (PaymentMethod $method) => [
                    'value' => $method->value,
                    'label' => __("financial_billing.payment_methods.{$method->value}"),
                ])
                ->all(),
            'today' => now()->toDateString(),
            // Recebimento em lote (seleção da aba Guias) e tetos por requisição.
            'bulkReceiptUrl'     => route('panel.financial.billing.claims.bulk-receipt'),
            'bulkMaxClaims'      => BillingBulkReceiptService::MAX_CLAIMS,
            'attachMaxClaims'    => BillingBatchAttachService::MAX_CLAIMS,
            'storeIndividualUrl' => route('panel.financial.billing.individual.store'),
            'storeBatchUrl'      => route('panel.financial.billing.batch.store'),
            'importReturnUrl'    => route('panel.financial.billing.import-return'),
            'glosasUrl'          => route('panel.financial.tiss.glosas.index'),
            'procedurePricesUrl' => route('panel.financial.procedure-prices.index'),
            // Busca CID-10 pela rota do financeiro (a do prontuário, cid10.search,
            // exige admin/médico/secretária e o perfil financeiro levaria 403).
            'cid10SearchUrl' => route('panel.financial.cid10.search'),
            'glosaReasons'   => TissGlosaReason::query()
                ->where('active', true)
                ->orderBy('code')
                ->get(['code', 'description'])
                ->map(fn (TissGlosaReason $r) => [
                    'code'        => $r->code,
                    'description' => $r->description,
                    'label'       => "{$r->code} — {$r->description}",
                ]),
            'tussCodes' => TissTussCode::query()
                ->where('active', true)
                ->orderBy('description')
                ->get(['code', 'description'])
                ->map(fn (TissTussCode $c) => [
                    'code'        => $c->code,
                    'description' => $c->description,
                    'label'       => "{$c->code} — {$c->description}",
                ]),
            't' => trans('financial_billing') + ['shared' => trans('financial_shared')],
            // Textos do ConfirmationWithReasonModal (lidos de `t_hardening`) para
            // cancelar guia/lote: mínimo de 10 caracteres, exemplo do
            // faturamento e "Voltar" no lugar do "Cancelar" genérico (ao lado
            // de "Cancelar guia" ficaria ambíguo).
            't_hardening' => fn () => array_merge(
                (array) trans('manager_hardening'),
                (array) trans('financial_billing.cancel_reason_modal'),
            ),
        ]);
    }

    /**
     * Busca e ordem de cada aba, normalizadas: chave fora da whitelist ou
     * direção inválida → padrão da aba; array/objeto na URL → vazio.
     *
     * @return array<string, array{search: string, sort: string, direction: string, default_sort: string, default_direction: string}>
     */
    private function listParams(Request $request): array
    {
        $lists = [];

        foreach (self::TABS as $tab) {
            [$defaultSort, $defaultDirection] = self::DEFAULT_SORTS[$tab];

            $sort      = ReportPeriod::text($request->input("{$tab}_sort"));
            $direction = ReportPeriod::text($request->input("{$tab}_direction"));

            $lists[$tab] = [
                'search'            => mb_substr(ReportPeriod::text($request->input("{$tab}_search")), 0, self::SEARCH_MAX_LENGTH),
                'sort'              => array_key_exists($sort, self::SORTS[$tab]) ? $sort : $defaultSort,
                'direction'         => in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection,
                'default_sort'      => $defaultSort,
                'default_direction' => $defaultDirection,
            ];
        }

        return $lists;
    }

    /**
     * Aba "A faturar", paginada. Busca pelo nome do paciente (cadastro ou o
     * nome digitado no agendamento), sem acento.
     *
     * @param array{search: string, sort: string, direction: string} $list
     * @param array<string, float>                                   $priceMap
     */
    private function eligiblePage(string $entityId, string $from, string $to, ?string $covenantId, array $list, array $priceMap): LengthAwarePaginator
    {
        $query = $this->billingService->eligibleSchedulesQuery($entityId, $from, $to, $covenantId)
            ->with(['patient.person', 'doctor.person', 'covenant', 'visitType.procedure']);

        if ($list['search'] !== '') {
            $pattern = $this->likePattern($list['search']);

            $query->where(fn (Builder $w) => $w
                ->whereRaw('unaccent(schedules.full_name) ILIKE unaccent(?)', [$pattern])
                ->orWhereExists(fn (QueryBuilder $s) => $this->patientNameMatch($s, 'schedules.patient_id', $entityId, $pattern)));
        }

        $this->orderList($query, 'eligible', $list, 'schedules');

        return $query->paginate(self::PER_PAGE['eligible'], ['*'], 'eligible_page')
            ->withQueryString()
            ->appends('tab', 'eligible')
            ->through(fn (Schedule $s) => $this->eligibleRow($s, $priceMap));
    }

    /**
     * Aba "Guias", paginada. Busca pelo código GUI, código LOT do lote, nº da
     * guia TISS (prestador/operadora) ou nome do paciente — cada subconsulta
     * também escopada pela clínica.
     *
     * @param array{search: string, sort: string, direction: string} $list
     */
    private function claimsPage(
        string $entityId,
        string $from,
        string $to,
        ?string $covenantId,
        ?string $claimStatus,
        ?string $batchId,
        array $list,
    ): LengthAwarePaginator {
        $query = $this->billingService->claimsListQuery($entityId, $from, $to, $covenantId, $claimStatus, $batchId)
            ->with(['batch.covenant', 'batch.tissBatch', 'patient.person', 'doctor.person', 'covenant', 'tissGuide', 'cancelledBy']);

        if ($list['search'] !== '') {
            $pattern = $this->likePattern($list['search']);

            $query->where(fn (Builder $w) => $w
                ->whereRaw('billing_claims.code ILIKE ?', [$pattern])
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                    ->from('billing_batches')
                    ->whereColumn('billing_batches.id', 'billing_claims.batch_id')
                    ->where('billing_batches.entity_id', $entityId)
                    ->whereRaw('billing_batches.code ILIKE ?', [$pattern]))
                ->orWhereExists(fn (QueryBuilder $s) => $this->tissGuideNumberMatch($s, 'billing_claims.tiss_guide_id', $entityId, $pattern))
                ->orWhereExists(fn (QueryBuilder $s) => $this->patientNameMatch($s, 'billing_claims.patient_id', $entityId, $pattern)));
        }

        $this->orderList($query, 'claims', $list, 'billing_claims');

        $page = $query->paginate(self::PER_PAGE['claims'], ['*'], 'claims_page')
            ->withQueryString()
            ->appends('tab', 'claims');

        // Bloqueios de recebimento (refaturada / receita já lançada) em 2
        // consultas e convênios com lote de destino ("Incluir em lote") em 1,
        // para a página inteira, em vez de por guia.
        $payBlockers   = $this->billingService->claimPayBlockers($page->getCollection());
        $attachTargets = $this->attach->covenantsWithAttachTarget($entityId, $page->getCollection()->pluck('covenant_id')->all());

        return $page->through(fn (BillingClaim $c) => $this->claimRow($c, $payBlockers, $attachTargets));
    }

    /**
     * Aba "Lotes", paginada. Busca pelo código LOT, nº do lote TISS ou por uma
     * guia do lote (GUI, nº TISS ou paciente). `claims_count` conta só as
     * guias ativas (cancelada fica no lote como histórico).
     *
     * @param array{search: string, sort: string, direction: string} $list
     */
    private function batchesPage(string $entityId, string $from, string $to, ?string $covenantId, ?string $batchId, array $list): LengthAwarePaginator
    {
        $query = $this->billingService->batchesListQuery($entityId, $from, $to, $covenantId, $batchId)
            ->with(['covenant', 'tissBatch', 'cancelledBy'])
            ->withCount([
                'claims' => fn (Builder $q) => $q->where('billing_claims.status', '!=', BillingClaimStatus::Cancelled->value),
                // Guias à espera de recebimento ("Registrar recebimento do lote").
                'claims as open_claims_count' => fn (Builder $q) => BillingBulkReceiptService::awaitingReceipt($q),
            ]);

        if ($list['search'] !== '') {
            $pattern = $this->likePattern($list['search']);

            $query->where(fn (Builder $w) => $w
                ->whereRaw('billing_batches.code ILIKE ?', [$pattern])
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                    ->from('tiss_batches')
                    ->whereColumn('tiss_batches.id', 'billing_batches.tiss_batch_id')
                    ->where('tiss_batches.entity_id', $entityId)
                    ->whereRaw('tiss_batches.batch_number ILIKE ?', [$pattern]))
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                    ->from('billing_claims')
                    ->whereColumn('billing_claims.batch_id', 'billing_batches.id')
                    ->where('billing_claims.entity_id', $entityId)
                    ->whereNull('billing_claims.deleted_at')
                    ->where(fn (QueryBuilder $c) => $c
                        ->whereRaw('billing_claims.code ILIKE ?', [$pattern])
                        ->orWhereExists(fn (QueryBuilder $g) => $this->tissGuideNumberMatch($g, 'billing_claims.tiss_guide_id', $entityId, $pattern))
                        ->orWhereExists(fn (QueryBuilder $p) => $this->patientNameMatch($p, 'billing_claims.patient_id', $entityId, $pattern)))));
        }

        $this->orderList($query, 'batches', $list, 'billing_batches');

        return $query->paginate(self::PER_PAGE['batches'], ['*'], 'batches_page')
            ->withQueryString()
            ->appends('tab', 'batches')
            ->through(fn (BillingBatch $b) => $this->batchRow($b));
    }

    /**
     * ORDER BY pela whitelist da aba + id como desempate (paginação
     * determinística). $table é sempre um literal desta classe.
     *
     * @param array{sort: string, direction: string} $list
     */
    private function orderList(Builder $query, string $tab, array $list, string $table): void
    {
        $column = self::SORTS[$tab][$list['sort']];

        $expression = match ($column) {
            'patient' => DB::table('patients')
                ->join('people', 'people.id', '=', 'patients.person_id')
                ->select('people.full_name')
                ->whereColumn('patients.id', "{$table}.patient_id")
                ->limit(1),
            'covenant' => DB::table('covenants')
                ->select('covenants.name')
                ->whereColumn('covenants.id', "{$table}.covenant_id")
                ->limit(1),
            default => $column,
        };

        $query->orderBy($expression, $list['direction'])->orderBy("{$table}.id", $list['direction']);
    }

    /** Termo literal para ILIKE: %, _ e \ escapados (senão "50%" ou "a_b" casariam qualquer coisa). */
    private function likePattern(string $search): string
    {
        return '%' . addcslashes($search, '\\%_') . '%';
    }

    /** EXISTS: paciente da clínica cujo nome (sem acento) contém o termo. */
    private function patientNameMatch(QueryBuilder $sub, string $patientColumn, string $entityId, string $pattern): void
    {
        $sub->selectRaw('1')
            ->from('patients')
            ->join('people', 'people.id', '=', 'patients.person_id')
            ->whereColumn('patients.id', $patientColumn)
            ->where('patients.entity_id', $entityId)
            ->whereRaw('unaccent(people.full_name) ILIKE unaccent(?)', [$pattern]);
    }

    /** EXISTS: guia TISS da clínica com o nº do prestador ou da operadora contendo o termo. */
    private function tissGuideNumberMatch(QueryBuilder $sub, string $guideColumn, string $entityId, string $pattern): void
    {
        $sub->selectRaw('1')
            ->from('tiss_guides')
            ->whereColumn('tiss_guides.id', $guideColumn)
            ->where('tiss_guides.entity_id', $entityId)
            ->where(fn (QueryBuilder $g) => $g
                ->whereRaw('tiss_guides.guide_number_provider ILIKE ?', [$pattern])
                ->orWhereRaw('tiss_guides.guide_number_operator ILIKE ?', [$pattern]));
    }

    /** Status da guia ou o filtro "pendência TISS"; qualquer outro valor = sem filtro. */
    private function claimStatusFilter(mixed $value): ?string
    {
        $value = ReportPeriod::text($value);

        if ($value === BillingService::CLAIM_FILTER_TISS_PENDING) {
            return $value;
        }

        return BillingClaimStatus::tryFrom($value)?->value;
    }

    /**
     * Linha da aba A faturar, com o preço sugerido pela tabela de preços
     * (procedimento do tipo de atendimento × convênio) — só sugestão: o valor
     * gravado é o que o usuário confirma no formulário.
     *
     * @param array<string, float> $priceMap
     *
     * @return array<string, mixed>
     */
    private function eligibleRow(Schedule $s, array $priceMap): array
    {
        $procedureId = $s->visitType?->procedure_id;
        $suggested   = filled($procedureId) && filled($s->covenant_id)
            ? ($priceMap["{$s->covenant_id}:{$procedureId}"] ?? null)
            : null;

        return [
            'id'              => $s->id,
            'date_time'       => $s->date_time?->toIso8601String(),
            'patient_name'    => $s->patient?->person?->full_name,
            'doctor_name'     => $s->doctor?->person?->full_name,
            'covenant_name'   => $s->covenant?->name,
            'covenant_id'     => $s->covenant_id,
            'visit_type'      => $s->visitType?->name,
            'procedure_name'  => $s->visitType?->procedure?->name,
            'suggested_price' => $suggested === null ? null : round((float) $suggested, 2),
        ];
    }

    /**
     * Linha da aba Guias. Datas em ISO (a tela formata pelo idioma) e ações
     * permitidas calculadas no servidor (mesma regra dos guards do service).
     * Guia TISS em aberto fora de lote TISS: "pendência" quando a
     * pré-validação barrou (erro), "fora de lote" quando é individual.
     *
     * @param array{rebilled: array<string, true>, launched: array<string, true>} $payBlockers
     * @param array<string, true>                                                 $attachTargets convênios com lote de destino
     *
     * @return array<string, mixed>
     */
    private function claimRow(BillingClaim $c, array $payBlockers, array $attachTargets): array
    {
        $status = $c->status instanceof BillingClaimStatus ? $c->status : BillingClaimStatus::tryFrom((string) $c->status);

        $isTiss        = filled($c->tiss_guide_id);
        $isOpen        = in_array($status, [BillingClaimStatus::Draft, BillingClaimStatus::Submitted], true);
        $outOfTissLot  = $isTiss && $isOpen && ! $c->getAttribute('tiss_attached');
        $hasTissErrors = $outOfTissLot && $this->guideHasErrors($c->tissGuide);
        $allowed       = $this->billingService->allowedClaimActions($c, $payBlockers, $attachTargets);

        return [
            'id'                => $c->id,
            'code'              => $c->code,
            'created_at'        => $c->created_at?->toIso8601String(),
            'attendance_date'   => $c->attendance_date?->toDateString(),
            'patient_name'      => $c->patient?->person?->full_name,
            'doctor_name'       => $c->doctor?->person?->full_name,
            'covenant_name'     => $c->covenant?->name,
            'batch_id'          => $c->batch_id,
            'batch_code'        => $c->batch?->code,
            'status'            => $status?->value ?? (string) $c->status,
            'status_label'      => $status?->label() ?? (string) $c->status,
            'status_badge'      => self::CLAIM_BADGES[$status?->value ?? ''] ?? 'badge-soft-secondary',
            'amount'            => (float) ($c->amount ?? 0),
            'glosa_amount'      => (float) ($c->glosa_amount ?? 0),
            'paid_amount'       => (float) ($c->paid_amount ?? 0),
            'paid_at'           => $c->paid_at?->toDateString(),
            'receivable_amount' => $this->billingService->receivableAmount($c),
            'guide_number'      => $c->tissGuide?->guide_number_provider,
            'is_tiss'           => $isTiss,
            'has_pending_guide' => $hasTissErrors,
            'out_of_batch'      => $outOfTissLot && ! $hasTissErrors,
            'pre_validate_url'  => $c->tiss_guide_id
                ? route('panel.financial.tiss.guides.pre-validate', $c->tiss_guide_id)
                : null,
            'allowed_actions' => $allowed,
            'mark_paid_url'   => route('panel.financial.billing.claims.paid', $c->id),
            'mark_denied_url' => route('panel.financial.billing.claims.denied', $c->id),
            'cancel_url'      => route('panel.financial.billing.claims.cancel', $c->id),
            // Dados atuais da guia TISS só quando a correção é permitida (o
            // modal abre preenchido; carteirinha não vai para as outras linhas).
            'fix_pending' => in_array(BillingService::CLAIM_ACTION_FIX_PENDING, $allowed, true) ? [
                'url'                     => route('panel.financial.billing.claims.fix-pending', $c->id),
                'clinical_indication'     => $c->tissGuide?->clinical_indication,
                'beneficiary_card_number' => $c->tissGuide?->beneficiary_card_number,
                'authorization_number'    => $c->tissGuide?->authorization_number,
            ] : null,
            'attach_targets_url' => in_array(BillingService::CLAIM_ACTION_ATTACH, $allowed, true)
                ? route('panel.financial.billing.claims.attach-targets', $c->id)
                : null,
            'cancelled_at'      => $c->cancelled_at?->toIso8601String(),
            'cancelled_by_name' => $c->cancelled_at ? $c->cancelledBy?->name : null,
            'cancel_reason'     => $c->cancel_reason,
        ];
    }

    /**
     * Linha da aba Lotes, com o resumo usado na confirmação de envio
     * (código, convênio/operadora, guias incluídas × pendentes, total).
     *
     * @return array<string, mixed>
     */
    private function batchRow(BillingBatch $b): array
    {
        $status       = $b->status instanceof BillingBatchStatus ? $b->status : BillingBatchStatus::tryFrom((string) $b->status);
        $claimsCount  = (int) $b->claims_count;
        $isParticular = $this->billingService->isParticularBatch($b);
        $allowed      = $this->billingService->allowedBatchActions($b);
        $can          = fn (string $action): bool => in_array($action, $allowed, true);

        // Lote TISS: só as guias anexadas ao lote TISS vão para a operadora;
        // as com pendência ficam fora (contadas à parte). Lote cancelado: nada vai.
        $includedCount = match (true) {
            $status === BillingBatchStatus::Cancelled => 0,
            $b->tiss_batch_id && $b->tissBatch        => (int) $b->tissBatch->guides_count,
            default                                   => $claimsCount,
        };

        return [
            'id'              => $b->id,
            'code'            => $b->code,
            'created_at'      => $b->created_at?->toIso8601String(),
            'submitted_at'    => $b->submitted_at?->toIso8601String(),
            'period_start'    => $b->period_start?->toDateString(),
            'period_end'      => $b->period_end?->toDateString(),
            'covenant_name'   => $b->covenant?->name,
            'claims_count'    => $claimsCount,
            'included_count'  => $includedCount,
            'pending_count'   => max(0, $claimsCount - $includedCount),
            'total_amount'    => (float) ($b->total_amount ?? 0),
            'status'          => $status?->value ?? (string) $b->status,
            'status_label'    => $status?->label() ?? (string) $b->status,
            'status_badge'    => self::BATCH_BADGES[$status?->value ?? ''] ?? 'badge-soft-secondary',
            'is_tiss'         => (bool) $b->tiss_batch_id,
            'is_particular'   => $isParticular,
            'notes'           => $b->notes,
            'allowed_actions' => $allowed,
            'submit_url'      => route('panel.financial.billing.batches.submit', $b->id),
            'xml_url'         => route('panel.financial.billing.batches.xml', $b->id),
            'cancel_url'      => route('panel.financial.billing.batches.cancel', $b->id),
            // Ações em lote (BillingBulkActionsController), só quando permitidas.
            'attachable_claims_url' => $can(BillingService::BATCH_ACTION_ADD_CLAIMS) ? route('panel.financial.billing.batches.attachable-claims', $b->id) : null,
            'attach_claims_url'     => $can(BillingService::BATCH_ACTION_ADD_CLAIMS) ? route('panel.financial.billing.batches.attach-claims', $b->id) : null,
            'reprocess_url'         => $can(BillingService::BATCH_ACTION_REPROCESS) ? route('panel.financial.billing.batches.reprocess-pending', $b->id) : null,
            'receipt_preview_url'   => $can(BillingService::BATCH_ACTION_RECEIVE) ? route('panel.financial.billing.batches.receipt-preview', $b->id) : null,
            'receipt_url'           => $can(BillingService::BATCH_ACTION_RECEIVE) ? route('panel.financial.billing.batches.receipt', $b->id) : null,
            'cancelled_at'          => $b->cancelled_at?->toIso8601String(),
            'cancelled_by_name'     => $b->cancelled_at ? $b->cancelledBy?->name : null,
            'cancel_reason'         => $b->cancel_reason,
        ];
    }

    public function storeIndividual(BillingIndividualRequest $request): RedirectResponse
    {
        $this->authorizeFinancial();

        $this->billingService->createIndividual($request->validated());

        return back()->with('success', __('financial_billing.flash.individual_created'));
    }

    public function storeBatch(BillingBatchRequest $request): RedirectResponse
    {
        $this->authorizeFinancial();

        $batch = $this->billingService->createBatch($request->validated());

        // Informa quantas guias entraram e quantas ficaram de fora (pendência
        // TISS) — antes o flash só citava o código e o usuário achava que tudo
        // que marcou tinha ido para o lote.
        $included = $batch->tiss_batch_id
            ? (int) ($batch->tissBatch?->guides_count ?? 0)
            : $batch->claims()->count();
        $pending = max(0, (int) $batch->total_claims - $included);

        return back()->with('success', $pending > 0
            ? __('financial_billing.flash.batch_created_detail', ['code' => $batch->code, 'included' => $included, 'pending' => $pending])
            : __('financial_billing.flash.batch_created', ['code' => $batch->code, 'included' => $included]));
    }

    public function submitBatch(BillingBatch $batch): RedirectResponse
    {
        $this->authorizeFinancial();

        $batch = $this->billingService->submitBatch($batch);

        return back()->with('success', $this->billingService->isParticularBatch($batch)
            ? __('financial_billing.flash.batch_charged', ['code' => $batch->code])
            : __('financial_billing.flash.batch_submitted', ['code' => $batch->code]));
    }

    public function exportBatchXml(BillingBatch $batch)
    {
        $this->authorizeFinancial();

        // Mesma regra de allowed_actions: lote particular não tem XML TISS. Sem
        // isto, o acesso direto à URL gerava XML pelo gerador legado e marcava
        // as guias como exportadas.
        if (! in_array(BillingService::BATCH_ACTION_DOWNLOAD_XML, $this->billingService->allowedBatchActions($batch), true)) {
            return back()->with('error', $batch->status === BillingBatchStatus::Cancelled
                ? __('financial_billing.errors.batch_xml_cancelled', ['code' => $batch->code])
                : __('financial_billing.errors.batch_xml_particular', ['code' => $batch->code]));
        }

        if (blank($batch->xml_path) || ! Storage::disk()->exists($batch->xml_path)) {
            try {
                $batch = $this->billingService->generateBatchXml($batch);
            } catch (ValidationException $e) {
                // Lote cancelado enquanto o download esperava o lock (link comum,
                // não Inertia): volta com o aviso no flash em vez de erro solto.
                return back()->with('error', (string) collect($e->errors())->flatten()->first());
            }
        }

        return Storage::disk()->download(
            $batch->xml_path,
            mb_strtolower($batch->code) . '.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function markClaimPaid(MarkClaimPaidRequest $request, BillingClaim $claim): RedirectResponse
    {
        $this->authorizeFinancial();

        // O service trata guia já paga como no-op idempotente (retry/duplo
        // clique). Aqui a tela precisa saber que nada foi gravado: aba
        // desatualizada ou outro usuário já registrou o recebimento.
        if ($claim->status === BillingClaimStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => __('financial_billing.errors.claim_already_paid', ['code' => $claim->code]),
            ]);
        }

        $this->billingService->markClaimPaid($claim, $request->validated());

        return back()->with('success', __('financial_billing.flash.claim_paid', ['code' => $claim->code]));
    }

    public function markClaimDenied(MarkClaimDeniedRequest $request, BillingClaim $claim): RedirectResponse
    {
        $this->authorizeFinancial();

        $this->billingService->markClaimDenied($claim, $request->validated());

        return back()->with('success', __('financial_billing.flash.claim_denied', ['code' => $claim->code]));
    }

    public function importReturn(Request $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();

        $validated = $request->validate([
            'covenant_id' => ['required', 'uuid'],
            'xml_file'    => ['required', 'file', 'extensions:xml', 'max:10240'],
        ]);

        $covenant = Covenant::query()
            ->where('id', $validated['covenant_id'])
            ->where(function ($q) use ($entity): void {
                $q->where('entity_id', (string) $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();

        if (blank($covenant->tiss_operator_id)) {
            return back()->with('error', __('financial_billing.errors.import_return_no_operator'));
        }

        $xmlContent = file_get_contents($validated['xml_file']->getRealPath());

        try {
            $return = app(TissWorkflowService::class)->receiveResponse(
                entityId: (string) $entity->id,
                operatorId: (string) $covenant->tiss_operator_id,
                xmlContent: (string) $xmlContent,
                processSynchronously: true,
            );
        } catch (Throwable $e) {
            // Sem mensagem nem stack no log (LGPD): mensagem/stack do parser de
            // XML de retorno TISS podem conter dado de paciente (cartão, nome)
            // vindo do arquivo da operadora — o stack traz até os argumentos.
            // Classe + origem bastam para localizar a falha.
            Log::error('Falha ao importar retorno TISS.', [
                'entity_id'   => (string) $entity->id,
                'covenant_id' => (string) $covenant->id,
                'exception'   => $e::class,
                'origin'      => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            report(new RuntimeException(sprintf(
                'Falha ao importar retorno TISS (entity=%s, covenant=%s, exceção=%s).',
                $entity->id,
                $covenant->id,
                $e::class,
            )));

            return back()->with('error', __('financial_billing.errors.import_return_failed'));
        }

        $glosaCount = (int) ($return->summary['glosa_count'] ?? 0);

        return back()->with(
            'success',
            $glosaCount > 0
                ? __('financial_billing.flash.import_return_success', ['count' => $glosaCount])
                : __('financial_billing.flash.import_return_empty'),
        );
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
