<?php

namespace App\Services\Billing;

use App\Domains\AI\Services\AiCreditWalletService;
use App\DTOs\Billing\SubscriptionTerms;
use App\Enums\Billing\SubscriptionCancelledReason;
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\SubscriptionChange;
use App\Models\{Entity, Plan, Subscription};
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;

/**
 * Gestão da assinatura de uma empresa pelo manager, sem passar pelo gateway:
 * trial, cortesia, adicionar período e alterar condições. A cobrança
 * automática (única receita) continua no BillingSubscriptionOrchestrator.
 *
 * Cada operação grava um SubscriptionChange (antes → depois, motivo e quem
 * fez) — é o histórico de períodos que o manager consulta. As chamadas ao
 * gateway (cancelar a cobrança automática) ficam fora da transação, como no
 * restante do billing.
 */
class SubscriptionManagementService
{
    public const EXTENSION_UNITS = ['days', 'months', 'years'];

    public function __construct(
        private readonly BillingCancellationService $cancellation,
        private readonly AiCreditWalletService $aiWallet,
    ) {
    }

    /**
     * Nova cortesia (com término). Substitui a vigente (trial, ativa ou em
     * atraso).
     */
    public function create(Entity $entity, Plan $plan, SubscriptionTerms $terms, string $reason): SubscriptionChange
    {
        return $this->replaceWith($entity, $plan, $reason, fn () => [
            'status' => SubscriptionStatus::Active,
            ...$terms->attributes(),
        ]);
    }

    /**
     * Trial manual. Guarda o ciclo que o cliente pretende contratar (e o
     * valor dele hoje) para a ativação já sugerir a mesma modalidade.
     */
    public function startTrial(Entity $entity, Plan $plan, int $days, ?BillingCycle $cycle, ?string $reason): SubscriptionChange
    {
        $cycle = $cycle && $plan->offersCycle($cycle) ? $cycle : $plan->defaultCycle();

        return $this->replaceWith($entity, $plan, $reason, fn () => [
            'status'        => SubscriptionStatus::Trial,
            'billing_mode'  => null,
            'billing_cycle' => $cycle,
            'amount'        => $cycle ? $plan->priceFor($cycle) : null,
            'starts_at'     => now(),
            'trial_ends_at' => now()->addDays($days),
            'ends_at'       => null,
        ]);
    }

    /**
     * Adiciona dias/meses/anos ao fim do período (estilo "renovar"): soma ao
     * término atual se ele ainda está no futuro; se já venceu, conta a
     * partir de hoje.
     *
     * - Trial: estende o trial (um trial expirado volta a valer).
     * - Cortesia ativa com término: estende o término.
     * - Cortesia expirada: reativa a partir de hoje.
     *
     * Cobrança automática não recebe período: ele acompanha os pagamentos
     * (o pagamento seguinte absorveria os dias dados). Para dias grátis, a
     * modalidade precisa virar cortesia em "Alterar assinatura".
     */
    public function extend(Subscription $subscription, string $unit, int $quantity, string $reason): SubscriptionChange
    {
        return DB::transaction(function () use ($subscription, $unit, $quantity, $reason): SubscriptionChange {
            $subscription = $this->lockCurrent($subscription);
            $previous     = $this->snapshot($subscription);

            $isTrial        = $subscription->status === SubscriptionStatus::Trial;
            $isExpiredTrial = $subscription->status === SubscriptionStatus::Expired
                && $subscription->billing_mode === null
                && $subscription->trial_ends_at !== null
                && $subscription->ends_at === null;
            $isReactivation = $subscription->status === SubscriptionStatus::Expired
                && $subscription->billing_mode === SubscriptionBillingMode::Complimentary;

            if ($isTrial || $isExpiredTrial) {
                $subscription->update([
                    'status'        => SubscriptionStatus::Trial,
                    'trial_ends_at' => self::extendedEnd($subscription->trial_ends_at, $unit, $quantity),
                ]);
            } elseif ($subscription->billing_mode === SubscriptionBillingMode::Gateway) {
                // Na cobrança automática o período acompanha os pagamentos: o
                // próximo pagamento estende a partir do vencimento dele e
                // absorveria o período dado. Dias grátis = converter em cortesia.
                throw ValidationException::withMessages([
                    'subscription' => __('manager_subscriptions.errors.extend_gateway'),
                ]);
            } elseif ($subscription->status === SubscriptionStatus::Active || $isReactivation) {
                // Assinatura antiga liberada sem término: define-se o término em
                // "Alterar assinatura", não somando período.
                if ($subscription->status === SubscriptionStatus::Active && $subscription->ends_at === null) {
                    throw ValidationException::withMessages([
                        'subscription' => __('manager_subscriptions.errors.no_end'),
                    ]);
                }

                $subscription->update([
                    'status'  => SubscriptionStatus::Active,
                    'ends_at' => self::extendedEnd($subscription->ends_at, $unit, $quantity),
                ]);
            } else {
                throw ValidationException::withMessages([
                    'subscription' => __('manager_subscriptions.errors.not_extendable'),
                ]);
            }

            return $this->record(
                subscription: $subscription,
                type: 'period_extended',
                reason: $reason,
                previous: $previous,
                extra: ['extension' => ['unit' => $unit, 'quantity' => $quantity]],
            );
        });
    }

