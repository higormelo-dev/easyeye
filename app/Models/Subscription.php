<?php

namespace App\Models;

use App\Casts\SanitizedGatewayPayload;
use App\Enums\Billing\{DunningStep, InvoiceStatus, PaymentAttemptStatus, SubscriptionCancelledReason};
use App\Enums\{BillingCycle, SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{Cancellation, Invoice, PaymentAttempt, SubscriptionChange, SubscriptionDunningStep};
use App\Support\Billing\DunningSchedule;
use App\Traits\{Auditable, HasAuditColumns};
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\{Carbon, Collection};

class Subscription extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'plan_id',
        'billing_cycle',
        'amount',
        'billing_mode',
        'needs_billing_reconciliation',
        'status',
        'billing_state',
        'last_billing_error',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'next_billing_at',
        'first_charge_grace_ends_at',
        'last_payment_at',
        'past_due_at',
        'cancelled_at',
        'cancelled_reason',
        'renewed_at',
        'gateway',
        'pinned_gateway',
        'gateway_customer_id',
        'gateway_subscription_id',
        'recurrence_alert_at',
        'payment_method',
        'gateway_card_id',
        'card_brand',
        'card_last4',
        'card_installments',
        'idempotency_key',
        'correlation_id',
        'current_invoice_id',
        'gateway_payload',
        'gateway_migration_locked_until',
    ];

    /** Referência do cartão no gateway nunca vai serializada para o front. */
    protected $hidden = ['gateway_card_id'];

    protected function casts(): array
    {
        return [
            'status'                         => SubscriptionStatus::class,
            'billing_cycle'                  => BillingCycle::class,
            'amount'                         => 'decimal:2',
            'billing_mode'                   => SubscriptionBillingMode::class,
            'needs_billing_reconciliation'   => 'boolean',
            'billing_state'                  => 'string',
            'last_billing_error'             => 'string',
            'trial_ends_at'                  => 'datetime',
            'starts_at'                      => 'datetime',
            'ends_at'                        => 'datetime',
            'next_billing_at'                => 'datetime',
            'first_charge_grace_ends_at'     => 'datetime',
            'last_payment_at'                => 'datetime',
            'past_due_at'                    => 'datetime',
            'cancelled_at'                   => 'datetime',
            'renewed_at'                     => 'datetime',
            'gateway'                        => 'string',
            'pinned_gateway'                 => 'string',
            'gateway_customer_id'            => 'string',
            'idempotency_key'                => 'string',
            'gateway_payload'                => SanitizedGatewayPayload::class,
            'gateway_migration_locked_until' => 'datetime',
            'recurrence_alert_at'            => 'datetime',
            'card_installments'              => 'integer',
            'created_at'                     => 'datetime',
            'updated_at'                     => 'datetime',
            'deleted_at'                     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Toda assinatura fora do trial tem modalidade. Sem gateway informado
        // ela foi liberada à mão — é cortesia (não é receita). Trial (em curso
        // ou encerrado) fica sem modalidade.
        static::creating(function (Subscription $subscription): void {
            if ($subscription->billing_mode === null
                && $subscription->status !== SubscriptionStatus::Trial
                && $subscription->trial_ends_at === null) {
                $subscription->billing_mode = filled($subscription->gateway)
                    ? SubscriptionBillingMode::Gateway
                    : SubscriptionBillingMode::Complimentary;
            }
        });
    }

    // ── Relacionamentos ─────────────────────────────────────────────────────

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id')->withTrashed();
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class, 'subscription_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class, 'subscription_id');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(Cancellation::class, 'subscription_id');
    }

    public function currentInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'current_invoice_id');
    }

    /** Etapas da régua de cobrança já registradas (billing:dunning). */
    public function dunningSteps(): HasMany
    {
        return $this->hasMany(SubscriptionDunningStep::class, 'subscription_id');
    }

    // ── Estado da assinatura ─────────────────────────────────────────────────

    /**
     * Assinatura dá acesso ao painel (total ou limitado): trial válido, plano
     * vigente, contratação aguardando o 1º pagamento dentro do vencimento ou
     * cliente pagante em atraso dentro da régua. Mesma regra do
     * scopeAccessible.
     */
    public function isActive(): bool
    {
        return $this->accessLevel()->hasAccess();
    }

    /**
     * Assinatura está no período de trial.
     */
    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trial
            && ($this->trial_ends_at?->isFuture() ?? false);
    }

    /**
     * Empresa tem acesso. Não há período de graça no fim do trial ou da
     * cortesia: acabou, acabou o acesso. Só o cliente pagante em atraso
     * passa pela régua de cobrança (ver accessLevel). A coluna
     * `grace_period_ends_at` ficou como legado e não libera mais nada.
     */
    public function hasAccess(): bool
    {
        return $this->isActive();
    }

    /**
     * Quanto do painel a assinatura libera:
     *
     *  - trial: total até trial_ends_at;
     *  - cortesia/plano vigente: total até ends_at;
     *  - contratação aguardando o 1º pagamento: total até o fim do dia do
     *    vencimento da 1ª cobrança (sem régua);
     *  - cliente pagante em atraso: total com aviso até
     *    `soft_block_after_days`, limitado até `hard_block_after_days`,
     *    depois nenhum;
     *  - expirada/cancelada: nenhum;
     *  - cobrança automática do código anterior aguardando conciliação
     *    (needsBillingReconciliation): total, como antes do deploy — só a
     *    vigente da empresa; a linha antiga já substituída nunca libera
     *    acesso pela marcação.
     */
    public function accessLevel(): SubscriptionAccessLevel
    {
        if ($this->needsBillingReconciliation() && $this->isCurrentOfEntity()) {
            return SubscriptionAccessLevel::Full;
        }

        if ($this->isInDunning()) {
            $days = $this->daysOverdue();

            return match (true) {
                $days === null,
                $days >= DunningSchedule::hardBlockAfterDays() => SubscriptionAccessLevel::None,
                $days >= DunningSchedule::softBlockAfterDays() => SubscriptionAccessLevel::Limited,
                default                                        => SubscriptionAccessLevel::Full,
            };
        }

        $inForce = match ($this->status) {
            SubscriptionStatus::Trial   => $this->trial_ends_at?->isFuture() ?? false,
            SubscriptionStatus::Active  => is_null($this->ends_at) || $this->ends_at->isFuture(),
            SubscriptionStatus::PastDue => $this->firstChargeAccessEndsAt()?->isFuture() ?? false,
            default                     => false,
        };

        return $inForce ? SubscriptionAccessLevel::Full : SubscriptionAccessLevel::None;
    }

    /**
     * Cliente pagante (cobrança automática já paga antes) com cobrança
     * vencida sem pagamento: em atraso, ou ativa com o período pago vencido
     * que a expiração diária ainda não marcou. Daí em diante o acesso segue a
     * régua de cobrança. Mesma regra do ramo de atraso do scopeAccessible.
     */
    public function isInDunning(): bool
    {
        if ($this->billing_mode !== SubscriptionBillingMode::Gateway || ! $this->hasBeenPaid() || $this->needsBillingReconciliation()) {
            return false;
        }

        return $this->status === SubscriptionStatus::PastDue
            || ($this->status === SubscriptionStatus::Active && $this->ends_at !== null && ! $this->ends_at->isFuture());
    }

    /** Desde quando o cliente pagante está em atraso (null fora da régua). */
    public function overdueSince(): ?Carbon
    {
        if (! $this->isInDunning()) {
            return null;
        }

        return $this->status === SubscriptionStatus::PastDue
            ? ($this->past_due_at ?? $this->ends_at)
            : $this->ends_at;
    }

    /** Dias corridos de atraso (0 no dia do vencimento); null fora da régua. */
    public function daysOverdue(): ?int
    {
        $since = $this->overdueSince();

        return $since ? DunningSchedule::daysSince($since) : null;
    }

    /**
     * Vencimento da cobrança em atraso, para mostrar à clínica (e-mail,
     * aviso e tela de bloqueio): o início do atraso ou, se for anterior, o
     * fim do período pago. Na linha do código anterior conciliada em atraso
     * a régua conta da conciliação (past_due_at), mas a cobrança venceu
     * antes (ends_at). Null fora da régua.
     */
    public function unpaidDueDate(): ?Carbon
    {
        $since = $this->overdueSince();

        if ($since === null) {
            return null;
        }

        return $this->ends_at !== null && $this->ends_at->lessThan($since) ? $this->ends_at : $since;
    }

    /**
     * Em que ponto da régua a assinatura está agora (null = fora dela):
     * atraso com aviso, acesso limitado ou bloqueio total (a encerrar); na
     * contratação nunca paga, vencida (sem acesso) ou a encerrar.
     */
    public function dunningStage(): ?DunningStep
    {
        if ($this->needsBillingReconciliation()) {
            return null;
        }

        if ($this->isInDunning()) {
            $days = $this->daysOverdue();

            return match (true) {
                $days === null                                 => null,
                $days >= DunningSchedule::hardBlockAfterDays() => DunningStep::Terminated,
                $days >= DunningSchedule::softBlockAfterDays() => DunningStep::Limited,
                default                                        => DunningStep::Overdue,
            };
        }

        $firstDue = $this->isAwaitingFirstPayment() ? $this->next_billing_at : null;

        if ($firstDue === null || $firstDue->copy()->endOfDay()->isFuture()) {
            return null;
        }

        return DunningSchedule::daysSince($firstDue) >= DunningSchedule::hardBlockAfterDays()
            ? DunningStep::FirstChargeTerminated
            : DunningStep::FirstChargeOverdue;
    }

    /**
     * Cobrança em aberto com link para pagar (página da fatura, boleto ou
     * Pix) — a mais antiga, que é a que está vencida.
     */
    public function payableInvoice(): ?Invoice
    {
        return $this->invoices()
            ->forBillingPeriod()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
            ->whereNotNull('payment_url')
            ->where('payment_url', '!=', '')
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->first();
    }

    // ── Condições contratadas ────────────────────────────────────────────────

    /** Ciclo contratado; linhas anteriores ao registro do ciclo caem no do plano. */
    public function effectiveCycle(): ?BillingCycle
    {
        return $this->billing_cycle ?? $this->plan?->billing_cycle;
    }

    /**
     * Sem data de término fora do trial — só em assinaturas antigas liberadas
     * à mão (vitalício não faz parte da estratégia). O manager define o
     * término em "Alterar assinatura".
     */
    public function isOpenEnded(): bool
    {
        return $this->status !== SubscriptionStatus::Trial && $this->ends_at === null;
    }

    public function isComplimentary(): bool
    {
        return $this->billing_mode === SubscriptionBillingMode::Complimentary;
    }

    /** Gera receita (cobrança automática). Trial e cortesia não. */
    public function isBillable(): bool
    {
        return $this->billing_mode?->isBillable() ?? false;
    }

    /**
     * Tentativa de contratação que o gateway recusou: nunca valeu e nunca é a
     * vigente da empresa (a anterior segue valendo). Mesma regra de
     * scopeWithoutFailedActivations.
     */
    public function isFailedActivation(): bool
    {
        return $this->cancelled_reason === SubscriptionCancelledReason::ActivationFailed->value;
    }

    /**
     * Cobrança automática criada pelo código anterior ainda não conciliada
     * com o gateway (billing:reconcile-legacy): as datas gravadas não são
     * confiáveis. Até a conciliação, acesso total (como antes do deploy) e
     * fora da régua, da expiração automática e da renovação local. Mesma
     * regra de scopeNeedingBillingReconciliation.
     */
    public function needsBillingReconciliation(): bool
    {
        return (bool) $this->needs_billing_reconciliation
            && in_array($this->status, self::reconciliationStatuses(), true);
    }

    /** @return list<SubscriptionStatus> situações em que a marcação vale */
    public static function reconciliationStatuses(): array
    {
        return [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Expired];
    }

    /**
     * A empresa ganhou uma nova assinatura (as anteriores foram substituídas):
     * nenhuma linha antiga dela segue aguardando conciliação. O chamador já
     * travou a empresa e, depois do commit, para no gateway a recorrência das
     * linhas devolvidas (inclusive a expirada que o gateway segue cobrando) —
     * senão o cliente paga as duas. Só as anteriores a ela: o 1º pagamento de
     * uma linha antiga nunca mexe numa mais nova (a vigente).
     *
     * @return Collection<int, self> as que ainda estavam em situação não final
     */
    public static function clearReconciliationOfOthers(self $current): Collection
    {
        $cleared = collect();

        static::query()
            ->forEntity((string) $current->entity_id)
            ->whereKeyNot($current->id)
            ->where('created_at', '<=', $current->created_at)
            ->where('needs_billing_reconciliation', true)
            ->each(function (self $old) use ($cleared): void {
                if ($old->needsBillingReconciliation()) {
                    $cleared->push($old);
                }

                $old->update(['needs_billing_reconciliation' => false]);
            });

        return $cleared;
    }

    /**
     * É a assinatura vigente da empresa: a mais recente (tentativa de
     * contratação recusada pelo gateway não conta). Só a vigente recebe a
     * régua, a expiração e a renovação local, e só ela libera acesso pela
     * marcação de conciliação. Mesma regra de scopeLatestPerEntity.
     */
    public function isCurrentOfEntity(): bool
    {
        return $this->entity_id !== null
            && static::query()
                ->forEntity((string) $this->entity_id)
                ->latestPerEntity()
                ->whereKey($this->id)
                ->exists();
    }

    /** Já teve pagamento confirmado pelo gateway (nulo = nunca pagou). */
    public function hasBeenPaid(): bool
    {
        return $this->last_payment_at !== null;
    }

    /**
     * Recebe a franquia mensal de IA do plano: só a cobrança automática paga
     * (ativa, ou em atraso ainda com acesso total pela régua) e com acesso
     * total. Trial e cortesia não têm franquia (créditos de teste vêm da
     * cortesia de créditos do manager); contratação aguardando o 1º
     * pagamento e acesso limitado/bloqueado também não.
     */
    public function earnsMonthlyAiQuota(): bool
    {
        return $this->isBillable()
            && in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && ! $this->isAwaitingFirstPayment()
            && $this->accessLevel() === SubscriptionAccessLevel::Full;
    }

    /**
     * Contratação pela cobrança automática que ainda não teve o 1º pagamento
     * confirmado. Não entra na régua de cobrança: sem pagar até o vencimento
     * da 1ª cobrança, o acesso acaba.
     */
    public function isAwaitingFirstPayment(): bool
    {
        return $this->billing_mode === SubscriptionBillingMode::Gateway
            && $this->status === SubscriptionStatus::PastDue
            && ! $this->hasBeenPaid();
    }

    /**
     * Até quando vale o acesso de uma contratação aguardando o 1º pagamento:
     * fim do dia do vencimento da 1ª cobrança (`next_billing_at`). Só depois
     * que a cobrança foi emitida no gateway (`gateway` preenchido na
     * confirmação) — antes disso, ou se a emissão falhou, não há acesso.
     */
    public function firstChargeAccessEndsAt(): ?Carbon
    {
        if (! $this->isAwaitingFirstPayment() || blank($this->gateway) || $this->next_billing_at === null
            || $this->needsBillingReconciliation()) {
            return null;
        }

        $dueEnd = $this->next_billing_at->copy()->endOfDay();

        // D5 vale uma vez (BillingSubscriptionOrchestrator::firstChargeGraceEnd):
        // a contratação gravou até quando o acesso pode valer sem pagar —
        // nunca além do vencimento.
        return $this->first_charge_grace_ends_at !== null && $this->first_charge_grace_ends_at->lessThan($dueEnd)
            ? $this->first_charge_grace_ends_at->copy()
            : $dueEnd;
    }

    /**
     * Mudança de plano/ciclo agendada para o fim do período pago (downgrade —
     * PlanChangeService::schedule): {plan_id, billing_cycle, amount,
     * effective_at, …}. Null sem agendamento.
     *
     * @return array<string, mixed>|null
     */
    public function scheduledChange(): ?array
    {
        $change = data_get($this->gateway_payload, 'scheduled_change');

        return is_array($change) && filled($change['plan_id'] ?? null) && filled($change['effective_at'] ?? null) ? $change : null;
    }

    /**
     * Fim do período pago por uma cobrança: vencimento + ciclo contratado,
     * até o fim do dia (o próximo vencimento). Null sem ciclo recorrente.
     */
    public function periodEndFrom(CarbonInterface $dueDate): ?Carbon
    {
        $months = $this->effectiveCycle()?->months() ?? 0;

        if ($months <= 0) {
            return null;
        }

        return Carbon::instance($dueDate)->startOfDay()->addMonthsNoOverflow($months)->endOfDay();
    }

    /**
     * Vencimento da próxima cobrança. Assinaturas pagas antes do registro de
     * next_billing_at (a coluna era descartada) caem no fim do período pago.
     */
    public function nextBillingDate(): ?Carbon
    {
        return $this->next_billing_at ?? ($this->hasBeenPaid() ? $this->ends_at : null);
    }

    /**
     * Renovação local: cobrança automática já paga antes, sem recorrência no
     * próprio gateway, com o próximo vencimento dentro da antecedência
     * (DunningSchedule::renewalWindowEnd, a mesma do agendador) e vigente na
     * empresa (linha substituída nunca é cobrada). Mesma regra do
     * scopeDueForLocalRenewal.
     */
    public function isDueForLocalRenewal(?CarbonInterface $until = null): bool
    {
        $until ??= DunningSchedule::renewalWindowEnd();

        return $this->billing_mode === SubscriptionBillingMode::Gateway
            && in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && $this->hasBeenPaid()
            && ! $this->needs_billing_reconciliation
            && filled($this->gateway)
            && blank($this->gateway_subscription_id)
            && $this->nextBillingDate() !== null
            && $this->nextBillingDate()->lessThanOrEqualTo($until)
            && $this->isCurrentOfEntity();
    }

    /**
     * A cobrança do período que começa no próximo vencimento já foi emitida
     * (fatura pendente, vencida ou paga). Rascunho ou falha na emissão não
     * contam: a renovação tenta de novo.
     */
    public function hasOpenInvoiceForNextBilling(): bool
    {
        $nextBilling = $this->nextBillingDate();

        if ($nextBilling === null) {
            return false;
        }

        return $this->invoices()
            ->forBillingPeriod()
            ->whereDate('period_start', $nextBilling->toDateString())
            ->whereIn('status', [
                InvoiceStatus::Pending->value,
                InvoiceStatus::Overdue->value,
                InvoiceStatus::Paid->value,
            ])
            ->exists();
    }

    /**
     * A cobrança do período no cartão salvo foi recusada e o cliente abriu
     * Pix/boleto pelo checkout (a fatura ficou em aberto noutra forma): a
     * renovação segue tentando o cartão até alguma das cobranças ser paga.
     */
    public function cardRetryPending(?Invoice $invoice): bool
    {
        return $invoice !== null
            && $this->hasSavedCard()
            && in_array($invoice->status, [InvoiceStatus::Pending, InvoiceStatus::Overdue], true)
            && $invoice->payment_method !== 'credit_card'
            && PaymentAttempt::query()
                ->where('invoice_id', $invoice->id)
                ->where('trigger', 'renewal')
                ->where('status', PaymentAttemptStatus::Failed->value)
                ->exists();
    }

    /** Fatura do próximo vencimento (null sem ela). */
    public function nextBillingInvoice(): ?Invoice
    {
        $next = $this->nextBillingDate();

        return $next === null ? null : $this->invoices()
            ->forBillingPeriod()
            ->whereDate('period_start', $next->toDateString())
            ->latest()
            ->first();
    }

    /** Renovação cobra o cartão guardado no gateway (checkout transparente). */
    public function hasSavedCard(): bool
    {
        return $this->payment_method === 'credit_card' && filled($this->gateway_card_id);
    }

    /**
     * Cobrança recorrente ativa no gateway — precisa ser cancelada lá antes
     * de a assinatura virar cortesia, senão o cliente continua sendo cobrado.
     */
    public function hasGatewayRecurrence(): bool
    {
        return $this->billing_mode === SubscriptionBillingMode::Gateway
            && filled($this->gateway)
            && filled($this->gateway_subscription_id);
    }

    /**
     * Registra que o PRÓPRIO sistema pediu ao gateway o cancelamento desta
     * recorrência (troca, cortesia, encerramento, refazer a recorrência). O
     * aviso do gateway que chega depois (ex.: SUBSCRIPTION_DELETED do Asaas)
     * não vira alerta de "recorrência desativada pelo gateway". Gravado ANTES
     * da chamada (o webhook pode chegar antes da resposta). Guarda os 20 mais
     * recentes em gateway_payload.recurrences_cancelled_by_us.
     */
    public static function rememberRecurrenceCancelledByUs(?string $subscriptionId, ?string $externalId): void
    {
        if (blank($subscriptionId) || blank($externalId)) {
            return;
        }

        $subscription = static::query()->withTrashed()->find($subscriptionId);

        if ($subscription === null || $subscription->recurrenceCancelledByUs($externalId)) {
            return;
        }

        $payload = (array) ($subscription->gateway_payload ?? []);
        $ids     = array_values(array_filter((array) ($payload['recurrences_cancelled_by_us'] ?? []), 'is_string'));

        $payload['recurrences_cancelled_by_us'] = array_slice([...$ids, (string) $externalId], -20);

        $subscription->forceFill(['gateway_payload' => $payload])->saveQuietly();
    }

    /** O próprio sistema pediu o cancelamento desta recorrência no gateway. */
    public function recurrenceCancelledByUs(?string $externalId): bool
    {
        return filled($externalId)
            && in_array((string) $externalId, (array) data_get($this->gateway_payload, 'recurrences_cancelled_by_us', []), true);
    }

    /**
     * Valor cobrado por ciclo; null quando não há cobrança (cortesia, trial
     * sem ciclo escolhido ou ciclo vitalício de planos antigos).
     */
    public function recurringAmount(): ?float
    {
        if ($this->isComplimentary()) {
            return null;
        }

        if ($this->amount !== null) {
            return (float) $this->amount;
        }

        $cycle = $this->effectiveCycle();

        if (! $cycle || $cycle === BillingCycle::Lifetime) {
            return null;
        }

        return $this->plan?->priceFor($cycle) ?? ($this->plan ? (float) $this->plan->price : null);
    }

    /**
     * Expressão SQL do valor mensal recorrente de cada linha (MRR), para somar
     * com `->join('plans', ...)`. Normaliza o ciclo (anual ÷ 12) e cai no
     * preço/ciclo do plano nas linhas antigas sem valor contratado (ciclo
     * vitalício de planos antigos não é receita recorrente).
     */
    public static function monthlyAmountSql(): string
    {
        $cycle = 'COALESCE(subscriptions.billing_cycle, plans.billing_cycle)';

        return "CASE WHEN {$cycle} = 'lifetime' THEN 0 "
            . 'ELSE COALESCE(subscriptions.amount, plans.price) / '
            . "CASE {$cycle} WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'yearly' THEN 12 ELSE 1 END END";
    }

    // ── Escopos ─────────────────────────────────────────────────────────────

    public function scopeForEntity($query, string $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    /** Só o que é receita: cobrança automática. */
    public function scopeBillable($query)
    {
        return $query->whereIn('subscriptions.billing_mode', SubscriptionBillingMode::billableValues());
    }

    /**
     * Já teve pagamento confirmado — contratação que nunca pagou (aguardando
     * o 1º pagamento ou com erro na emissão) não é receita recorrente.
     */
    public function scopeEverPaid($query)
    {
        return $query->whereNotNull('subscriptions.last_payment_at');
    }

    /**
     * Contratação pela cobrança automática aguardando o 1º pagamento. Mesma
     * regra de isAwaitingFirstPayment(); o whereNotNull evita NULL na lógica
     * de três valores quando o escopo é negado (whereNot).
     */
    public function scopeAwaitingFirstPayment($query)
    {
        return $query->where('subscriptions.status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('subscriptions.billing_mode')
            ->where('subscriptions.billing_mode', SubscriptionBillingMode::Gateway->value)
            ->whereNull('subscriptions.last_payment_at');
    }

    /** Vigentes (trial, ativa ou em atraso) — o que uma nova assinatura substitui. */
    public function scopeInForce($query)
    {
        return $query->whereIn('subscriptions.status', [
            SubscriptionStatus::Trial->value,
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PastDue->value,
        ]);
    }

    /**
     * Renovação local (gateway sem recorrência própria): cobrança automática
     * já paga antes, vigente na empresa, com o próximo vencimento até
     * `$until`. Mesma regra de isDueForLocalRenewal().
     */
    public function scopeDueForLocalRenewal($query, CarbonInterface $until)
    {
        return $query->latestPerEntity()
            ->where('subscriptions.billing_mode', SubscriptionBillingMode::Gateway->value)
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->whereNotNull('subscriptions.last_payment_at')
            ->where('subscriptions.needs_billing_reconciliation', false)
            ->whereNotNull('subscriptions.gateway')
            ->where('subscriptions.gateway', '!=', '')
            ->where(fn ($q) => $q->whereNull('subscriptions.gateway_subscription_id')->orWhere('subscriptions.gateway_subscription_id', ''))
            // Mesmo fallback de nextBillingDate() (já pagou: fim do período).
            ->whereRaw('COALESCE(subscriptions.next_billing_at, subscriptions.ends_at) IS NOT NULL')
            ->whereRaw('COALESCE(subscriptions.next_billing_at, subscriptions.ends_at) <= ?', [$until]);
    }

    /**
     * Tira as tentativas de contratação que o gateway recusou — elas ficam
     * no histórico, mas nunca são a vigente da empresa. Mesma regra de
     * isFailedActivation().
     */
    public function scopeWithoutFailedActivations($query)
    {
        return $query->where(fn ($q) => $q->whereNull('subscriptions.cancelled_reason')
            ->orWhere('subscriptions.cancelled_reason', '!=', SubscriptionCancelledReason::ActivationFailed->value));
    }

    /**
     * Uma linha por empresa: a assinatura mais recente (mesmo desempate de
     * currentFirst). As anteriores ficam no histórico. Tentativa de
     * contratação recusada pelo gateway não conta: a vigente segue sendo a
     * que continua valendo.
     */
    public function scopeLatestPerEntity($query)
    {
        return $query->withoutFailedActivations()->whereNotExists(function ($newer) {
            $newer->selectRaw('1')
                ->from('subscriptions as newer')
                ->whereColumn('newer.entity_id', 'subscriptions.entity_id')
                ->whereNull('newer.deleted_at')
                ->where(fn ($q) => $q->whereNull('newer.cancelled_reason')
                    ->orWhere('newer.cancelled_reason', '!=', SubscriptionCancelledReason::ActivationFailed->value))
                ->where(function ($q) {
                    $q->whereColumn('newer.created_at', '>', 'subscriptions.created_at')
                        ->orWhere(function ($tie) {
                            $tie->whereColumn('newer.created_at', 'subscriptions.created_at')
                                ->whereColumn('newer.id', '>', 'subscriptions.id');
                        });
                });
        });
    }

    /**
     * Com mais de uma assinatura acessível (ex.: trial automático ainda válido
     * + plano pago recém-contratado), vale a MAIS RECENTE — mesma regra que os
     * controllers de IA já usavam. Sem ordem, o `first()` dependia da ordem
     * física no banco: o recurso do plano podia ser liberado ou bloqueado a
     * cada consulta (e testes falhavam conforme a ordem de execução).
     */
    public function scopeCurrentFirst($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Cobrança automática do código anterior aguardando conciliação. Mesma
     * regra de needsBillingReconciliation().
     */
    public function scopeNeedingBillingReconciliation($query)
    {
        return $query->where('subscriptions.needs_billing_reconciliation', true)
            ->whereIn('subscriptions.status', array_map(fn (SubscriptionStatus $s) => $s->value, self::reconciliationStatuses()));
    }

    public function scopeAccessible($query)
    {
        return $query->where(function ($q) {
            // Aguardando conciliação: acesso total, só a vigente da empresa
            // (needsBillingReconciliation + isCurrentOfEntity).
            $q->where(fn ($subQ) => $subQ->needingBillingReconciliation()->latestPerEntity())->orWhere(function ($subQ) {
                $subQ->where('status', SubscriptionStatus::Trial->value)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now());
            })->orWhere(function ($subQ) {
                $subQ->where('status', SubscriptionStatus::Active->value)
                    ->where(function ($dateQ) {
                        $dateQ->whereNull('ends_at')
                            ->orWhere('ends_at', '>', now());
                    });
            })->orWhere(function ($subQ) {
                // Contratação aguardando o 1º pagamento (cobrança já emitida
                // no gateway): acesso até o fim do dia do vencimento.
                // Mesma regra de firstChargeAccessEndsAt(); os whereNotNull
                // evitam NULL na lógica de três valores (filtro "sem acesso"
                // usa whereNot).
                $subQ->where('subscriptions.status', SubscriptionStatus::PastDue->value)
                    ->whereNotNull('subscriptions.billing_mode')
                    ->where('subscriptions.billing_mode', SubscriptionBillingMode::Gateway->value)
                    ->whereNull('subscriptions.last_payment_at')
                    ->whereNotNull('subscriptions.gateway')
                    ->where('subscriptions.gateway', '!=', '')
                    ->whereNotNull('subscriptions.next_billing_at')
                    ->where('subscriptions.next_billing_at', '>=', now()->startOfDay())
                    // D5 uma vez: o limite gravado na contratação, se houver.
                    ->where(fn ($grace) => $grace->whereNull('subscriptions.first_charge_grace_ends_at')
                        ->orWhere('subscriptions.first_charge_grace_ends_at', '>', now()));
            })->orWhere(function ($subQ) {
                // Cliente pagante em atraso, dentro da régua: acesso (total
                // ou limitado) até o bloqueio total. Mesma regra de
                // isInDunning()/accessLevel(): atraso desde past_due_at (ou
                // do fim do período) a partir do corte de hoje.
                $cutoff = DunningSchedule::hardBlockCutoff();

                $subQ->whereNotNull('subscriptions.billing_mode')
                    ->where('subscriptions.billing_mode', SubscriptionBillingMode::Gateway->value)
                    ->whereNotNull('subscriptions.last_payment_at')
                    ->where(function ($overdue) use ($cutoff) {
                        $overdue->where(function ($pastDue) use ($cutoff) {
                            $pastDue->where('subscriptions.status', SubscriptionStatus::PastDue->value)
                                ->whereRaw('COALESCE(subscriptions.past_due_at, subscriptions.ends_at) IS NOT NULL')
                                ->whereRaw('COALESCE(subscriptions.past_due_at, subscriptions.ends_at) >= ?', [$cutoff]);
                        })->orWhere(function ($lapsed) use ($cutoff) {
                            $lapsed->where('subscriptions.status', SubscriptionStatus::Active->value)
                                ->whereNotNull('subscriptions.ends_at')
                                ->where('subscriptions.ends_at', '<=', now())
                                ->where('subscriptions.ends_at', '>=', $cutoff);
                        });
                    });
            });
        });
    }

    /**
     * Assinatura da empresa que hoje dá o melhor acesso (total antes de
     * limitado); no empate, a mais recente (currentFirst). Null sem acesso.
     */
    public static function bestAccessibleFor(string $entityId, array $with = []): ?self
    {
        return static::query()
            ->forEntity($entityId)
            ->accessible()
            ->currentFirst()
            ->with($with)
            ->get()
            ->sortByDesc(fn (self $s) => $s->accessLevel()->rank())
            ->first(fn (self $s) => $s->accessLevel()->hasAccess());
    }
}
