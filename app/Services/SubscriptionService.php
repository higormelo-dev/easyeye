<?php

namespace App\Services;

use App\Enums\{BillingCycle, SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\SubscriptionCycleService;
use DateInterval;
use Illuminate\Http\Request;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /** Atributo do request com a assinatura de acesso de cada empresa (currentAccessFor). */
    private const REQUEST_MEMO = 'subscription.current_access';

    public function __construct(
        private readonly SubscriptionCycleService $cycles,
    ) {
    }

    /**
     * Ativa uma assinatura paga para a empresa.
     * Cancela qualquer assinatura anterior (trial ou paga).
     */
    public function activate(Entity $entity, Plan $plan, BillingCycle $cycle = BillingCycle::Monthly): Subscription
    {
        $this->cancelCurrent($entity);

        return Subscription::create([
            'entity_id' => $entity->id,
            'plan_id'   => $plan->id,
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at'   => $cycle->addToNow(),
        ]);
    }

    /**
     * Cancela a assinatura ativa da empresa. O acesso acaba na hora (não há
     * período de graça).
     */
    public function cancel(Entity $entity): ?Subscription
    {
        $subscription = $this->getCurrent($entity);

        if (! $subscription) {
            return null;
        }

        $subscription->update([
            'status'       => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $subscription->fresh();
    }

    /**
     * Renova a assinatura ativa por mais um ciclo.
     */
    public function renew(Subscription $subscription): Subscription
    {
        $cycle = $subscription->plan->billing_cycle;

        $newEndsAt = $cycle === BillingCycle::Lifetime
            ? null
            : ($subscription->ends_at ?? now())->add(
                match ($cycle) {
                    BillingCycle::Monthly => new DateInterval('P1M'),
                    BillingCycle::Yearly  => new DateInterval('P1Y'),
                    default               => new DateInterval('P1M'),
                },
            );

        $subscription->update([
            'status'  => SubscriptionStatus::Active,
            'ends_at' => $newEndsAt,
        ]);

        return $subscription->fresh();
    }

    /**
     * Marca como expiradas as assinaturas vencidas que não são cobradas pelo
     * gateway — cortesia e liberações antigas (scheduler). O acesso já
     * terminou em `ends_at` — não há período de graça. Cobrança automática
     * não expira aqui: ver markLapsedAsPastDue().
     */
    public function expireOverdue(): int
    {
        $count = 0;

        // Iterate individually so Eloquent observers fire for each record.
        Subscription::where('status', SubscriptionStatus::Active)
            ->where(fn ($q) => $q->whereNull('billing_mode')->orWhere('billing_mode', '!=', SubscriptionBillingMode::Gateway->value))
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->each(function (Subscription $subscription) use (&$count): void {
                $subscription->update(['status' => SubscriptionStatus::Expired]);

                $count++;
            });

        return $count;
    }

    /**
     * Cobrança automática ativa com o período pago vencido e sem o pagamento
     * da renovação: entra em atraso desde o fim do período (past_due_at =
     * ends_at). Daí em diante quem decide o acesso e o encerramento é a
     * régua de cobrança — o gateway pode estar só compensando o pagamento.
     * Linha do código anterior aguardando conciliação fica de fora: o ends_at
     * dela não é o fim do período pago. Só a vigente da empresa: linha antiga
     * (substituída) não entra em atraso nem na régua.
     */
    public function markLapsedAsPastDue(): int
    {
        $count = 0;

        Subscription::query()
            ->latestPerEntity()
            ->where('status', SubscriptionStatus::Active)
            ->where('billing_mode', SubscriptionBillingMode::Gateway->value)
            ->where('needs_billing_reconciliation', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->pluck('id')
            ->each(function (string $id) use (&$count): void {
                $changed = DB::transaction(function () use ($id): bool {
                    $subscription = Subscription::query()->whereKey($id)->lockForUpdate()->first();

                    // Pago entre a consulta e a trava: nada a fazer.
                    if (! $subscription
                        || $subscription->status !== SubscriptionStatus::Active
                        || $subscription->needs_billing_reconciliation
                        || $subscription->ends_at === null
                        || $subscription->ends_at->isFuture()
                        || ! $subscription->isCurrentOfEntity()) {
                        return false;
                    }

                    $this->cycles->markPastDue(
                        subscription: $subscription,
                        since: $subscription->ends_at,
                        billingState: 'past_due',
                        error: __('manager_subscriptions.billing_errors.period_lapsed'),
                        correlationId: (string) Str::uuid(),
                        source: 'scheduler',
                    );

                    return true;
                });

                $count += (int) $changed;
            });

        return $count;
    }

    /**
     * Assinatura vigente da empresa — trial, ativa ou em atraso, com ou sem
     * acesso no momento (ex.: o manager cancela uma em atraso).
     */
    public function currentInForce(Entity $entity): ?Subscription
    {
        return Subscription::forEntity($entity->id)
            ->inForce()
            ->currentFirst()
            ->first();
    }

    /**
     * O que o "Cancelar" do manager encerra na empresa: as vigentes (trial,
     * ativa ou em atraso) e as linhas do código anterior aguardando
     * conciliação em qualquer situação não final — inclusive a expirada que
     * o gateway segue cobrando. A mais recente primeiro.
     *
     * @return Collection<int, Subscription>
     */
    public function cancellableOf(Entity $entity): Collection
    {
        return Subscription::forEntity((string) $entity->id)
            ->where(fn ($q) => $q->inForce()->orWhere(fn ($flagged) => $flagged->needingBillingReconciliation()))
            ->currentFirst()
            ->get();
    }

    /**
     * Retorna a assinatura acessível atual (trial ou ativa) da empresa.
     */
    public function getCurrent(Entity $entity): ?Subscription
    {
        return Subscription::forEntity($entity->id)
            ->accessible()
            ->currentFirst()
            ->with('plan.features')
            ->first();
    }

    /**
     * Verifica se a empresa tem acesso ao sistema (total ou limitado).
     */
    public function hasAccess(Entity $entity): bool
    {
        return Subscription::forEntity($entity->id)
            ->accessible()
            ->exists();
    }

    /**
     * Assinatura que hoje dá o melhor acesso à empresa (total antes de
     * limitado; no empate, a mais recente). Null sem acesso.
     */
    public function currentAccess(Entity|string $entity): ?Subscription
    {
        return Subscription::bestAccessibleFor($entity instanceof Entity ? (string) $entity->id : $entity);
    }

    /**
     * currentAccess() guardada no próprio request (com o plano e os recursos
     * dele): o CheckSubscription, o aviso do painel, o plano no cabeçalho e o
     * gate de recursos (FeatureGateService) usam uma consulta só. Vive só
     * enquanto o request existe — nada passa de um request para outro.
     */
    public function currentAccessFor(Request $request, Entity|string $entity): ?Subscription
    {
        $entityId = $entity instanceof Entity ? (string) $entity->id : $entity;
        $memo     = $request->attributes->get(self::REQUEST_MEMO, []);

        if (! array_key_exists($entityId, $memo)) {
            $memo[$entityId] = Subscription::bestAccessibleFor($entityId, ['plan.features']);
            $request->attributes->set(self::REQUEST_MEMO, $memo);

            app(FeatureGateService::class)->rememberSubscription($entityId, $memo[$entityId]);
        }

        return $memo[$entityId];
    }

    /**
     * Melhor nível de acesso da empresa entre as assinaturas dela: total,
     * limitado (cliente pagante em atraso na régua) ou nenhum.
     */
    public function accessLevel(Entity|string $entity): SubscriptionAccessLevel
    {
        return $this->currentAccess($entity)?->accessLevel() ?? SubscriptionAccessLevel::None;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function cancelCurrent(Entity $entity): void
    {
        // Iterate individually so Eloquent observers (SubscriptionObserver) fire for each record.
        // Mass update via ::update() bypasses observers entirely.
        Subscription::forEntity($entity->id)
            ->whereIn('status', [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])
            ->each(function (Subscription $subscription): void {
                $subscription->update([
                    'status'       => SubscriptionStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);
            });
    }
}
