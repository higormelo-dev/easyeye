<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet, AiRun};
use App\Domains\AI\Support\AiQuotaWindow;
use App\Enums\AI\{AiLedgerEntryType, AiProvider, AiRunStatus};
use App\Enums\FeatureKey;
use App\Models\{Plan, Subscription};
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Carteira de créditos IA — modelo unificado.
 *
 * Saldo: 1 número único (balance + monthly_quota). Cliente NÃO escolhe provedor.
 * Tracking: cada AiCreditLedgerEntry grava o `provider` (analítico, para dashboards).
 *
 * Política de consumo: CONSOME A COTA MENSAL PRIMEIRO (que expira no ciclo),
 * depois desconta do balance comprado (que acumula). Isso protege o cliente do
 * desperdício de créditos comprados quando ainda há cota disponível.
 */
class AiCreditWalletService
{
    // ─── Concessões (créditos que ENTRAM na carteira) ─────────────────────────

    /**
     * Concede ou ajusta a cota mensal do plano. Idempotente por ciclo.
     *
     * Não soma à cota: SUBSTITUI o valor da cota e RESETA o consumido,
     * propagando o novo período. É o ato de "iniciar um novo ciclo mensal".
     */
    public function grantMonthlyQuota(
        string $entityId,
        int $amount,
        CarbonImmutable $periodEndsAt,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $periodEndsAt,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Grant)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);

            // Concessão concorrente (agendador × leitura sob demanda): quem
            // esperou o lock vê a concessão já gravada e não zera o consumo.
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Grant)) {
                return $existing;
            }

            // Reset do ciclo mensal: nova cota substitui qualquer resíduo do ciclo anterior.
            $wallet->monthly_quota                  = $amount;
            $wallet->monthly_quota_used             = 0;
            $wallet->quota_period_ends_at           = $periodEndsAt;
            $wallet->monthly_quota_lifetime_granted = (int) $wallet->monthly_quota_lifetime_granted + $amount;
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Grant,
                provider: null,
                amount: $amount,
                subscriptionId: $subscriptionId,
                description: $description ?? 'Cota mensal de créditos IA concedida.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], [
                    'kind'           => 'monthly_quota',
                    'period_ends_at' => $periodEndsAt->toAtomString(),
                ]),
            );
        });
    }

    /**
     * Concede a franquia mensal da janela atual da assinatura — só cobrança
     * automática paga com acesso total (Subscription::earnsMonthlyAiQuota):
     * trial e cortesia não recebem.
     *
     * Janelas de 1 mês ancoradas na 1ª concessão desta assinatura (a
     * ativação paga) — ver AiQuotaWindow. Idempotente por assinatura + início
     * da janela: renovação paga, webhook repetido e "Adicionar período" não
     * reiniciam o consumo. A janela seguinte vem da leitura da carteira
     * (grantDueQuotaWindow) ou do ai:grant-monthly-quotas, o que vier antes.
     * Sem acúmulo: a cota da nova janela substitui a da anterior.
     */
    public function grantMonthlyCreditsForSubscription(Subscription $subscription): ?AiCreditLedgerEntry
    {
        if (! $subscription->earnsMonthlyAiQuota()) {
            return null;
        }

        $plan   = $this->currentPlan($subscription);
        $amount = $plan ? $this->planMonthlyQuota($plan) : 0;

        if ($amount <= 0) {
            return null;
        }

        $window = $this->currentQuotaWindow($subscription);

        if ($this->quotaWindowGranted($subscription, $window)) {
            return null;
        }

        return $this->grantMonthlyQuota(
            entityId: $subscription->entity_id,
            amount: $amount,
            periodEndsAt: $window->end,
            subscriptionId: $subscription->id,
            description: "Cota mensal do plano {$plan->name}.",
            idempotencyKey: $window->grantKey($subscription->id),
            metadata: [
                'plan_id'       => $plan->id,
                'plan_slug'     => $plan->slug,
                'window_anchor' => $window->anchor->toDateString(),
                'window_start'  => $window->start->toDateString(),
                'window_end'    => $window->end->toDateString(),
                'window_index'  => $window->index,
                'source'        => 'subscription_window',
            ],
        );
    }

    /**
     * Concessão sob demanda: a carteira lida para reservar ou medir com a
     * janela da franquia vencida recebe a janela vigente na hora, sem esperar
     * o ai:grant-monthly-quotas das 00:20 (que segue como garantia, inclusive
     * para quem não abre a IA). Só para a assinatura vigente da empresa que
     * ganha franquia (earnsMonthlyAiQuota) — cortesia, trial e acesso
     * limitado não recebem. Idempotente pela chave da janela.
     *
     * @return bool true quando a janela vigente está concedida (agora ou por
     *              uma leitura concorrente) — a carteira deve ser relida
     */
    public function grantDueQuotaWindow(string $entityId): bool
    {
        $wallet = AiCreditWallet::query()
            ->where('entity_id', $entityId)
            ->first(['id', 'monthly_quota', 'quota_period_ends_at']);

        // Só quem já teve franquia e viu a janela vencer (cota zerada por
        // cortesia/trial fica com monthly_quota = 0 e não consulta nada).
        if ($wallet === null || (int) $wallet->monthly_quota <= 0 || ! $wallet->quotaExpired()) {
            return false;
        }

        $subscription = Subscription::bestAccessibleFor($entityId);

        if ($subscription === null || ! $subscription->earnsMonthlyAiQuota()) {
            return false;
        }

        try {
            return $this->grantMonthlyCreditsForSubscription($subscription) !== null;
        } catch (Throwable $e) {
            // A leitura (medidor/reserva) não pode quebrar por isso: o
            // agendador concede depois.
            report($e);

            return false;
        }
    }

    /**
     * Troca de plano na mesma assinatura, no meio da janela: a cota passa a
     * ser a do novo plano e o consumido da janela é mantido (trocar de plano
     * não zera o uso). Sem janela concedida ainda, concede a do novo plano.
     */
    public function applyPlanChangeForSubscription(Subscription $subscription): ?AiCreditLedgerEntry
    {
        $plan = $this->currentPlan($subscription);

        if (! $plan || ! $subscription->earnsMonthlyAiQuota()) {
            return null;
        }

        $window = $this->currentQuotaWindow($subscription);

        if (! $this->quotaWindowGranted($subscription, $window)) {
            return $this->grantMonthlyCreditsForSubscription($subscription);
        }

        $amount = $this->planMonthlyQuota($plan);

        return DB::transaction(function () use ($subscription, $plan, $amount, $window): ?AiCreditLedgerEntry {
            $key = "ai-quota-plan:{$subscription->id}:{$window->start->toDateString()}:{$plan->id}";

            if ($existing = $this->findIdempotentEntry($key, $subscription->entity_id, AiLedgerEntryType::Adjustment)) {
                return $existing;
            }

            $wallet = $this->lockWallet($subscription->entity_id);

            // A carteira precisa estar na janela desta assinatura (outra
            // assinatura da empresa pode ter assumido a cota depois).
            if ($wallet->quotaExpired()
                || $wallet->quota_period_ends_at === null
                || ! $wallet->quota_period_ends_at->equalTo($window->end)) {
                return null;
            }

            $previous = (int) $wallet->monthly_quota;

            if ($previous === $amount) {
                return null;
            }

            $wallet->monthly_quota                  = $amount;
            $wallet->monthly_quota_lifetime_granted = (int) $wallet->monthly_quota_lifetime_granted + max(0, $amount - $previous);
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Adjustment,
                provider: null,
                amount: $amount - $previous,
                subscriptionId: $subscription->id,
                description: "Cota mensal ajustada ao plano {$plan->name}.",
                idempotencyKey: $key,
                metadata: [
                    'adjustment_reason' => 'quota_plan_change',
                    'kind'              => 'monthly_quota',
                    'plan_id'           => $plan->id,
                    'previous_quota'    => $previous,
                    'new_quota'         => $amount,
                    'quota_used'        => (int) $wallet->monthly_quota_used,
                    'window_start'      => $window->start->toDateString(),
                    'window_end'        => $window->end->toDateString(),
                ],
            );
        });
    }

    /**
     * Zera a cota mensal que ainda resta (a assinatura virou cortesia ou foi
     * trocada por trial/cortesia — sem franquia de IA). Saldo comprado e
     * créditos de cortesia do manager ficam. Registra no ledger (expire).
     */
    public function forfeitMonthlyQuota(
        string $entityId,
        ?string $subscriptionId,
        string $reason,
        ?string $createdBy = null,
    ): ?AiCreditLedgerEntry {
        if (! AiCreditWallet::query()->where('entity_id', $entityId)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($entityId, $subscriptionId, $reason, $createdBy): ?AiCreditLedgerEntry {
            $wallet = $this->lockWallet($entityId);

            if ((int) $wallet->monthly_quota === 0 && (int) $wallet->monthly_quota_used === 0) {
                return null;
            }

            $remaining = $wallet->quotaRemaining();
            $quota     = (int) $wallet->monthly_quota;
            $used      = (int) $wallet->monthly_quota_used;

            $wallet->monthly_quota        = 0;
            $wallet->monthly_quota_used   = 0;
            $wallet->quota_period_ends_at = now();
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Expire,
                provider: null,
                amount: -$remaining,
                subscriptionId: $subscriptionId,
                description: 'Cota mensal de IA encerrada: a assinatura não inclui franquia de IA.',
                createdBy: $createdBy,
                metadata: [
                    'kind'           => 'monthly_quota',
                    'reason'         => $reason,
                    'forfeited'      => $remaining,
                    'previous_quota' => $quota,
                    'previous_used'  => $used,
                ],
            );
        });
    }

    /**
     * Janela atual da franquia da assinatura. A âncora é o dia da 1ª
     * concessão desta assinatura (a ativação paga); sem concessão ainda, hoje.
     */
    public function currentQuotaWindow(Subscription $subscription, ?CarbonInterface $at = null): AiQuotaWindow
    {
        return AiQuotaWindow::containing($this->quotaAnchor($subscription), $at ?? now());
    }

    private function quotaAnchor(Subscription $subscription): CarbonImmutable
    {
        $first = AiCreditLedgerEntry::query()
            ->where('entity_id', $subscription->entity_id)
            ->where('subscription_id', $subscription->id)
            ->where('type', AiLedgerEntryType::Grant->value)
            ->orderBy('created_at')
            ->first(['created_at', 'metadata']);

        $anchor = data_get($first?->metadata, 'window_anchor');

        if (is_string($anchor) && $anchor !== '') {
            return CarbonImmutable::parse($anchor)->startOfDay();
        }

        // Concessões anteriores às janelas (período = ends_at): a 1ª foi na ativação.
        return CarbonImmutable::instance($first?->created_at ?? now())->startOfDay();
    }

    /**
     * A janela já teve a franquia concedida: pela chave da janela ou, nas
     * concessões anteriores às janelas, por uma concessão feita dentro dela.
     */
    private function quotaWindowGranted(Subscription $subscription, AiQuotaWindow $window): bool
    {
        return AiCreditLedgerEntry::query()
            ->where('entity_id', $subscription->entity_id)
            ->where('subscription_id', $subscription->id)
            ->where('type', AiLedgerEntryType::Grant->value)
            ->where(fn ($q) => $q->where('idempotency_key', $window->grantKey($subscription->id))
                ->orWhere(fn ($range) => $range->where('created_at', '>=', $window->start)
                    ->where('created_at', '<', $window->end)))
            ->exists();
    }

    /** Plano atual da assinatura (a relação carregada pode ser a do plano anterior à troca). */
    private function currentPlan(Subscription $subscription): ?Plan
    {
        if ($subscription->relationLoaded('plan') && $subscription->plan?->id !== $subscription->plan_id) {
            $subscription->unsetRelation('plan');
        }

        return $subscription->plan;
    }

    private function planMonthlyQuota(Plan $plan): int
    {
        $plan->loadMissing('features');

        return max(0, (int) $plan->featureValue(FeatureKey::AiMonthlyCredits));
    }

    /**
     * Compra avulsa de créditos — adiciona ao balance permanente (não expira).
     */
    public function purchaseCredits(
        string $entityId,
        int $amount,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Purchase)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);
            $wallet->balance += $amount;
            $wallet->lifetime_purchased += $amount;
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Purchase,
                provider: null,
                amount: $amount,
                subscriptionId: $subscriptionId,
                description: $description ?? 'Compra avulsa de créditos IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], ['kind' => 'purchased_balance']),
            );
        });
    }

    /**
     * Estorno administrativo de uma compra creditada (debita do balance + lifetime).
     * Permite saldo negativo se o cliente já consumiu — registro contábil correto.
     */
    public function revokePurchaseCredits(
        string $entityId,
        int $amount,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Adjustment)) {
                return $existing;
            }

            $wallet                     = $this->lockWallet($entityId);
            $wallet->balance            = max(0, $wallet->balance - $amount);
            $wallet->lifetime_purchased = max(0, $wallet->lifetime_purchased - $amount);
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Adjustment,
                provider: null,
                amount: -$amount,
                subscriptionId: $subscriptionId,
                description: $description ?? 'Estorno administrativo de compra de créditos IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], ['adjustment_reason' => 'purchase_refund']),
            );
        });
    }

    /**
     * Ajuste manual administrativo (positivo ou negativo) no balance comprado.
     */
    public function adjust(
        string $entityId,
        int $delta,
        string $description,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        if ($delta === 0) {
            throw new InvalidArgumentException('Ajuste manual com delta zero é inválido.');
        }

        return DB::transaction(function () use (
            $entityId,
            $delta,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Adjustment)) {
                return $existing;
            }

            $wallet          = $this->lockWallet($entityId);
            $wallet->balance = max(0, $wallet->balance + $delta);
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Adjustment,
                provider: null,
                amount: $delta,
                description: $description,
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], ['adjustment_reason' => 'manual']),
            );
        });
    }

    // ─── Operações de execução (reserva / consumo / liberação) ────────────────

    /**
     * Reserva créditos antes de uma execução de IA. Usa a COTA primeiro, depois balance.
     */
    public function reserve(
        string $entityId,
        int $amount,
        ?string $aiRunId = null,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        // Janela da franquia virou e o agendador ainda não rodou: concede antes de reservar.
        $this->grantDueQuotaWindow($entityId);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $aiRunId,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Reserve)) {
                return $existing;
            }

            $wallet    = $this->lockWallet($entityId);
            $available = $wallet->totalAvailable();

            if ($available < $amount) {
                throw new InsufficientAiCreditsException(
                    requested: $amount,
                    available: $available,
                );
            }

            // Consome cota primeiro (que vai expirar), depois balance.
            $fromQuota   = min($wallet->quotaRemaining(), $amount);
            $fromBalance = $amount - $fromQuota;

            if ($fromQuota > 0) {
                $wallet->monthly_quota_used += $fromQuota;
            }

            if ($fromBalance > 0) {
                $wallet->balance -= $fromBalance;
                $wallet->reserved_balance += $fromBalance;
            }

            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Reserve,
                provider: null,
                amount: -$amount,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Reserva de créditos para execução de IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], [
                    'reserved_amount' => $amount,
                    'from_quota'      => $fromQuota,
                    'from_balance'    => $fromBalance,
                    // Janela da cota usada: devolução depois da virada não
                    // volta para a janela nova (cota é use-or-lose).
                    'quota_window_ends_at' => $wallet->quota_period_ends_at?->toAtomString(),
                ]),
            );
        });
    }

    /**
     * Confirma o consumo definitivo de uma reserva.
     *
     * Liquida pela origem gravada na reserva do run (`ai_run_id`): o consumo
     * sai primeiro da parte da cota (que expira) e só depois da parte do
     * saldo comprado — só essa baixa o reserved_balance, e nunca o de outras
     * execuções. Sem reserva registrada para o run, baixa do reservado do
     * saldo comprado até o que existe.
     *
     * Recebe `provider` opcionalmente — quando informado, grava no ledger
     * (analytics) e incrementa o contador lifetime_consumed_<provider>.
     */
    public function consumeReservation(
        string $entityId,
        int $amount,
        ?AiProvider $provider = null,
        ?string $aiRunId = null,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $provider,
            $aiRunId,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Consume)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);
            $open   = $this->openReservation($wallet, $aiRunId);

            if ($open !== null) {
                $fromQuota   = min($amount, $open['quota']);
                $fromBalance = min($amount - $fromQuota, $open['balance']);
            } else {
                $fromBalance = min($amount, (int) $wallet->reserved_balance);
                $fromQuota   = $amount - $fromBalance;
            }

            // A parte da cota já foi contada em monthly_quota_used na reserva.
            $wallet->reserved_balance = max(0, (int) $wallet->reserved_balance - $fromBalance);
            $wallet->lifetime_consumed += $amount;

            if ($provider !== null) {
                $col            = "lifetime_consumed_{$provider->value}";
                $wallet->{$col} = (int) $wallet->{$col} + $amount;
            }

            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Consume,
                provider: $provider,
                amount: 0,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Consumo definitivo de créditos reservados.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], [
                    'consumed_from_reservation' => $amount,
                    'reserved_delta'            => $fromBalance,
                    'from_quota'                => $fromQuota,
                    'from_balance'              => $fromBalance,
                ]),
            );
        });
    }

    /**
     * Libera uma reserva de volta ao saldo disponível, pela origem gravada
     * na reserva do run: como o consumo sai primeiro da cota, a sobra volta
     * primeiro ao saldo comprado (crédito comprado nunca vira cota que
     * expira) e só depois à cota — e só se a janela da cota ainda for a da
     * reserva; virada a janela, essa parte se perde (use-or-lose).
     */
    public function releaseReservation(
        string $entityId,
        int $amount,
        ?string $aiRunId = null,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $aiRunId,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Release)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);
            $open   = $this->openReservation($wallet, $aiRunId);

            if ($open !== null) {
                $toBalance  = min($amount, $open['balance']);
                $quotaPart  = min($amount - $toBalance, $open['quota']);
                $sameWindow = ! $wallet->quotaExpired()
                    && ($open['window'] === null || $open['window'] === $wallet->quota_period_ends_at?->toAtomString());
            } else {
                $toBalance  = min($amount, (int) $wallet->reserved_balance);
                $quotaPart  = min($amount - $toBalance, $wallet->quotaExpired() ? 0 : (int) $wallet->monthly_quota_used);
                $sameWindow = true;
            }

            if ($toBalance + $quotaPart < $amount) {
                throw new InvalidArgumentException(
                    'Tentativa de liberação maior que o reservado. Reservado em aberto: ' . ($toBalance + $quotaPart) . "; solicitado: {$amount}.",
                );
            }

            $toQuota   = $sameWindow ? min($quotaPart, (int) $wallet->monthly_quota_used) : 0;
            $forfeited = $quotaPart - $toQuota;

            $wallet->monthly_quota_used -= $toQuota;
            $wallet->reserved_balance = max(0, (int) $wallet->reserved_balance - $toBalance);
            $wallet->balance += $toBalance;
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Release,
                provider: null,
                amount: $amount,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Liberação de reserva de créditos.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], [
                    'released_amount' => $amount,
                    'to_quota'        => $toQuota,
                    'to_balance'      => $toBalance,
                    'quota_forfeited' => $forfeited,
                ]),
            );
        });
    }

    /**
     * Estorno de créditos já consumidos — devolve ao balance comprado.
     */
    public function refund(
        string $entityId,
        int $amount,
        ?string $aiRunId = null,
        ?string $subscriptionId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use (
            $entityId,
            $amount,
            $aiRunId,
            $subscriptionId,
            $description,
            $idempotencyKey,
            $createdBy,
            $metadata,
        ): AiCreditLedgerEntry {
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Refund)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);
            $wallet->balance += $amount;
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Refund,
                provider: null,
                amount: $amount,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Estorno de créditos IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: $metadata,
            );
        });
    }

    /**
     * Retorna saldo agregado + breakdown analítico de consumo por provedor.
     *
     * @return array{
     *     available:int,
     *     balance:int,
     *     quota_remaining:int,
     *     quota_total:int,
     *     quota_used:int,
     *     quota_expired:bool,
     *     quota_period_ends_at:?string,
     *     reserved:int,
     *     total:int,
     *     lifetime_purchased:int,
     *     lifetime_consumed:int,
     *     lifetime_quota_granted:int,
     *     consumed_by_provider: array<string, int>
     * }
     */
    public function balance(string $entityId): array
    {
        // Mesmo saldo que a reserva veria: janela vencida recebe a vigente antes.
        $this->grantDueQuotaWindow($entityId);

        $wallet = AiCreditWallet::query()->firstOrCreate(
            ['entity_id' => $entityId],
            $this->emptyWalletColumns(),
        );

        $consumedByProvider = [];

        foreach (AiProvider::cases() as $provider) {
            $consumedByProvider[$provider->value] = $wallet->lifetimeConsumedFor($provider);
        }

        $quotaRemaining = $wallet->quotaRemaining();
        $balance        = (int) $wallet->balance;
        $reserved       = (int) $wallet->reserved_balance;
        $available      = $quotaRemaining + $balance;

        return [
            'available'              => $available,
            'balance'                => $balance,
            'quota_remaining'        => $quotaRemaining,
            'quota_total'            => (int) $wallet->monthly_quota,
            'quota_used'             => (int) $wallet->monthly_quota_used,
            'quota_expired'          => $wallet->quotaExpired(),
            'quota_period_ends_at'   => $wallet->quota_period_ends_at?->toAtomString(),
            'reserved'               => $reserved,
            'total'                  => $available + $reserved,
            'lifetime_purchased'     => (int) $wallet->lifetime_purchased,
            'lifetime_consumed'      => (int) $wallet->lifetime_consumed,
            'lifetime_quota_granted' => (int) $wallet->monthly_quota_lifetime_granted,
            'consumed_by_provider'   => $consumedByProvider,
        ];
    }

    /**
     * Saneia o reserved_balance: ele deve ser a soma das partes do saldo
     * comprado reservadas por execuções ainda em andamento. A sobra
     * ("Reservados" sem execução — liquidações antigas devolviam à cota o que
     * tinha saído do saldo comprado) volta ao saldo comprado, com registro no
     * ledger. Sem `$apply`, só calcula.
     *
     * @return array{entity_id:string, reserved:int, expected:int, phantom:int, applied:bool}
     */
    public function reconcileReservedBalance(string $entityId, bool $apply = false): array
    {
        return DB::transaction(function () use ($entityId, $apply): array {
            $wallet = AiCreditWallet::query()->where('entity_id', $entityId)->lockForUpdate()->first();

            if (! $wallet) {
                return ['entity_id' => $entityId, 'reserved' => 0, 'expected' => 0, 'phantom' => 0, 'applied' => false];
            }

            $openRunIds = AiRun::query()
                ->withoutGlobalScopes()
                ->where('entity_id', $entityId)
                ->whereIn('status', [
                    AiRunStatus::Pending->value,
                    AiRunStatus::Reserved->value,
                    AiRunStatus::Running->value,
                ])
                ->pluck('id');

            $expected = 0;

            foreach ($openRunIds as $runId) {
                $expected += $this->openReservation($wallet, (string) $runId)['balance'] ?? 0;
            }

            $reserved = (int) $wallet->reserved_balance;
            $phantom  = max(0, $reserved - $expected);
            $applied  = false;

            if ($apply && $phantom > 0) {
                $wallet->reserved_balance = $reserved - $phantom;
                $wallet->balance += $phantom;
                $wallet->save();

                $this->createLedgerEntry(
                    wallet: $wallet,
                    type: AiLedgerEntryType::Adjustment,
                    provider: null,
                    amount: $phantom,
                    description: 'Créditos reservados sem execução em andamento devolvidos ao saldo comprado.',
                    metadata: [
                        'adjustment_reason' => 'reserved_balance_reconcile',
                        'reserved_before'   => $reserved,
                        'expected_reserved' => $expected,
                    ],
                );

                $applied = true;
            }

            return [
                'entity_id' => $entityId,
                'reserved'  => $reserved,
                'expected'  => $expected,
                'phantom'   => $phantom,
                'applied'   => $applied,
            ];
        });
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Quanto da reserva de um run ainda está em aberto, por origem (cota ×
     * saldo comprado), lido do ledger do próprio run: reserva menos consumos
     * e liberações já feitos. Null sem reserva registrada para o run.
     *
     * @return array{quota:int, balance:int, window:?string}|null
     */
    private function openReservation(AiCreditWallet $wallet, ?string $aiRunId): ?array
    {
        if (blank($aiRunId)) {
            return null;
        }

        $entries = AiCreditLedgerEntry::query()
            ->where('entity_id', $wallet->entity_id)
            ->where('ai_run_id', $aiRunId)
            ->whereIn('type', [
                AiLedgerEntryType::Reserve->value,
                AiLedgerEntryType::Consume->value,
                AiLedgerEntryType::Release->value,
            ])
            ->get(['type', 'amount', 'metadata']);

        $reserve = $entries->first(fn (AiCreditLedgerEntry $entry) => $entry->type === AiLedgerEntryType::Reserve);

        if (! $reserve) {
            return null;
        }

        $quota   = (int) data_get($reserve->metadata, 'from_quota', 0);
        $balance = (int) data_get($reserve->metadata, 'from_balance', abs((int) $reserve->amount) - $quota);

        foreach ($entries as $entry) {
            $meta = (array) $entry->metadata;

            if ($entry->type === AiLedgerEntryType::Consume) {
                // Consumos antigos só registravam reserved_delta (parte do saldo comprado).
                $consumed    = (int) ($meta['consumed_from_reservation'] ?? 0);
                $fromBalance = (int) ($meta['from_balance'] ?? $meta['reserved_delta'] ?? 0);
                $quota -= (int) ($meta['from_quota'] ?? max(0, $consumed - $fromBalance));
                $balance -= $fromBalance;
            } elseif ($entry->type === AiLedgerEntryType::Release) {
                $quota -= (int) ($meta['to_quota'] ?? 0) + (int) ($meta['quota_forfeited'] ?? 0);
                $balance -= (int) ($meta['to_balance'] ?? 0);
            }
        }

        $window = data_get($reserve->metadata, 'quota_window_ends_at');

        return [
            'quota'   => max(0, $quota),
            'balance' => max(0, $balance),
            'window'  => is_string($window) ? $window : null,
        ];
    }

    private function lockWallet(string $entityId): AiCreditWallet
    {
        $wallet = AiCreditWallet::query()
            ->where('entity_id', $entityId)
            ->lockForUpdate()
            ->first();

        if ($wallet) {
            return $wallet;
        }

        try {
            AiCreditWallet::query()->create(array_merge(
                ['entity_id' => $entityId],
                $this->emptyWalletColumns(),
            ));
        } catch (QueryException) {
            // Outra transação pode ter criado a carteira entre o select e o insert.
        }

        return AiCreditWallet::query()
            ->where('entity_id', $entityId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array<string,int|null>
     */
    private function emptyWalletColumns(): array
    {
        return [
            'balance'                        => 0,
            'reserved_balance'               => 0,
            'lifetime_purchased'             => 0,
            'lifetime_consumed'              => 0,
            'monthly_quota'                  => 0,
            'monthly_quota_used'             => 0,
            'quota_period_ends_at'           => null,
            'monthly_quota_lifetime_granted' => 0,
            'lifetime_consumed_openai'       => 0,
            'lifetime_consumed_anthropic'    => 0,
            'lifetime_consumed_gemini'       => 0,
        ];
    }

    private function createLedgerEntry(
        AiCreditWallet $wallet,
        AiLedgerEntryType $type,
        ?AiProvider $provider,
        int $amount,
        ?string $subscriptionId = null,
        ?string $aiRunId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        return AiCreditLedgerEntry::query()->create([
            'entity_id'       => $wallet->entity_id,
            'wallet_id'       => $wallet->id,
            'subscription_id' => $subscriptionId,
            'ai_run_id'       => $aiRunId,
            'type'            => $type->value,
            'provider'        => $provider?->value,
            'amount'          => $amount,
            'balance_after'   => (int) $wallet->balance,
            'description'     => $description,
            'metadata'        => $metadata,
            'idempotency_key' => $idempotencyKey,
            'created_by'      => $createdBy,
        ]);
    }

    private function findIdempotentEntry(
        ?string $idempotencyKey,
        string $entityId,
        AiLedgerEntryType $type,
    ): ?AiCreditLedgerEntry {
        if (blank($idempotencyKey)) {
            return null;
        }

        $existing = AiCreditLedgerEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        if ($existing->entity_id !== $entityId || $existing->type !== $type) {
            throw new RuntimeException("Conflito de idempotência para a chave [{$idempotencyKey}].");
        }

        return $existing;
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('O valor de créditos deve ser maior que zero.');
        }
    }
}
