<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\{AiCreditPurchase, AiProviderTopup};
use App\Domains\AI\Services\{AiCreditPurchaseService, AiCreditWalletService, AiProviderCostService};
use App\Enums\AI\{AiCreditPurchaseStatus, AiProvider};
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
 * Manager: gerencia compras de créditos IA das clínicas e da empresa SaaS.
 *
 * Autorização granular (matriz RBAC):
 *   - index/show     : SaasAccess (qualquer SaaS) — apenas leitura
 *   - credit         : SaasFinancial — confirma pagamento, libera créditos
 *   - cancel         : SaasSupport — atendimento ao cliente
 *   - markFailed     : SaasFinancial — métrica de gateway
 *   - refund         : SaasAdminPanel (Admin only) — operação destrutiva
 *   - createManual   : SaasFinancial (sem limite) OU SaasSupport (com limite diário)
 *
 * Toda ação que altera estado escreve no audit_log (CFM/LGPD trilha).
 */
class AiCreditPurchasesController extends Controller
{
    /** Limite diário (em créditos) para usuários Support criarem manual. Admin/Financial: sem limite. */
    private const SUPPORT_DAILY_LIMIT = 500;

    public function __construct(
        private readonly AiCreditPurchaseService $purchaseService,
        private readonly AiCreditWalletService $walletService,
        private readonly AiProviderCostService $providerCostService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasAccess);

        $filters = $request->validate([
            'status'    => ['nullable', 'string', Rule::in(array_map(fn (AiCreditPurchaseStatus $s) => $s->value, AiCreditPurchaseStatus::cases()))],
            'provider'  => ['nullable', 'string', Rule::in(array_map(fn (AiProvider $p) => $p->value, AiProvider::cases()))],
            'entity_id' => ['nullable', 'string', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d'],
            'q'         => ['nullable', 'string', 'max:120'],
        ]);

        $query = AiCreditPurchase::query()
            ->with(['entity:id,name,is_client', 'requestedBy:id,name,email'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $v) . '%';
                $q->whereHas('entity', fn ($qe) => $qe->where('name', 'ilike', $like));
            })
            ->orderByDesc('created_at');

        $purchases = $query->paginate(20)->withQueryString();

        $internalEntity = $this->internalEntity();

