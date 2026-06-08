<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet};
use App\Enums\AI\{AiLedgerEntryType, AiProvider};
use App\Enums\FeatureKey;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
     * Concede a franquia mensal da subscription. Idempotente por ciclo (ends_at).
     */
    public function grantMonthlyCreditsForSubscription(Subscription $subscription): ?AiCreditLedgerEntry
    {
        $plan = $subscription->plan;

        if (! $plan) {
            return null;
        }

        $plan->loadMissing('features');

        $amount = (int) $plan->featureValue(FeatureKey::AiMonthlyCredits);

        if ($amount <= 0) {
            return null;
        }

        $endsAt = $subscription->ends_at
            ? CarbonImmutable::parse($subscription->ends_at)
            : CarbonImmutable::now()->addMonth();
        $periodKey = $endsAt->toDateString();

        return $this->grantMonthlyQuota(
            entityId: $subscription->entity_id,
            amount: $amount,
            periodEndsAt: $endsAt,
            subscriptionId: $subscription->id,
            description: "Cota mensal do plano {$plan->name}.",
            idempotencyKey: "ai-monthly-grant-{$subscription->id}-{$periodKey}",
            metadata: [
                'plan_id'    => $plan->id,
                'plan_slug'  => $plan->slug,
                'period_key' => $periodKey,
                'source'     => 'subscription_cycle',
            ],
        );
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
                ]),
            );
        });
    }

    /**
     * Confirma o consumo definitivo de uma reserva.
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

            if ($wallet->reserved_balance < $amount) {
                // Pode ter sido reservado parcialmente da cota — nesse caso
                // o consume só precisa baixar o reserved_balance até o limite.
                // Se ainda exceder, lança erro (algo desincronizado).
                if ($wallet->reserved_balance < $amount && $amount > $wallet->reserved_balance) {
                    $reservedDelta            = $wallet->reserved_balance;
                    $wallet->reserved_balance = 0;
                } else {
                    $reservedDelta = $amount;
                    $wallet->reserved_balance -= $amount;
                }
            } else {
                $reservedDelta = $amount;
                $wallet->reserved_balance -= $amount;
            }

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
                    'reserved_delta'            => $reservedDelta,
                ]),
            );
        });
    }

    /**
     * Libera uma reserva de volta ao saldo disponível. Devolve preferencialmente
     * à cota (se a cota teve uso recente) e depois ao balance comprado.
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

            // Devolve primeiro à cota se houve consumo dela neste ciclo (e não expirou).
            $toQuota = 0;

            if (! $wallet->quotaExpired() && $wallet->monthly_quota_used > 0) {
                $toQuota = min($amount, $wallet->monthly_quota_used);
                $wallet->monthly_quota_used -= $toQuota;
            }

            $toBalance = $amount - $toQuota;

            if ($toBalance > 0) {
                if ($wallet->reserved_balance < $toBalance) {
                    throw new InvalidArgumentException(
                        "Tentativa de liberação maior que o reservado. Reservado: {$wallet->reserved_balance}; solicitado adicional: {$toBalance}.",
                    );
                }
                $wallet->reserved_balance -= $toBalance;
                $wallet->balance += $toBalance;
            }

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

    // ─── Internals ────────────────────────────────────────────────────────────

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
