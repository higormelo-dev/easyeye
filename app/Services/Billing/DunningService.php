<?php

namespace App\Services\Billing;

use App\Enums\Billing\{CancellationReason, DunningStep, InvoiceStatus};
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\{Invoice, SubscriptionDunningStep};
use App\Models\{Entity, Subscription, User};
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Notifications\SubscriptionDunningNotification;
use App\Support\Billing\{DunningSchedule, NoticeLocale};
use Illuminate\Support\{Carbon, Collection, Str};
use Illuminate\Support\Facades\{Cache, DB};
use Throwable;

/**
 * Régua de cobrança (comando diário `billing:dunning`), só para a cobrança
 * automática. Prazos em App\Support\Billing\DunningSchedule:
 *
 * Cliente que já pagou:
 *  - D-5: lembrete do vencimento (valor, data e link); na renovação local,
 *    espera a cobrança do período ser emitida (no máximo até o D-1);
 *  - venceu sem pagamento (D0/D+1): "pagamento não identificado" com o link
 *    — a nova tentativa do boleto/Pix; acesso normal com aviso;
 *  - D+3: aviso de acesso limitado (IA e financeiro bloqueados);
 *  - D+7: assinatura encerrada por inadimplência (BillingCancellationService::expire)
 *    e recorrência cancelada no gateway. Cobrança avulsa já emitida (renovação
 *    local, 1ª cobrança) é cancelada no gateway quando ele permite; senão,
 *    alerta crítico para o time e o e-mail orienta a não pagar (stopCharges).
 *
 * Contratação que nunca pagou (sem régua — o acesso já acabou no vencimento):
 * aviso no dia seguinte ao vencimento e encerramento no D+7, parando a
 * recorrência no gateway.
 *
 * Só a assinatura vigente da empresa (Subscription::isCurrentOfEntity): linha
 * antiga, já substituída, nunca gera aviso, encerramento nem cancelamento no
 * gateway.
 *
 * Idempotente: cada etapa é gravada uma vez por assinatura e vencimento
 * (subscription_dunning_steps, índice único). A situação é reconferida com a
 * empresa e a assinatura travadas (mesma ordem do SubscriptionManagementService)
 * e de novo na hora do envio do e-mail (shouldSend). Chamadas ao gateway
 * ficam fora da transação.
 */
class DunningService
{
    /** O que o e-mail de encerramento diz sobre as cobranças (billing_dunning.charges.*). */
    public const CHARGES_RECURRENCE_CANCELLED = 'recurrence_cancelled';

    public const CHARGES_RECURRENCE_CANCELLING = 'recurrence_cancelling';

    public const CHARGES_OPEN_CHARGE = 'open_charge';

    public const CHARGES_OPEN_CHARGE_CANCELLED = 'open_charge_cancelled';

    public const CHARGES_NONE = 'none';

    public function __construct(
        private readonly BillingCancellationService $cancellation,
        private readonly BillingLogService $billingLog,
        private readonly GatewayRegistry $registry,
        private readonly GatewayPaymentReconciler $reconciler,
    ) {
    }

    /** Chave de emergência (billing.dunning.enabled): false para a régua inteira. */
    public function isEnabled(): bool
    {
        return (bool) config('billing.dunning.enabled', true);
    }