    /**
     * Altera plano e período da assinatura vigente, que passa a ser cortesia.
     * Se ela era cobrada pelo gateway, a cobrança automática é cancelada lá
     * depois do commit.
     */
    public function updateTerms(Subscription $subscription, Plan $plan, SubscriptionTerms $terms, string $reason): SubscriptionChange
    {
        $correlationId = (string) Str::uuid();

        [$change, $stopRecurrence] = DB::transaction(function () use ($subscription, $plan, $terms, $reason, $correlationId): array {
            $subscription = $this->lockCurrent($subscription);

            if ($subscription->status === SubscriptionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'subscription' => __('manager_subscriptions.errors.cancelled_use_new'),
                ]);
            }

            $previous       = $this->snapshot($subscription);
            $previousPlanId = $subscription->plan_id;
            $stopRecurrence = $subscription->hasGatewayRecurrence() ? $subscription : null;

            $subscription->update([
                'plan_id' => $plan->id,
                'status'  => SubscriptionStatus::Active,
                ...$terms->attributes(),
                // Virou cortesia com término definido pelo manager: a
                // conciliação da cobrança antiga (se havia) acabou aqui.
                'needs_billing_reconciliation' => false,
                'billing_state'                => null,
                'last_billing_error'           => null,
                'correlation_id'               => $correlationId,
            ]);

            // Cortesia não tem franquia de IA: a cota mensal que restava da
            // cobrança automática acaba aqui (saldo comprado e créditos de
            // cortesia do manager ficam).
            $this->aiWallet->forfeitMonthlyQuota(
                entityId: (string) $subscription->entity_id,
                subscriptionId: (string) $subscription->id,
                reason: 'became_complimentary',
                createdBy: Auth::id() !== null ? (string) Auth::id() : null,
            );

            $change = $this->record(
                subscription: $subscription->setRelation('plan', $plan),
                type: 'terms_changed',
                reason: $reason,
                previous: $previous,
                previousPlanId: $previousPlanId,
                correlationId: $correlationId,
                extra: ['gateway_recurrence_cancelled' => $stopRecurrence !== null],
            );

            return [$change, $stopRecurrence];
        });

        if ($stopRecurrence) {
            $this->cancellation->cancelGatewayRecurrence($stopRecurrence, $correlationId);
        } else {
            // Virou cortesia sem recorrência no gateway: checkout de cartão
            // aberto/pago da cobrança automática deixa de valer.
            $this->cancellation->supersedeHostedCheckouts($subscription, 'became_complimentary', $correlationId);
        }

        return $change;
    }

    /**
     * Novo término ao somar o período: a partir do término atual se ele está
     * no futuro, senão a partir de agora. Meses/anos não "transbordam"
     * (31/01 + 1 mês = 28/02).
     */
    public static function extendedEnd(?CarbonInterface $currentEnd, string $unit, int $quantity, ?CarbonInterface $now = null): CarbonImmutable
    {
        $now  = CarbonImmutable::instance($now ?? now());
        $base = $currentEnd && $currentEnd->greaterThan($now) ? CarbonImmutable::instance($currentEnd) : $now;

        return match ($unit) {
            'days'   => $base->addDays($quantity),
            'months' => $base->addMonthsNoOverflow($quantity),
            'years'  => $base->addYearsNoOverflow($quantity),
        };
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Cria a nova assinatura da empresa no lugar da vigente, numa transação
     * com a empresa travada (duas criações simultâneas não deixam duas
     * vigentes). A cobrança automática das substituídas é cancelada depois.
     *
     * @param callable(): array<string, mixed> $attributes
     */
    private function replaceWith(Entity $entity, Plan $plan, ?string $reason, callable $attributes): SubscriptionChange
    {
        $correlationId = (string) Str::uuid();

        [$change, $replaced] = DB::transaction(function () use ($entity, $plan, $reason, $attributes, $correlationId): array {
            Entity::query()->whereKey($entity->id)->lockForUpdate()->first();

            $current  = $this->currentOf($entity);
            $previous = $current->first() ? $this->snapshot($current->first()) : null;
            $replaced = $this->cancel($current, $correlationId);

            $subscription = Subscription::create([
                'entity_id' => $entity->id,
                'plan_id'   => $plan->id,
                ...$attributes(),
                'correlation_id' => $correlationId,
            ])->setRelation('plan', $plan);

            // Assinatura antiga (inclusive expirada) aguardando conciliação
            // deixa de aguardar: a nova é a que vale, e a recorrência dela
            // para no gateway junto com a das substituídas.
            $cleared = Subscription::clearReconciliationOfOthers($subscription);

            // Trial e cortesia não têm franquia de IA: a cota mensal que
            // restava da assinatura substituída acaba aqui.
            if (! $subscription->isBillable()) {
                $this->aiWallet->forfeitMonthlyQuota(
                    entityId: (string) $entity->id,
                    subscriptionId: (string) $subscription->id,
                    reason: $subscription->status === SubscriptionStatus::Trial ? 'replaced_by_trial' : 'replaced_by_complimentary',
                    createdBy: Auth::id() !== null ? (string) Auth::id() : null,
                );
            }

            $change = $this->record(
                subscription: $subscription,
                type: 'subscription_created',
                reason: $reason,
                previous: $previous,
                previousPlanId: $previous['plan_id'] ?? null,
                correlationId: $correlationId,
                extra: [
                    'replaced_subscription_ids' => $replaced->pluck('id')->all(),
                    // Linhas do código anterior que deixaram de aguardar conciliação
                    // (a recorrência delas para no gateway depois do commit).
                    'legacy_review_closed_ids' => $cleared->pluck('id')->all(),
                ],
            );

            return [$change, $replaced->merge($cleared)->unique('id')->values()];
        });

        // Recorrência no gateway ou, na renovação local, a cobrança avulsa em
        // aberto das substituídas: param depois do commit.
        $replaced->each(function (Subscription $old) use ($correlationId): void {
            if ($old->hasGatewayRecurrence()) {
                $this->cancellation->cancelGatewayRecurrence($old, $correlationId);
            } elseif (filled($old->gateway)) {
                $this->cancellation->supersedeHostedCheckouts($old, 'subscription_replaced', $correlationId);
                $this->cancellation->cancelOpenCharges($old, correlationId: $correlationId);
            }
        });

        return $change;
    }

    /**
     * Assinaturas vigentes da empresa (trial, ativa ou em atraso), travadas,
     * da mais recente para a mais antiga.
     *
     * @return Collection<int, Subscription>
     */
    private function currentOf(Entity $entity): Collection
    {
        return Subscription::query()
            ->forEntity((string) $entity->id)
            ->whereIn('status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->currentFirst()
            ->with('plan')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Encerra as assinaturas substituídas (uma a uma, para observers e
     * auditoria rodarem). Sem período de graça: a nova assume o acesso.
     *
     * @param Collection<int, Subscription> $subscriptions
     *
     * @return Collection<int, Subscription>
     */
    private function cancel(Collection $subscriptions, string $correlationId): Collection
    {
        return $subscriptions->each(function (Subscription $subscription) use ($correlationId): void {
            $subscription->update([
                'status'           => SubscriptionStatus::Cancelled,
                'cancelled_at'     => now(),
                'cancelled_reason' => SubscriptionCancelledReason::Replaced->value,
                'billing_state'    => $subscription->billing_mode === SubscriptionBillingMode::Gateway
                    ? 'cancelled'
                    : $subscription->billing_state,
                'correlation_id' => $correlationId,
            ]);
        });
    }

    /**
     * Trava a assinatura e exige que seja a mais recente da empresa — as
     * anteriores são histórico e não mudam. Tentativa de contratação recusada
     * pelo gateway não conta (a vigente segue sendo a que continua valendo).
     */
    private function lockCurrent(Subscription $subscription): Subscription
    {
        $locked = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

        $latestId = Subscription::query()
            ->forEntity((string) $locked->entity_id)
            ->withoutFailedActivations()
            ->currentFirst()
            ->value('id');

        if ($latestId !== $locked->id) {
            throw ValidationException::withMessages([
                'subscription' => __('manager_subscriptions.errors.not_current'),
            ]);
        }

        return $locked;
    }

    /**
     * Condições da assinatura para o histórico (antes → depois). Também usado
     * na ativação pelo gateway (SubscriptionCycleService). A contratação
     * aguardando o 1º pagamento fica marcada: no histórico ela aparece como
     * "Aguardando 1º pagamento", não como "Em atraso".
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Subscription $subscription): array
    {
        return [
            'plan_id'                => $subscription->plan_id,
            'plan_name'              => $subscription->plan?->name,
            'status'                 => $subscription->status?->value,
            'awaiting_first_payment' => $subscription->isAwaitingFirstPayment(),
            'billing_mode'           => $subscription->billing_mode?->value,
            'billing_cycle'          => $subscription->billing_cycle?->value,
            'amount'                 => $subscription->amount !== null ? (float) $subscription->amount : null,
            'starts_at'              => $subscription->starts_at?->toIso8601String(),
            'ends_at'                => $subscription->ends_at?->toIso8601String(),
            'trial_ends_at'          => $subscription->trial_ends_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>      $extra
     */
    private function record(
        Subscription $subscription,
        string $type,
        ?string $reason,
        ?array $previous,
        ?string $previousPlanId = null,
        ?string $correlationId = null,
        array $extra = [],
    ): SubscriptionChange {
        $reason = filled($reason) ? trim((string) $reason) : null;

        return SubscriptionChange::query()->create([
            'entity_id'        => $subscription->entity_id,
            'subscription_id'  => $subscription->id,
            'previous_plan_id' => $previousPlanId ?? $previous['plan_id'] ?? null,
            'new_plan_id'      => $subscription->plan_id,
            'change_type'      => $type,
            'reason'           => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'changed_by'       => Auth::id(),
            'effective_at'     => now(),
            'correlation_id'   => $correlationId,
            'metadata'         => [
                'source'      => 'manager',
                'reason_text' => $reason,
                'previous'    => $previous,
                'new'         => $this->snapshot($subscription),
                ...$extra,
            ],
        ]);
    }
}
