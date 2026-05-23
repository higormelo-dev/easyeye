<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\AiCreditPurchase;
use App\Domains\AI\Services\{AiCreditPurchaseService, AiCreditWalletService};
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Manager: gerencia pedidos de compra de créditos IA das clínicas.
 *
 * Autorização granular (matriz RBAC):
 *   - index/show: SaasAccess (qualquer SaaS) — apenas leitura
 *   - credit:    SaasFinancial (Admin + Financial) — confirma pagamento, libera créditos
 *   - cancel:    SaasSupport (Admin + Support) — atendimento ao cliente
 *   - markFailed: SaasFinancial (Admin + Financial) — métrica de gateway
 *   - refund:    SaasAdminPanel (somente Admin) — operação destrutiva
 *
 * Toda ação que altera estado escreve no audit_log (CFM/LGPD trilha).
 */
class AiCreditPurchasesController extends Controller
{
    public function __construct(
        private readonly AiCreditPurchaseService $purchaseService,
        private readonly AiCreditWalletService $walletService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasAccess);

        $filters = $request->validate([
            'status'    => ['nullable', 'string', Rule::in(array_map(fn (AiCreditPurchaseStatus $s) => $s->value, AiCreditPurchaseStatus::cases()))],
            'entity_id' => ['nullable', 'string', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d'],
            'q'         => ['nullable', 'string', 'max:120'],
        ]);

        $query = AiCreditPurchase::query()
            ->with(['entity:id,name', 'requestedBy:id,name,email'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $v) . '%';
                $q->whereHas('entity', fn ($qe) => $qe->where('name', 'ilike', $like));
            })
            ->orderByDesc('created_at');

        $purchases = $query->paginate(20)->withQueryString();

