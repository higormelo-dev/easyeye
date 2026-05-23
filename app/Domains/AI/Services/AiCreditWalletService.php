<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\AiCreditLedgerEntry;
use App\Domains\AI\Models\AiCreditWallet;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\FeatureKey;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AiCreditWalletService
{
    public function grantMonthlyCredits(
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
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Grant)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);
            $wallet->balance += $amount;
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Grant,
                amount: $amount,
                subscriptionId: $subscriptionId,
                description: $description ?? 'Concessão mensal de créditos IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: $metadata,
            );
        });
    }

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
                amount: $amount,
                subscriptionId: $subscriptionId,
                description: $description ?? 'Compra avulsa de créditos IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: $metadata,
            );
        });
    }

    /**
     * Concede a franquia mensal de créditos IA do plano associado à assinatura.
     *
     * Idempotente por ciclo: usa `ends_at` da subscription (ou 'lifetime') como
     * chave de período, garantindo que múltiplas chamadas no mesmo ciclo não dupliquem
     * o grant. Cada renovação que avança `ends_at` produz uma nova chave e novo grant.
     *
     * Retorna null quando o plano não concede créditos (feature ausente ou valor zero).
     */
    public function grantMonthlyCreditsForSubscription(Subscription $subscription): ?AiCreditLedgerEntry
    {
        $plan = $subscription->plan;

        if (!$plan) {
            return null;
        }

        $plan->loadMissing('features');

        $rawValue = $plan->featureValue(FeatureKey::AiMonthlyCredits);
        $amount   = (int) $rawValue;

        if ($amount <= 0) {
            return null;
        }

        $periodKey      = $subscription->ends_at?->toDateString() ?? 'lifetime';
        $idempotencyKey = "ai-monthly-grant-{$subscription->id}-{$periodKey}";

        return $this->grantMonthlyCredits(
            entityId: $subscription->entity_id,
            amount: $amount,
            subscriptionId: $subscription->id,
            description: "Franquia mensal de créditos IA do plano {$plan->name}.",
            idempotencyKey: $idempotencyKey,
            metadata: [
                'plan_id'    => $plan->id,
                'plan_slug'  => $plan->slug,
                'period_key' => $periodKey,
                'source'     => 'subscription_cycle',
            ],
        );
    }

    /**
     * Estorna créditos de uma compra já creditada (refund administrativo do Manager).
     *
     * Debita o `amount` do balance e do lifetime_purchased — ao contrário do
     * `refund()` (que credita devolução de consumo). Cria entrada do tipo
     * Adjustment com metadata['adjustment_reason']='purchase_refund'.
     *
     * Permite saldo negativo: se a clínica já consumiu parte dos créditos
     * comprados, o estorno fica em débito. Isso é registro contábil correto —
     * a área financeira do SaaS decide se cobra o débito ou absorve. Não
     * podemos REVERTER consumo (PDFs já foram emitidos, dados gravados em
     * audit_logs); só registramos o estorno e marcamos a wallet.
     *
     * Idempotente via idempotencyKey.
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

            $wallet = $this->lockWallet($entityId);
            $wallet->balance -= $amount;
            $wallet->lifetime_purchased = max(0, $wallet->lifetime_purchased - $amount);
            $wallet->save();

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Adjustment,
                amount: -$amount,
                subscriptionId: $subscriptionId,
                description: $description ?? 'Estorno administrativo de compra de créditos IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: array_merge($metadata ?? [], ['adjustment_reason' => 'purchase_refund']),
            );
        });
    }

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

            $wallet = $this->lockWallet($entityId);

            if ($wallet->balance < $amount) {
                throw new InsufficientAiCreditsException(
                    requested: $amount,
                    available: (int) $wallet->balance,
                );
            }

            $wallet->balance -= $amount;
            $wallet->reserved_balance += $amount;
            $wallet->save();

            $entryMetadata = array_merge($metadata ?? [], ['reserved_amount' => $amount]);

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Reserve,
                amount: -$amount,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Reserva de créditos para execução de IA.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: $entryMetadata,
            );
        });
    }

    public function consumeReservation(
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
            if ($existing = $this->findIdempotentEntry($idempotencyKey, $entityId, AiLedgerEntryType::Consume)) {
                return $existing;
            }

            $wallet = $this->lockWallet($entityId);

            if ($wallet->reserved_balance < $amount) {
                throw new \InvalidArgumentException(
                    "Tentativa de consumo maior que o reservado. Reservado: {$wallet->reserved_balance}; solicitado: {$amount}."
                );
            }

            $wallet->reserved_balance -= $amount;
            $wallet->lifetime_consumed += $amount;
            $wallet->save();

            $entryMetadata = array_merge($metadata ?? [], ['consumed_from_reservation' => $amount]);

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Consume,
                amount: 0,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Consumo definitivo de créditos reservados.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: $entryMetadata,
            );
        });
    }

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

            if ($wallet->reserved_balance < $amount) {
                throw new \InvalidArgumentException(
                    "Tentativa de liberação maior que o reservado. Reservado: {$wallet->reserved_balance}; solicitado: {$amount}."
                );
            }

            $wallet->reserved_balance -= $amount;
            $wallet->balance += $amount;
            $wallet->save();

            $entryMetadata = array_merge($metadata ?? [], ['released_amount' => $amount]);

            return $this->createLedgerEntry(
                wallet: $wallet,
                type: AiLedgerEntryType::Release,
                amount: $amount,
                subscriptionId: $subscriptionId,
                aiRunId: $aiRunId,
                description: $description ?? 'Liberação de reserva de créditos.',
                idempotencyKey: $idempotencyKey,
                createdBy: $createdBy,
                metadata: $entryMetadata,
            );
        });
    }

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
     * @return array{available:int,reserved:int,total:int,lifetime_purchased:int,lifetime_consumed:int}
     */
    public function balance(string $entityId): array
    {
        $wallet = AiCreditWallet::query()->firstOrCreate(
            ['entity_id' => $entityId],
            [
                'balance'            => 0,
                'reserved_balance'   => 0,
                'lifetime_purchased' => 0,
                'lifetime_consumed'  => 0,
            ]
        );

        $available = (int) $wallet->balance;
        $reserved  = (int) $wallet->reserved_balance;

        return [
            'available'          => $available,
            'reserved'           => $reserved,
            'total'              => $available + $reserved,
            'lifetime_purchased' => (int) $wallet->lifetime_purchased,
            'lifetime_consumed'  => (int) $wallet->lifetime_consumed,
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
            AiCreditWallet::query()->create([
                'entity_id'          => $entityId,
                'balance'            => 0,
                'reserved_balance'   => 0,
                'lifetime_purchased' => 0,
                'lifetime_consumed'  => 0,
            ]);
        } catch (QueryException) {
            // Outra transação pode ter criado a carteira entre o select e o insert.
        }

        return AiCreditWallet::query()
            ->where('entity_id', $entityId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function createLedgerEntry(
        AiCreditWallet $wallet,
        AiLedgerEntryType $type,
        int $amount,
        ?string $subscriptionId = null,
        ?string $aiRunId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?array $metadata = null,
    ): AiCreditLedgerEntry {
        return AiCreditLedgerEntry::query()->create([
            'entity_id'        => $wallet->entity_id,
            'wallet_id'        => $wallet->id,
            'subscription_id'  => $subscriptionId,
            'ai_run_id'        => $aiRunId,
            'type'             => $type->value,
            'amount'           => $amount,
            'balance_after'    => (int) $wallet->balance,
            'description'      => $description,
            'metadata'         => $metadata,
            'idempotency_key'  => $idempotencyKey,
            'created_by'       => $createdBy,
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

        if (!$existing) {
            return null;
        }

        if ($existing->entity_id !== $entityId || $existing->type !== $type) {
            throw new \RuntimeException("Conflito de idempotência para a chave [{$idempotencyKey}].");
        }

        return $existing;
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('O valor de créditos deve ser maior que zero.');
        }
    }
}