    /**
     * Roda a régua para todas as assinaturas candidatas. Desligada pela
     * chave de emergência, não faz nada.
     *
     * @return array<string, int> etapas cumpridas nesta execução, por etapa
     */
    public function run(): array
    {
        $stats = collect(DunningStep::cases())->mapWithKeys(fn (DunningStep $s) => [$s->value => 0])->all();

        if (! $this->isEnabled()) {
            return $stats;
        }

        foreach ($this->candidateIds() as $id) {
            // Uma assinatura com problema não para a régua das outras.
            try {
                $step = $this->process((string) $id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($step) {
                $stats[$step->value]++;
            }
        }

        return $stats;
    }

    /**
     * Etapa da régua que vale agora para a assinatura (null = nada a fazer).
     * A mesma regra decide o registro e o envio (stillApplies).
     */
    public function stepFor(Subscription $subscription): ?DunningStep
    {
        // Linha do código anterior aguardando conciliação: sem aviso nem
        // encerramento até o billing:reconcile-legacy conferir no gateway.
        if ($subscription->billing_mode !== SubscriptionBillingMode::Gateway || $subscription->needsBillingReconciliation()) {
            return null;
        }

        // Só a assinatura vigente da empresa: linha antiga (substituída) nunca
        // gera aviso, encerramento nem cancelamento no gateway — a clínica
        // pode estar em dia na vigente.
        if (! $subscription->isCurrentOfEntity()) {
            return null;
        }

        $stage = $subscription->dunningStage();

        if ($stage !== null) {
            return $stage;
        }

        // Cliente em dia: lembrete nos dias que antecedem o próximo vencimento.
        if ($subscription->status === SubscriptionStatus::Active && $subscription->hasBeenPaid()) {
            $next = $subscription->nextBillingDate();

            if ($next !== null && $next->isFuture()) {
                $daysUntil = DunningSchedule::daysSince(now(), $next);

                if ($daysUntil >= 1 && $daysUntil <= DunningSchedule::reminderDaysBefore()
                    && $this->reminderReady($subscription, $next, $daysUntil)) {
                    return DunningStep::Reminder;
                }
            }
        }

        return null;
    }

    /**
     * Renovação local (sem recorrência no gateway): somos nós que emitimos a
     * cobrança (RenewSubscriptionJob, um dia antes do lembrete), e o
     * lembrete espera ela existir para sair com o link — sem gravar a etapa,
     * a régua tenta de novo no dia seguinte. No último dia da antecedência
     * (D-1) sai de qualquer jeito (a emissão falhou até lá). Na recorrência
     * nativa o gateway emite a cobrança: o lembrete não espera.
     */
    private function reminderReady(Subscription $subscription, Carbon $next, int $daysUntil): bool
    {
        // Cartão salvo: a cobrança só sai no dia do vencimento (no cartão) —
        // o lembrete não espera fatura nem link.
        if ($subscription->hasGatewayRecurrence() || $daysUntil <= 1 || $this->renewsOnSavedCard($subscription)) {
            return true;
        }

        return $this->invoiceFor($subscription, DunningStep::Reminder, $next->toDateString()) !== null;
    }

    /** Vencimento a que a etapa se refere (Y-m-d) — parte da chave de idempotência. */
    public function referenceDate(Subscription $subscription, DunningStep $step): ?string
    {
        return match ($step) {
            DunningStep::Reminder => $subscription->nextBillingDate()?->toDateString(),
            DunningStep::FirstChargeOverdue,
            DunningStep::FirstChargeTerminated => $subscription->next_billing_at?->toDateString(),
            default                            => $subscription->overdueSince()?->toDateString(),
        };
    }

    /**
     * A etapa ainda vale para a assinatura? (reconferência na hora do envio:
     * pagou, mudou de etapa ou foi encerrada por outro caminho → não envia).
     * Encerramento já aconteceu — o aviso sempre sai.
     */
    public function stillApplies(?Subscription $subscription, DunningStep $step, string $dueOn): bool
    {
        if ($step->terminates()) {
            return true;
        }

        return $subscription !== null
            && $this->stepFor($subscription) === $step
            && $this->referenceDate($subscription, $step) === $dueOn;
    }

    /**
     * Quem recebe os avisos de cobrança da empresa: os contatos de cobrança
     * (EntityUser::billingContacts — admin, financeiro e o dono, os mesmos
     * que veem valor e link da fatura no painel), com e-mail verificado.
     *
     * @return Collection<int, User>
     */
    public function recipients(string $entityId): Collection
    {
        return User::query()
            ->whereNotNull('users.email_verified_at')
            ->whereHas('entityUsers', fn ($q) => $q->where('entity_id', $entityId)->billingContacts())
            ->orderBy('users.id')
            ->get();
    }

    /**
     * Simulação (billing:dunning --dry-run): o que a régua faria agora, sem
     * travar, gravar, enviar e-mail nem chamar o gateway. Mesma regra do
     * run() — etapa ainda não registrada para o vencimento.
     *
     * @return Collection<int, array{subscription_id: string, entity: string, step: DunningStep, due_on: string, days_overdue: ?int, gateway: ?string, stops_recurrence: bool, recipients: int}>
     */
    public function preview(): Collection
    {
        return $this->candidateIds()
            ->map(function (string $id): ?array {
                $subscription = Subscription::query()->with('entity')->find($id);
                $step         = $subscription ? $this->stepFor($subscription) : null;
                $dueOn        = $step ? $this->referenceDate($subscription, $step) : null;

                if (! $step || ! $dueOn
                    || $subscription->dunningSteps()->where('step', $step->value)->whereDate('due_on', $dueOn)->exists()) {
                    return null;
                }

                return [
                    'subscription_id'  => (string) $subscription->id,
                    'entity'           => (string) $subscription->entity?->name,
                    'step'             => $step,
                    'due_on'           => $dueOn,
                    'days_overdue'     => $subscription->daysOverdue(),
                    'gateway'          => $subscription->gateway,
                    'stops_recurrence' => $step->terminates() && $subscription->hasGatewayRecurrence(),
                    'recipients'       => blank($subscription->gateway) ? 0 : $this->recipients((string) $subscription->entity_id)->count(),
                ];
            })
            ->filter()
            ->values();
    }

    // ── Execução ─────────────────────────────────────────────────────────────

    /** @return Collection<int, string> */
    private function candidateIds(): Collection
    {
        $reminderUntil = now()->addDays(DunningSchedule::reminderDaysBefore())->endOfDay();

        return Subscription::query()
            ->latestPerEntity()
            ->where('subscriptions.billing_mode', SubscriptionBillingMode::Gateway->value)
            ->where('subscriptions.needs_billing_reconciliation', false)
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->where(fn ($q) => $q->where('subscriptions.status', SubscriptionStatus::PastDue->value)
                // Vencimento próximo (lembrete) ou período pago já vencido.
                ->orWhereRaw('COALESCE(subscriptions.next_billing_at, subscriptions.ends_at) <= ?', [$reminderUntil]))
            ->orderBy('subscriptions.id')
            ->pluck('subscriptions.id');
    }

    private function process(string $subscriptionId): ?DunningStep
    {
        // Antes de limitar o acesso ou encerrar: o pagamento pode ter
        // acontecido no gateway sem o webhook chegar (fila pausada, atraso).
        // Pago lá → aplicado pelo caminho do webhook e a etapa não acontece.
        if (! $this->confirmUnpaidAtGateway($subscriptionId)) {
            return null;
        }

        $result = DB::transaction(function () use ($subscriptionId): ?array {
            $entityId = Subscription::query()->whereKey($subscriptionId)->value('entity_id');
            $entity   = $entityId ? Entity::query()->whereKey($entityId)->lockForUpdate()->first() : null;
            $sub      = $entity ? Subscription::query()->whereKey($subscriptionId)->lockForUpdate()->first() : null;

            if (! $sub) {
                return null;
            }

            $step  = $this->stepFor($sub);
            $dueOn = $step ? $this->referenceDate($sub, $step) : null;

            if (! $step || ! $dueOn) {
                return null;
            }

            $done = $sub->dunningSteps()->where('step', $step->value)->whereDate('due_on', $dueOn)->exists();

            if ($done) {
                return null;
            }

            $invoice       = $this->invoiceFor($sub, $step, $dueOn);
            $context       = $this->context($entity, $sub, $step, $dueOn, $invoice);
            $daysOverdue   = $sub->daysOverdue();
            $correlationId = (string) Str::uuid();
            // Antes de encerrar (as faturas em aberto viram canceladas):
            // recorrência no gateway e cobranças avulsas já emitidas.
            $hadRecurrence = $sub->hasGatewayRecurrence();
            $openCharges   = $step->terminates() && ! $hadRecurrence ? $this->emittedOpenCharges($sub) : collect();

            if ($step->terminates()) {
                $this->terminate($sub, $entity, $correlationId);
            }

            $record = SubscriptionDunningStep::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $sub->id,
                'invoice_id'      => $invoice?->id,
                'step'            => $step->value,
                'due_on'          => $dueOn,
                'metadata'        => [
                    'days_overdue'   => $daysOverdue,
                    'amount'         => $context['amount'],
                    'correlation_id' => $correlationId,
                ],
            ]);

            return [$sub->fresh(), $entity, $step, $dueOn, $context, $record, $correlationId, $hadRecurrence, $openCharges];
        });

        if ($result === null) {
            return null;
        }

        [$subscription, $entity, $step, $dueOn, $context, $record, $correlationId, $hadRecurrence, $openCharges] = $result;

        // Para a cobrança recorrente no gateway só depois do commit; o
        // e-mail diz exatamente o que aconteceu com as cobranças.
        if ($step->terminates()) {
            $context['charges'] = $this->stopCharges($subscription, $hadRecurrence, $openCharges, $correlationId);
        }

        [$recipients, $whatsapp] = $this->notify($subscription, $entity, $step, $dueOn, $context);

        $record->update([
            'recipients_count' => $recipients,
            'metadata'         => [
                ...($record->metadata ?? []),
                ...(isset($context['charges']) ? ['charges' => $context['charges']] : []),
                // Contatos com WhatsApp verificado (o envio segue na fila, em horário comercial).
                'whatsapp_recipients' => $whatsapp,
            ],
        ]);

        return $step;
    }