        return Inertia::render('Panel/Manager/AiCreditPurchases/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'),             'url' => route('manager.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.ai_credit_purchases'),   'url' => '#',                        'active' => true],
            ],
            'purchases' => [
                'data' => $purchases->getCollection()->map(fn (AiCreditPurchase $p) => $this->serializeRow($p))->all(),
                'meta' => [
                    'current_page' => $purchases->currentPage(),
                    'last_page'    => $purchases->lastPage(),
                    'per_page'     => $purchases->perPage(),
                    'total'        => $purchases->total(),
                    'from'         => $purchases->firstItem(),
                    'to'           => $purchases->lastItem(),
                ],
                'links' => [
                    'prev' => $purchases->previousPageUrl(),
                    'next' => $purchases->nextPageUrl(),
                ],
            ],
            'kpis'         => $this->kpis(),
            'topConsumers' => $this->topConsumersLast30Days(),
            'filters'      => $filters,
            'statusOptions' => collect(AiCreditPurchaseStatus::cases())->map(fn (AiCreditPurchaseStatus $s) => [
                'value' => $s->value,
                'label' => __("ai.credit_purchase_status.{$s->value}"),
            ])->values(),
            'entities' => Entity::query()
                ->where('is_client', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Entity $e) => ['id' => (string) $e->id, 'name' => $e->name]),
            'permissions' => [
                'credit' => Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity()),
                'cancel' => Gate::allows(EntityGate::SaasSupport->value, $this->currentSaasEntity()),
                'fail'   => Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity()),
                'refund' => Gate::allows(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity()),
            ],
            't' => trans('manager_ai_credit_purchases'),
        ]);
    }

    public function show(AiCreditPurchase $purchase): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasAccess);

        $purchase->load(['entity:id,name', 'requestedBy:id,name,email', 'subscription.plan']);

        return response()->json([
            'purchase' => array_merge($this->serializeRow($purchase), [
                'metadata'        => (array) $purchase->metadata,
                'description'     => $purchase->description,
                'idempotency_key' => $purchase->idempotency_key,
                'subscription'    => $purchase->subscription ? [
                    'id'        => (string) $purchase->subscription->id,
                    'plan_name' => $purchase->subscription->plan?->name,
                ] : null,
                'wallet_after' => $this->walletService->balance((string) $purchase->entity_id),
            ]),
        ]);
    }

    public function credit(Request $request, AiCreditPurchase $purchase): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasFinancial);

        try {
            $purchase = $this->purchaseService->creditPaidPurchase($purchase, (string) auth()->id());
        } catch (DomainException $e) {
            return response()->json([
                'message' => __("manager_ai_credit_purchases.errors.{$e->getMessage()}", []) ?: $e->getMessage(),
            ], 422);
        }

        $this->audit->recordAdminAction(
            event: 'ai_credit_purchase.credited',
            targetEntityId: (string) $purchase->entity_id,
            targetUserId: $purchase->requested_by ? (string) $purchase->requested_by : null,
            auditableType: 'ai_credit_purchase',
            auditableId: (string) $purchase->id,
            reason: 'Manual credit by SaaS Manager',
            newValues: [
                'credits'      => $purchase->credits,
                'amount_cents' => $purchase->amount_cents,
                'package_code' => $purchase->package_code,
            ],
            request: $request,
        );

        return response()->json([
            'message'  => __('manager_ai_credit_purchases.actions.credited'),
            'purchase' => $this->serializeRow($purchase->refresh()),
        ]);
    }

    public function cancel(Request $request, AiCreditPurchase $purchase): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasSupport);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $purchase = $this->purchaseService->cancelPurchase(
                $purchase,
                $validated['reason'],
                (string) auth()->id(),
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => __("manager_ai_credit_purchases.errors.{$e->getMessage()}", []) ?: $e->getMessage(),
            ], 422);
        }

        $this->audit->recordAdminAction(
            event: 'ai_credit_purchase.cancelled',
            targetEntityId: (string) $purchase->entity_id,
            targetUserId: $purchase->requested_by ? (string) $purchase->requested_by : null,
            auditableType: 'ai_credit_purchase',
            auditableId: (string) $purchase->id,
            reason: $validated['reason'],
            newValues: ['package_code' => $purchase->package_code],
            request: $request,
        );

        return response()->json([
            'message'  => __('manager_ai_credit_purchases.actions.cancelled'),
            'purchase' => $this->serializeRow($purchase->refresh()),
        ]);
    }

    public function markFailed(Request $request, AiCreditPurchase $purchase): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasFinancial);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $purchase = $this->purchaseService->markAsFailed(
                $purchase,
                $validated['reason'],
                (string) auth()->id(),
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => __("manager_ai_credit_purchases.errors.{$e->getMessage()}", []) ?: $e->getMessage(),
            ], 422);
        }

        $this->audit->recordAdminAction(
            event: 'ai_credit_purchase.marked_failed',
            targetEntityId: (string) $purchase->entity_id,
            targetUserId: $purchase->requested_by ? (string) $purchase->requested_by : null,
            auditableType: 'ai_credit_purchase',
            auditableId: (string) $purchase->id,
            reason: $validated['reason'],
            newValues: ['package_code' => $purchase->package_code],
            request: $request,
        );

        return response()->json([
            'message'  => __('manager_ai_credit_purchases.actions.marked_failed'),
            'purchase' => $this->serializeRow($purchase->refresh()),
        ]);
    }

    public function refund(Request $request, AiCreditPurchase $purchase): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasAdminPanel);

        $validated = $request->validate([
            'reason'         => ['required', 'string', 'min:5', 'max:500'],
            'acknowledge_negative_balance' => ['sometimes', 'boolean'],
        ]);

        try {
            $purchase = $this->purchaseService->refundPurchase(
                $purchase,
                $validated['reason'],
                (string) auth()->id(),
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => __("manager_ai_credit_purchases.errors.{$e->getMessage()}", []) ?: $e->getMessage(),
            ], 422);
        }

        $this->audit->recordAdminAction(
            event: 'ai_credit_purchase.refunded',
            targetEntityId: (string) $purchase->entity_id,
            targetUserId: $purchase->requested_by ? (string) $purchase->requested_by : null,
            auditableType: 'ai_credit_purchase',
            auditableId: (string) $purchase->id,
            reason: $validated['reason'],
            newValues: [
                'credits_revoked' => $purchase->credits,
                'amount_cents'    => $purchase->amount_cents,
                'package_code'    => $purchase->package_code,
            ],
            request: $request,
        );

        return response()->json([
            'message'      => __('manager_ai_credit_purchases.actions.refunded'),
            'purchase'     => $this->serializeRow($purchase->refresh()),
            'wallet_after' => $this->walletService->balance((string) $purchase->entity_id),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function authorizeSaasEntity(EntityGate $gate): void
    {
        Gate::authorize($gate->value, $this->currentSaasEntity());
    }

    private function currentSaasEntity(): Entity
    {
        return Entity::findOrFail(session('selected_entity_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(AiCreditPurchase $p): array
    {
        $status = $p->status instanceof AiCreditPurchaseStatus
            ? $p->status
            : AiCreditPurchaseStatus::tryFrom((string) $p->status);

        return [
            'id'               => (string) $p->id,
            'entity_id'        => (string) $p->entity_id,
            'entity_name'      => $p->entity?->name,
            'package_code'     => (string) $p->package_code,
            'package_name'     => __("ai.credit_packages.{$p->package_code}.name"),
            'credits'          => (int) $p->credits,
            'amount_cents'     => (int) $p->amount_cents,
            'amount_formatted' => $this->formatMoney((int) $p->amount_cents, (string) $p->currency),
            'currency'         => (string) $p->currency,
            'status'           => $status?->value ?? (string) $p->status,
            'status_label'     => $status ? __("ai.credit_purchase_status.{$status->value}") : (string) $p->status,
            'status_badge'     => $this->statusBadge($status),
            'requested_by'     => $p->requestedBy?->name,
            'requested_email'  => $p->requestedBy?->email,
            'created_at'       => $p->created_at?->format('d/m/Y H:i'),
            'credited_at'      => $p->credited_at?->format('d/m/Y H:i'),
            'cancelled_at'     => $p->cancelled_at?->format('d/m/Y H:i'),
            'failed_at'        => $p->failed_at?->format('d/m/Y H:i'),
            'refunded_at'      => $p->refunded_at?->format('d/m/Y H:i'),
            'actions_url'      => [
                'show'   => route('manager.ai-credit-purchases.show', $p),
                'credit' => route('manager.ai-credit-purchases.credit', $p),
                'cancel' => route('manager.ai-credit-purchases.cancel', $p),
                'fail'   => route('manager.ai-credit-purchases.fail', $p),
                'refund' => route('manager.ai-credit-purchases.refund', $p),
            ],
            'allowed' => [
                'credit' => $status === AiCreditPurchaseStatus::PendingPayment,
                'cancel' => $status === AiCreditPurchaseStatus::PendingPayment,
                'fail'   => $status === AiCreditPurchaseStatus::PendingPayment,
                'refund' => $status === AiCreditPurchaseStatus::Credited,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpis(): array
    {
        $thirtyDaysAgo = CarbonImmutable::now()->subDays(30);

        // Pendentes
        $pending = AiCreditPurchase::query()
            ->where('status', AiCreditPurchaseStatus::PendingPayment)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount_cents), 0) as sum_cents')
            ->first();

        // Creditados nos últimos 30 dias
        $credited30d = AiCreditPurchase::query()
            ->where('status', AiCreditPurchaseStatus::Credited)
            ->where('credited_at', '>=', $thirtyDaysAgo)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount_cents), 0) as sum_cents, COALESCE(SUM(credits), 0) as credits')
            ->first();

        // Funil de conversão (últimos 30 dias por created_at)
        $statusesLast30d = AiCreditPurchase::query()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $total30d     = array_sum($statusesLast30d);
        $credited     = (int) ($statusesLast30d[AiCreditPurchaseStatus::Credited->value] ?? 0);
        $cancelled    = (int) ($statusesLast30d[AiCreditPurchaseStatus::Cancelled->value] ?? 0);
        $failed       = (int) ($statusesLast30d[AiCreditPurchaseStatus::Failed->value] ?? 0);
        $conversion   = $total30d > 0 ? round(($credited / $total30d) * 100, 1) : 0.0;
        $abandonment  = $total30d > 0 ? round((($cancelled + $failed) / $total30d) * 100, 1) : 0.0;

        return [
            'pending' => [
                'count'           => (int) ($pending->cnt ?? 0),
                'amount_cents'    => (int) ($pending->sum_cents ?? 0),
                'amount_formatted' => $this->formatMoney((int) ($pending->sum_cents ?? 0), 'BRL'),
            ],
            'credited_30d' => [
                'count'            => (int) ($credited30d->cnt ?? 0),
                'amount_cents'     => (int) ($credited30d->sum_cents ?? 0),
                'amount_formatted' => $this->formatMoney((int) ($credited30d->sum_cents ?? 0), 'BRL'),
                'credits_sold'     => (int) ($credited30d->credits ?? 0),
            ],
            'funnel_30d' => [
                'total'              => $total30d,
                'credited'           => $credited,
                'cancelled'          => $cancelled,
                'failed'             => $failed,
                'conversion_pct'     => $conversion,
                'abandonment_pct'    => $abandonment,
            ],
        ];
    }

    /**
     * Top 5 clínicas por créditos comprados nos últimos 30 dias.
     *
     * @return list<array<string, mixed>>
     */
    private function topConsumersLast30Days(): array
    {
        $thirtyDaysAgo = CarbonImmutable::now()->subDays(30);

        return AiCreditPurchase::query()
            ->where('status', AiCreditPurchaseStatus::Credited)
            ->where('credited_at', '>=', $thirtyDaysAgo)
            ->join('entities', 'entities.id', '=', 'ai_credit_purchases.entity_id')
            ->select([
                'ai_credit_purchases.entity_id',
                'entities.name as entity_name',
                DB::raw('SUM(ai_credit_purchases.credits) as credits_total'),
                DB::raw('SUM(ai_credit_purchases.amount_cents) as amount_total'),
                DB::raw('COUNT(*) as purchases_total'),
            ])
            ->groupBy('ai_credit_purchases.entity_id', 'entities.name')
            ->orderByDesc('credits_total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'entity_id'        => (string) $row->entity_id,
                'entity_name'      => $row->entity_name,
                'credits_total'    => (int) $row->credits_total,
                'amount_total'     => (int) $row->amount_total,
                'amount_formatted' => $this->formatMoney((int) $row->amount_total, 'BRL'),
                'purchases_total'  => (int) $row->purchases_total,
            ])
            ->all();
    }

    private function statusBadge(?AiCreditPurchaseStatus $status): string
    {
        return match ($status) {
            AiCreditPurchaseStatus::PendingPayment => 'bg-warning-subtle text-warning',
            AiCreditPurchaseStatus::Credited       => 'bg-success-subtle text-success',
            AiCreditPurchaseStatus::Cancelled      => 'bg-secondary-subtle text-secondary',
            AiCreditPurchaseStatus::Failed         => 'bg-danger-subtle text-danger',
            AiCreditPurchaseStatus::Refunded       => 'bg-info-subtle text-info',
            default                                => 'bg-light text-muted',
        };
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $value = number_format($cents / 100, 2, ',', '.');

        return $currency === 'BRL' ? "R$ {$value}" : "{$currency} {$value}";
    }
}