        return Inertia::render('Panel/Manager/AiCreditPurchases/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('manager.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.ai_credit_purchases'), 'url' => '#', 'active' => true],
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
            'kpis'                  => $this->kpis(),
            'providerCosts'         => $this->providerCostService->getCostDashboard(),
            'consumptionByProvider' => $this->consumptionByProviderLast30Days(),
            'internalWallet'        => $internalEntity
                ? $this->presentInternalWallet($internalEntity)
                : null,
            'topConsumers'  => $this->topConsumersLast30Days(),
            'filters'       => $filters,
            'statusOptions' => collect(AiCreditPurchaseStatus::cases())->map(fn (AiCreditPurchaseStatus $s) => [
                'value' => $s->value,
                'label' => __("ai.credit_purchase_status.{$s->value}"),
            ])->values(),
            'providerOptions' => collect(AiProvider::cases())->map(fn (AiProvider $p) => [
                'value' => $p->value,
                'label' => __("ai.providers.{$p->value}"),
            ])->values(),
            'entities' => Entity::query()
                ->where(function ($q) {
                    $q->where('is_client', true)->orWhere('is_client', false);
                })
                ->orderByDesc('is_client') // clientes primeiro, internas depois
                ->orderBy('name')
                ->get(['id', 'name', 'is_client'])
                ->map(fn (Entity $e) => [
                    'id'        => (string) $e->id,
                    'name'      => $e->name,
                    'is_client' => (bool) $e->is_client,
                ]),
            'permissions' => [
                'credit'                     => Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity()),
                'cancel'                     => Gate::allows(EntityGate::SaasSupport->value, $this->currentSaasEntity()),
                'fail'                       => Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity()),
                'refund'                     => Gate::allows(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity()),
                'create_manual'              => Gate::allows(EntityGate::SaasSupport->value, $this->currentSaasEntity()),
                'create_manual_unlimited'    => Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity()),
                'create_manual_for_internal' => Gate::allows(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity()),
                'create_topup'               => Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity()),
                'delete_topup'               => Gate::allows(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity()),
                'support_daily_limit'        => self::SUPPORT_DAILY_LIMIT,
            ],
            't' => trans('manager_ai_credit_purchases'),
        ]);
    }

    public function show(AiCreditPurchase $purchase): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasAccess);

        $purchase->load(['entity:id,name,is_client', 'requestedBy:id,name,email', 'subscription.plan']);

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
                'provider'     => $purchase->provider instanceof AiProvider ? $purchase->provider->value : (string) $purchase->provider,
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
            'reason'                       => ['required', 'string', 'min:5', 'max:500'],
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
                'provider'        => $purchase->provider instanceof AiProvider ? $purchase->provider->value : (string) $purchase->provider,
            ],
            request: $request,
        );

        return response()->json([
            'message'      => __('manager_ai_credit_purchases.actions.refunded'),
            'purchase'     => $this->serializeRow($purchase->refresh()),
            'wallet_after' => $this->walletService->balance((string) $purchase->entity_id),
        ]);
    }

    /**
     * Cria uma compra manual de créditos (cortesia/uso interno) e credita
     * imediatamente no provedor escolhido.
     *
     * RBAC:
     *   - Admin SaaS: pode creditar para qualquer entity (incluindo interna), sem limite.
     *   - Financial : pode creditar para clientes, sem limite. NÃO pode operar na entity interna.
     *   - Support   : pode creditar para clientes, limitado a SUPPORT_DAILY_LIMIT créditos/dia.
     */
    public function createManual(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasSupport);

        $validated = $request->validate([
            'entity_id' => ['required', 'string', 'uuid', 'exists:entities,id'],
            'kind'      => ['nullable', 'string', Rule::in(['courtesy', 'purchase'])],
            'credits'   => ['required', 'integer', 'min:1', 'max:1000000'],
            // Valor da compra: aceitamos Reais (UI) e convertemos para centavos.
            // `amount_cents` segue aceito por compatibilidade com chamadas antigas.
            'amount_reais' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'amount_cents' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'reason'       => ['required', 'string', 'min:10', 'max:500'],
            'package_code' => ['nullable', 'string', 'max:50'],
        ]);

        $entity  = Entity::findOrFail($validated['entity_id']);
        $credits = (int) $validated['credits'];

        // Cortesia × Compra: a distinção de domínio é amount_cents == 0 (cortesia)
        // vs > 0 (compra paga fora do app). O package_code documenta a operação.
        $isCourtesy = ($validated['kind'] ?? null) === 'courtesy'
            || ($validated['package_code'] ?? null) === 'courtesy';
        $packageCode = $isCourtesy ? 'courtesy' : ($validated['package_code'] ?? 'manual');

        $amountCents = $isCourtesy
            ? 0
            : (isset($validated['amount_reais'])
                ? (int) round(((float) $validated['amount_reais']) * 100)
                : (int) ($validated['amount_cents'] ?? 0));

        // Bloqueia Financial e Support de creditar na entity interna (só Admin)
        if (! $entity->is_client && ! Gate::allows(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity())) {
            return response()->json([
                'message' => __('manager_ai_credit_purchases.errors.manual_internal_admin_only'),
            ], 403);
        }

        // Limite diário para Support
        $isFinancialOrAdmin = Gate::allows(EntityGate::SaasFinancial->value, $this->currentSaasEntity());

        if (! $isFinancialOrAdmin) {
            $usedToday = $this->supportDailyUsage((string) auth()->id());

            if ($usedToday + $credits > self::SUPPORT_DAILY_LIMIT) {
                return response()->json([
                    'message' => __('manager_ai_credit_purchases.errors.support_daily_limit_exceeded', [
                        'limit' => self::SUPPORT_DAILY_LIMIT,
                        'used'  => $usedToday,
                    ]),
                ], 422);
            }
        }

        try {
            $purchase = $this->purchaseService->createManualPurchase(
                entityId: (string) $entity->id,
                credits: $credits,
                reason: (string) $validated['reason'],
                createdBy: (string) auth()->id(),
                provider: null, // carteira tem saldo único — provedor não roteia crédito
                amountCents: $amountCents,
                packageCode: $packageCode,
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => __("manager_ai_credit_purchases.errors.{$e->getMessage()}", []) ?: $e->getMessage(),
            ], 422);
        }

        $this->audit->recordAdminAction(
            event: 'ai_credit_purchase.manual_created',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'ai_credit_purchase',
            auditableId: (string) $purchase->id,
            reason: (string) $validated['reason'],
            newValues: [
                'credits'          => $credits,
                'amount_cents'     => $amountCents,
                'kind'             => $isCourtesy ? 'courtesy' : 'purchase',
                'package_code'     => $packageCode,
                'is_internal'      => ! $entity->is_client,
                'used_daily_quota' => ! $isFinancialOrAdmin,
            ],
            request: $request,
        );

        return response()->json([
            'message'      => __('manager_ai_credit_purchases.actions.manual_created'),
            'purchase'     => $this->serializeRow($purchase->fresh()),
            'wallet_after' => $this->walletService->balance((string) $entity->id),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Topups dos provedores (lado supplier)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Registra uma recarga manual que o time fez no painel do provedor.
     * Permitido para Admin e Financial.
     */
    public function storeTopup(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasFinancial);

        $validated = $request->validate([
            'provider'     => ['required', 'string', Rule::in(array_map(fn (AiProvider $p) => $p->value, AiProvider::cases()))],
            'amount_usd'   => ['required', 'numeric', 'min:0.0001', 'max:1000000'],
            'amount_brl'   => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'topped_up_at' => ['required', 'date'],
            'reference'    => ['nullable', 'string', 'max:120'],
            'note'         => ['nullable', 'string', 'max:500'],
        ]);

        // Cotação efetiva (R$ por US$) = valor pago / valor creditado pelo provedor.
        $exchangeRate = (float) $validated['amount_usd'] > 0
            ? round((float) $validated['amount_brl'] / (float) $validated['amount_usd'], 6)
            : null;

        $topup = AiProviderTopup::query()->create([
            'provider'      => $validated['provider'],
            'amount_usd'    => $validated['amount_usd'],
            'amount_brl'    => $validated['amount_brl'],
            'exchange_rate' => $exchangeRate,
            'topped_up_at'  => $validated['topped_up_at'],
            'reference'     => $validated['reference'] ?? null,
            'note'          => $validated['note'] ?? null,
            'created_by'    => auth()->id(),
        ]);

        $this->audit->recordAdminAction(
            event: 'ai_provider_topup.created',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'ai_provider_topup',
            auditableId: (string) $topup->id,
            reason: 'Registro manual de recarga de provedor de IA',
            newValues: [
                'provider'     => $validated['provider'],
                'amount_usd'   => (float) $validated['amount_usd'],
                'topped_up_at' => $validated['topped_up_at'],
                'reference'    => $validated['reference'] ?? null,
            ],
            request: $request,
        );

        return response()->json([
            'message'        => __('manager_ai_credit_purchases.actions.topup_created'),
            'topup_id'       => (string) $topup->id,
            'provider_costs' => $this->providerCostService->getCostDashboard(),
        ]);
    }

    /**
     * Remove um topup registrado (correção de lançamento errado).
     * Apenas Admin pode excluir — operação altera saldo estimado calculado.
     */
    public function deleteTopup(Request $request, AiProviderTopup $topup): JsonResponse
    {
        $this->authorizeSaasEntity(EntityGate::SaasAdminPanel);

        $snapshot = [
            'provider'     => $topup->provider instanceof AiProvider ? $topup->provider->value : (string) $topup->provider,
            'amount_usd'   => (float) $topup->amount_usd,
            'topped_up_at' => $topup->topped_up_at?->toAtomString(),
            'reference'    => $topup->reference,
        ];

        $topupId = (string) $topup->id;
        $topup->delete();

        $this->audit->recordAdminAction(
            event: 'ai_provider_topup.deleted',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'ai_provider_topup',
            auditableId: $topupId,
            reason: 'Exclusão de registro de recarga (correção)',
            oldValues: $snapshot,
            request: $request,
        );

        return response()->json([
            'message'        => __('manager_ai_credit_purchases.actions.topup_deleted'),
            'provider_costs' => $this->providerCostService->getCostDashboard(),
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
     * Retorna a entity interna do SaaS (is_client=false ATIVA).
     * Se houver múltiplas, pega a mais antiga (provavelmente a entity-mãe original).
     */
    private function internalEntity(): ?Entity
    {
        return Entity::query()
            ->where('is_client', false)
            ->where('active', true)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentInternalWallet(Entity $entity): array
    {
        $balance = $this->walletService->balance((string) $entity->id);

        return [
            'entity_id'   => (string) $entity->id,
            'entity_name' => $entity->name,
            'balance'     => $balance,
        ];
    }

    /**
     * Total de créditos manuais (cortesia + compra avulsa) que o usuário Support
     * lançou hoje. Conta ambos os package_codes para o limite diário não ser
     * burlado lançando tudo como cortesia.
     */
    private function supportDailyUsage(string $userId): int
    {
        $startOfDay = CarbonImmutable::now()->startOfDay();

        return (int) AiCreditPurchase::query()
            ->where('requested_by', $userId)
            ->where('created_at', '>=', $startOfDay)
            ->whereIn('package_code', ['manual', 'courtesy'])
            ->sum('credits');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(AiCreditPurchase $p): array
    {
        $status = $p->status instanceof AiCreditPurchaseStatus
            ? $p->status
            : AiCreditPurchaseStatus::tryFrom((string) $p->status);

        $provider = $p->provider instanceof AiProvider
            ? $p->provider
            : ($p->provider ? AiProvider::tryFrom((string) $p->provider) : null);

        $isInternal = $p->entity && ! $p->entity->is_client;
        $kind       = $this->purchaseKind($p);

        return [
            'id'               => (string) $p->id,
            'entity_id'        => (string) $p->entity_id,
            'entity_name'      => $p->entity?->name,
            'is_internal'      => $isInternal,
            'package_code'     => (string) $p->package_code,
            'package_name'     => $this->packageName((string) $p->package_code),
            'kind'             => $kind,
            'kind_label'       => __("manager_ai_credit_purchases.kind.{$kind}"),
            'provider'         => $provider?->value,
            'provider_label'   => $provider ? __("ai.providers.{$provider->value}") : null,
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
     * KPIs focados nos dois trabalhos reais do dono do SaaS:
     *   - distribuição de créditos às clínicas no mês (cortesia × compra paga);
     *   - pedidos de clientes ainda pendentes (aviso discreto, raramente usado).
     *
     * @return array<string, mixed>
     */
    private function kpis(): array
    {
        $startOfMonth = CarbonImmutable::now()->startOfMonth();

        // Distribuição creditada no mês, separando cortesia (R$ 0) de compra paga.
        $distributed = AiCreditPurchase::query()
            ->where('status', AiCreditPurchaseStatus::Credited)
            ->where('credited_at', '>=', $startOfMonth)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(credits), 0) as credits')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as amount_cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_cents = 0 THEN credits ELSE 0 END), 0) as courtesy_credits')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_cents > 0 THEN credits ELSE 0 END), 0) as paid_credits')
            ->first();

        $pending = AiCreditPurchase::query()
            ->where('status', AiCreditPurchaseStatus::PendingPayment)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount_cents), 0) as sum_cents')
            ->first();

        return [
            'distributed_mtd' => [
                'count'            => (int) ($distributed->cnt ?? 0),
                'credits'          => (int) ($distributed->credits ?? 0),
                'courtesy_credits' => (int) ($distributed->courtesy_credits ?? 0),
                'paid_credits'     => (int) ($distributed->paid_credits ?? 0),
                'amount_cents'     => (int) ($distributed->amount_cents ?? 0),
                'amount_formatted' => $this->formatMoney((int) ($distributed->amount_cents ?? 0), 'BRL'),
            ],
            'pending' => [
                'count'            => (int) ($pending->cnt ?? 0),
                'amount_cents'     => (int) ($pending->sum_cents ?? 0),
                'amount_formatted' => $this->formatMoney((int) ($pending->sum_cents ?? 0), 'BRL'),
            ],
        ];
    }

    /**
     * Consumo de créditos por provedor nos últimos 30 dias (agregado em todas as entities).
     *
     * @return array<string, array{credits:int,percent:float,label:string}>
     */
    private function consumptionByProviderLast30Days(): array
    {
        $thirtyDaysAgo = CarbonImmutable::now()->subDays(30);

        $rows = DB::table('ai_credit_ledger_entries')
            ->whereIn('type', ['consume'])
            ->whereNotNull('provider')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->select('provider')
            ->selectRaw("COALESCE(SUM((metadata->>'consumed_from_reservation')::int), 0) as credits_consumed")
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');

        $total  = (int) $rows->sum('credits_consumed');
        $result = [];

        foreach (AiProvider::cases() as $provider) {
            $credits                  = (int) ($rows[$provider->value]->credits_consumed ?? 0);
            $result[$provider->value] = [
                'credits' => $credits,
                'percent' => $total > 0 ? round($credits / $total * 100, 1) : 0.0,
                'label'   => __("ai.providers.{$provider->value}"),
            ];
        }

        return $result;
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
                'entities.is_client',
                DB::raw('SUM(ai_credit_purchases.credits) as credits_total'),
                DB::raw('SUM(ai_credit_purchases.amount_cents) as amount_total'),
                DB::raw('COUNT(*) as purchases_total'),
            ])
            ->groupBy('ai_credit_purchases.entity_id', 'entities.name', 'entities.is_client')
            ->orderByDesc('credits_total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'entity_id'        => (string) $row->entity_id,
                'entity_name'      => $row->entity_name,
                'is_internal'      => ! (bool) $row->is_client,
                'credits_total'    => (int) $row->credits_total,
                'amount_total'     => (int) $row->amount_total,
                'amount_formatted' => $this->formatMoney((int) $row->amount_total, 'BRL'),
                'purchases_total'  => (int) $row->purchases_total,
            ])
            ->all();
    }

    /**
     * Rótulo legível do pacote. Lançamentos manuais (manual/courtesy) usam os
     * rótulos da própria tela; pacotes de catálogo usam ai.credit_packages.
     */
    private function packageName(string $code): string
    {
        return match ($code) {
            'manual'   => __('manager_ai_credit_purchases.kind.purchase'),
            'courtesy' => __('manager_ai_credit_purchases.kind.courtesy'),
            default    => __("ai.credit_packages.{$code}.name"),
        };
    }

    /**
     * Classifica a linha: cortesia (grátis), compra avulsa (admin lançou paga)
     * ou pedido de cliente (checkout no app). Distinção de domínio: amount_cents.
     */
    private function purchaseKind(AiCreditPurchase $p): string
    {
        $metadata = is_array($p->metadata) ? $p->metadata : (array) $p->metadata;
        $source   = $metadata['source'] ?? null;
        $isManual = in_array((string) $p->package_code, ['manual', 'courtesy'], true)
            || $source === 'manager_manual';

        return match (true) {
            $isManual && (int) $p->amount_cents === 0 => 'courtesy',
            $isManual                                 => 'purchase',
            default                                   => 'client',
        };
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