    /**
     * Etapa que limita o acesso (D+3) ou encerra (D+7): confere no gateway as
     * cobranças em aberto (GatewayPaymentReconciler — Asaas: GET
     * /v3/payments/{id}). Só "não pago" CONCLUSIVO deixa seguir. False = não
     * seguir hoje: o pagamento foi aplicado agora (a régua relê e não acha
     * mais atraso) ou a consulta não foi conclusiva (timeout, 5xx, chave
     * recusada, 429) — a etapa é adiada para a próxima rodada; depois de
     * billing.dunning.max_gateway_check_deferrals adiamentos seguidos, alerta
     * crítico ao time (a régua nunca encerra sozinha sem conferir).
     */
    private function confirmUnpaidAtGateway(string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);
        $step         = $subscription ? $this->stepFor($subscription) : null;

        if ($subscription === null || ! in_array($step, [DunningStep::Limited, DunningStep::Terminated, DunningStep::FirstChargeTerminated], true)) {
            return true;
        }

        $dueOn = $this->referenceDate($subscription, $step);

        if ($dueOn !== null && $subscription->dunningSteps()->where('step', $step->value)->whereDate('due_on', $dueOn)->exists()) {
            return true;
        }

        try {
            $applied = $this->reconciler->reconcileSubscription($subscription, strict: true);
        } catch (Throwable $e) {
            if (! $e instanceof GatewayIntegrationException) {
                report($e);
            }

            $this->deferStep($subscription, $step, (string) $dueOn, $e);

            return false;
        }

        Cache::forget($this->deferralKey($subscription, $step, (string) $dueOn));

        return ! $applied;
    }

    /**
     * Conferência inconclusiva: a etapa fica para a próxima rodada. Conta os
     * adiamentos seguidos da etapa/vencimento; no limite, alerta crítico ao
     * time (uma vez por dia) — sem encerrar/limitar sozinho.
     */
    private function deferStep(Subscription $subscription, DunningStep $step, string $dueOn, Throwable $e): void
    {
        $key   = $this->deferralKey($subscription, $step, $dueOn);
        $count = (int) Cache::get($key, 0) + 1;
        $max   = max(1, (int) config('billing.dunning.max_gateway_check_deferrals', 3));
        $rate  = $e instanceof GatewayIntegrationException && $e->isRateLimit();

        Cache::put($key, $count, now()->addDays(60));

        $this->billingLog->log(
            level: $count >= $max ? 'critical' : 'warning',
            message: $rate
                ? 'Régua: o gateway pediu para esperar (429) antes de conferir o pagamento — etapa adiada para a próxima rodada.'
                : 'Régua: não foi possível conferir o pagamento no gateway (sem resposta conclusiva) — etapa adiada para a próxima rodada; nada é limitado nem encerrado sem conferir.',
            context: ['step' => $step->value, 'due_on' => $dueOn, 'deferrals' => $count, 'error' => mb_substr($e->getMessage(), 0, 300)],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
        );

        if ($count >= $max) {
            app(GatewayAlertService::class)->alert(
                gateway: (string) $subscription->gateway,
                kind: 'dunning_check_failed',
                params: ['entity' => (string) $subscription->entity?->name, 'step' => $step->value, 'count' => $count, 'detail' => mb_substr($e->getMessage(), 0, 200)],
                message: "Régua de {$subscription->entity?->name}: a etapa {$step->value} foi adiada {$count} vezes sem conseguir conferir o pagamento no gateway — conferir à mão.",
                level: 'critical',
                throttleKey: (string) $subscription->id . ':' . $step->value . ':' . $dueOn,
                throttleMinutes: 60 * 24,
                entityId: (string) $subscription->entity_id,
            );
        }
    }

    private function deferralKey(Subscription $subscription, DunningStep $step, string $dueOn): string
    {
        return "billing:dunning:gateway-check-deferrals:{$subscription->id}:{$step->value}:{$dueOn}";
    }

    /**
     * Encerra por inadimplência: expirada, com a troca registrada; as
     * cobranças em aberto desta assinatura deixam de valer.
     */
    private function terminate(Subscription $subscription, Entity $entity, string $correlationId): void
    {
        $this->cancellation->expire(
            subscription: $subscription,
            entity: $entity,
            reason: CancellationReason::NonPayment,
            source: 'dunning',
            cancelAtGateway: false,
            correlationId: $correlationId,
        );

        $subscription->invoices()
            ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value])
            ->each(fn (Invoice $invoice) => $invoice->update(['status' => InvoiceStatus::Cancelled->value]));
    }

    /**
     * Fatura do aviso: só a cobrança em aberto daquele vencimento — a do
     * período lembrado (D-5) ou a do período que contém o vencimento em
     * atraso. Nunca a já paga (ex.: a do ciclo anterior, que fica em
     * current_invoice_id) nem a de outro vencimento: sem ela, o e-mail sai
     * sem link (e sem "Pagar agora").
     */
    private function invoiceFor(Subscription $subscription, DunningStep $step, string $dueOn): ?Invoice
    {
        if ($step === DunningStep::Reminder) {
            return $subscription->invoices()
                ->forBillingPeriod()
                ->whereDate('period_start', $dueOn)
                ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value])
                ->latest()
                ->first();
        }

        return $subscription->invoices()
            ->forBillingPeriod()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
            ->where(fn ($q) => $q->where(fn ($period) => $period->whereDate('period_start', '<=', $dueOn)
                ->where(fn ($end) => $end->whereNull('period_end')->orWhereDate('period_end', '>', $dueOn)))
                // Fatura antiga sem período: a que vence naquele dia.
                ->orWhere(fn ($legacy) => $legacy->whereNull('period_start')->whereDate('due_at', $dueOn)))
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Cobranças avulsas já emitidas no gateway e ainda em aberto (boleto/Pix
     * da renovação local ou da 1ª cobrança) — stopCharges tenta cancelá-las.
     *
     * @return Collection<int, Invoice>
     */
    private function emittedOpenCharges(Subscription $subscription): Collection
    {
        return $subscription->invoices()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value])
            ->where(fn ($q) => $q->where(fn ($ext) => $ext->whereNotNull('external_invoice_id')->where('external_invoice_id', '!=', ''))
                ->orWhere(fn ($url) => $url->whereNotNull('payment_url')->where('payment_url', '!=', '')))
            ->get();
    }

    /**
     * Para as cobranças da assinatura encerrada e diz o que de fato aconteceu:
     *  - recorrência no gateway: cancelada agora, ou em andamento (o job
     *    tenta de novo);
     *  - cobrança avulsa já emitida: cancelada no gateway quando ele permite
     *    (Asaas, Mercado Pago, Pagar.me, Stripe) — o e-mail diz que foi
     *    cancelada; senão (InfinitePay, PagBank, falha) alerta crítico para o
     *    time cancelar à mão e o e-mail orienta a não pagar;
     *  - nada emitido: nenhuma nova cobrança.
     *
     * @param Collection<int, Invoice> $openCharges
     */
    private function stopCharges(Subscription $subscription, bool $hadRecurrence, Collection $openCharges, string $correlationId): string
    {
        if ($hadRecurrence) {
            return $this->cancellation->stopGatewayRecurrence($subscription, $correlationId) === false
                ? self::CHARGES_RECURRENCE_CANCELLING
                : self::CHARGES_RECURRENCE_CANCELLED;
        }

        if ($openCharges->isEmpty()) {
            return self::CHARGES_NONE;
        }

        // Cancela no gateway o que ele permite (cancelCharge); o resto fica
        // em aberto, com alerta para o time e o e-mail dizendo para não pagar.
        $result = $this->cancellation->cancelOpenCharges($subscription, $openCharges, $correlationId, alertOpen: false);

        // Link sem id externo (não dá para cancelar) também fica em aberto.
        $stillOpen = $openCharges->filter(fn (Invoice $invoice) => blank($invoice->external_invoice_id)
            || in_array((string) $invoice->external_invoice_id, $result['open'], true));

        if ($stillOpen->isEmpty()) {
            return self::CHARGES_OPEN_CHARGE_CANCELLED;
        }

        $this->billingLog->log(
            level: 'critical',
            message: 'Assinatura encerrada por inadimplência com cobrança avulsa em aberto no gateway — cancelar manualmente (se for paga, o webhook registra sem dar acesso).',
            context: [
                'gateway'              => $subscription->gateway,
                'external_charge_ids'  => $stillOpen->pluck('external_invoice_id')->filter()->values()->all(),
                'invoice_ids'          => $stillOpen->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                'cancelled_charge_ids' => $result['cancelled'],
            ],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
            correlationId: $correlationId,
        );

        return self::CHARGES_OPEN_CHARGE;
    }

    /**
     * Dados do e-mail — só da assinatura (empresa, valor, datas e link). No
     * encerramento, process() acrescenta `charges` (o que aconteceu com as
     * cobranças).
     *
     * `due_date` é o vencimento real da cobrança em atraso (unpaidDueDate):
     * na linha conciliada do código anterior a régua conta da conciliação
     * ($dueOn), mas o e-mail diz quando a cobrança venceu.
     *
     * @return array{entity: string, due_date: ?string, amount: ?float, payment_url: ?string, limited_on: ?string, blocked_on: ?string}
     */
    private function context(Entity $entity, Subscription $subscription, DunningStep $step, string $dueOn, ?Invoice $invoice): array
    {
        $due = Carbon::parse($dueOn);

        $card = $step === DunningStep::Reminder && $this->renewsOnSavedCard($subscription)
            ? [
                'brand' => (string) $subscription->card_brand,
                'last4' => (string) $subscription->card_last4,
                // Anual parcelado: a renovação no cartão salvo é à vista
                // (o gateway não parcela cobrança iniciada pelo lojista).
                'in_full' => (int) $subscription->card_installments > 1
                    && ! $this->registry->get((string) $subscription->gateway)->supportsRenewalInstallments(),
            ]
            : null;

        return [
            'entity' => (string) $entity->name,
            // Lembrete da renovação no cartão salvo: só bandeira e 4 últimos.
            ...($card !== null ? ['card' => $card] : []),
            'due_date' => $subscription->unpaidDueDate()?->toDateString(),
            'amount'   => $invoice ? (float) $invoice->amount : $subscription->recurringAmount(),
            // Encerrada não tem o que pagar: o pagamento não religaria o acesso.
            'payment_url' => ! $step->terminates() && filled($invoice?->payment_url) ? (string) $invoice->payment_url : null,
            // WhatsApp: link para pagar esta fatura dentro do sistema (Minha assinatura).
            'invoice_id' => ! $step->terminates() && $invoice !== null ? (string) $invoice->id : null,
            'limited_on' => DunningSchedule::limitedFrom($due)->toDateString(),
            'blocked_on' => DunningSchedule::blockedFrom($due)->toDateString(),
        ];
    }

    /** A renovação desta assinatura cobra o cartão salvo no gateway (RenewSubscriptionJob). */
    private function renewsOnSavedCard(Subscription $subscription): bool
    {
        return $subscription->hasSavedCard()
            && blank($subscription->gateway_subscription_id)
            && filled($subscription->gateway)
            && $this->registry->has((string) $subscription->gateway)
            && $this->registry->get((string) $subscription->gateway)->chargesSavedCards();
    }

    /**
     * E-mail (e WhatsApp, para quem tem o telefone verificado) a cada contato
     * de cobrança. Falha no envio de um contato não impede os demais nem a
     * régua (o registro da etapa já foi gravado).
     *
     * @return array{0: int, 1: int} destinatários do e-mail e quantos também pelo WhatsApp
     */
    private function notify(Subscription $subscription, Entity $entity, DunningStep $step, string $dueOn, array $context): array
    {
        // Contratação cuja cobrança nem chegou a ser emitida: não há o que pagar.
        if (blank($subscription->gateway)) {
            return [0, 0];
        }

        $recipients = $this->recipients((string) $entity->id);
        $whatsapp   = 0;

        foreach ($recipients as $user) {
            $whatsapp += SaasWhatsAppChannel::reachable($user) ? 1 : 0;

            try {
                $user->notify(
                    (new SubscriptionDunningNotification($subscription, $step, $dueOn, $context))
                        ->locale(NoticeLocale::for($user, $entity)),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [$recipients->count(), $whatsapp];
    }
}
