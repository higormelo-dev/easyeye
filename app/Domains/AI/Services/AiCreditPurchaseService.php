<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\{AiCreditPurchase, AiCreditWallet};
use App\Enums\AI\{AiCreditPurchaseStatus, AiProvider};
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\{DB, Lang};
use Illuminate\Support\Str;

class AiCreditPurchaseService
{
    public function __construct(
        private readonly AiCreditWalletService $walletService,
        private readonly AiPricingSettings $pricingSettings,
    ) {
    }

    /**
     * Pacotes oferecidos à clínica (contrato público: só valores em centavos e limites do personalizado).
     *
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        $packages = config('ai.credit_purchases.packages', []);

        return collect($packages)
            ->map(fn (array $package): array => $this->presentPackage($package))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentForEntity(string $entityId, int $limit = 5): array
    {
        return AiCreditPurchase::query()
            ->where('entity_id', $entityId)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AiCreditPurchase $purchase): array => $this->presentPurchase($purchase))
            ->values()
            ->all();
    }

    public function createPendingPurchase(
        string $entityId,
        string $packageCode,
        ?AiProvider $provider = null,
        ?string $subscriptionId = null,
        ?string $requestedBy = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
        string $source = 'panel_ai_ui',
        ?int $customAmountCents = null,
    ): AiCreditPurchase {
        // O valor é SEMPRE recalculado/validado aqui (pacote fixo: o da config; personalizado: dentro dos limites do servidor).
        $package = $this->resolvePackage($packageCode, $customAmountCents);
        $value   = (int) $package['value_cents'];
        $idempotencyKey ??= 'ai-credit-purchase:' . (string) Str::uuid();

        return DB::transaction(function () use (
            $entityId,
            $package,
            $value,
            $provider,
            $subscriptionId,
            $requestedBy,
            $idempotencyKey,
            $metadata,
            $source,
        ): AiCreditPurchase {
            $existing = AiCreditPurchase::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ((string) $existing->entity_id !== $entityId) {
                    throw new DomainException('ai_credit_purchase_idempotency_conflict');
                }

                return $existing;
            }

            return AiCreditPurchase::query()->create([
                'entity_id'       => $entityId,
                'subscription_id' => $subscriptionId,
                'requested_by'    => $requestedBy,
                'package_code'    => (string) $package['code'],
                'provider'        => $provider?->value,
                'credits'         => null,
                // R$ pagos = R$ de saldo (1:1, sem bônus): a compra credita exatamente o valor cobrado.
                'wallet_credit_cents' => $value,
                'amount_cents'        => $value,
                'currency'            => (string) config('ai.credit_purchases.currency', 'BRL'),
                'status'              => AiCreditPurchaseStatus::PendingPayment->value,
                'description'         => 'Pacote de saldo de IA (' . Money::formatBrl($value) . ')',
                'metadata'            => array_merge($metadata ?? [], [
                    'source'  => $source,
                    'package' => $this->presentPackage($package),
                ]),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Cria uma compra manual (cortesia/ajuste administrativo) e credita imediatamente
     * no provedor especificado. Usado pelo manager para conceder créditos sem passar
     * por gateway de pagamento.
     *
     * O `packageCode` aqui é livre — não precisa corresponder a um pacote do config.
     * Útil para representar a operação no histórico ("manual_courtesy", "manual_internal").
     */
    public function createManualPurchase(
        string $entityId,
        int $walletCreditCents,
        string $reason,
        string $createdBy,
        ?AiProvider $provider = null,
        int $amountCents = 0,
        string $packageCode = 'manual',
        ?string $subscriptionId = null,
        ?array $metadata = null,
    ): AiCreditPurchase {
        if ($walletCreditCents <= 0) {
            throw new DomainException('ai_credit_purchase_invalid_credits');
        }

        $idempotencyKey = 'ai-credit-purchase:manual:' . (string) Str::uuid();

        return DB::transaction(function () use (
            $entityId,
            $provider,
            $walletCreditCents,
            $reason,
            $createdBy,
            $amountCents,
            $packageCode,
            $subscriptionId,
            $metadata,
            $idempotencyKey,
        ): AiCreditPurchase {
            $purchase = AiCreditPurchase::query()->create([
                'entity_id'           => $entityId,
                'subscription_id'     => $subscriptionId,
                'requested_by'        => $createdBy,
                'package_code'        => $packageCode,
                'provider'            => $provider?->value, // opcional — metadata, não controla saldo
                'credits'             => null,
                'wallet_credit_cents' => $walletCreditCents,
                'amount_cents'        => max(0, $amountCents),
                'currency'            => (string) config('ai.credit_purchases.currency', 'BRL'),
                'status'              => AiCreditPurchaseStatus::PendingPayment->value,
                'description'         => "Saldo manual: {$reason}",
                'metadata'            => array_merge($metadata ?? [], [
                    'source'        => 'manager_manual',
                    'manual_reason' => $reason,
                    'created_by'    => $createdBy,
                    'provider_hint' => $provider?->value,
                ]),
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->creditPaidPurchase($purchase, $createdBy);
        });
    }

    /**
     * Cancela um pedido pendente (cliente desistiu OU admin SaaS rejeitou).
     * Idempotente: chamadas repetidas em pedidos já cancelados retornam o mesmo
     * registro. Pedidos já creditados/refundados/com falha lançam exceção.
     */
    public function cancelPurchase(
        AiCreditPurchase $purchase,
        ?string $reason = null,
        ?string $createdBy = null,
    ): AiCreditPurchase {
        return DB::transaction(function () use ($purchase, $reason, $createdBy): AiCreditPurchase {
            $locked = AiCreditPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === AiCreditPurchaseStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== AiCreditPurchaseStatus::PendingPayment) {
                throw new DomainException('ai_credit_purchase_not_cancellable');
            }

            $locked->update([
                'status'       => AiCreditPurchaseStatus::Cancelled->value,
                'cancelled_at' => now(),
                'metadata'     => array_merge((array) $locked->metadata, [
                    'cancel_reason' => $reason,
                    'cancelled_by'  => $createdBy,
                ]),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Marca pedido como falha de pagamento (gateway recusou).
     * Separado de `cancelled` para métricas de funil — `failed` indica problema
     * de checkout/gateway, `cancelled` indica desistência do cliente.
     */
    public function markAsFailed(
        AiCreditPurchase $purchase,
        ?string $reason = null,
        ?string $createdBy = null,
    ): AiCreditPurchase {
        return DB::transaction(function () use ($purchase, $reason, $createdBy): AiCreditPurchase {
            $locked = AiCreditPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === AiCreditPurchaseStatus::Failed) {
                return $locked;
            }

            if ($locked->status !== AiCreditPurchaseStatus::PendingPayment) {
                throw new DomainException('ai_credit_purchase_not_failable');
            }

            $locked->update([
                'status'    => AiCreditPurchaseStatus::Failed->value,
                'failed_at' => now(),
                'metadata'  => array_merge((array) $locked->metadata, [
                    'failure_reason' => $reason,
                    'failed_by'      => $createdBy,
                ]),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Estorna créditos de uma compra já creditada.
     *
     * Comportamento crítico: se a clínica já consumiu parte dos créditos,
     * o balance pode ficar NEGATIVO. Isso é registro contábil correto —
     * não podemos reverter PDFs gerados, dados clínicos persistidos ou
     * audit logs. O Manager precisa decidir se cobra o débito ou absorve.
     *
     * Idempotente via campo `refunded_at`.
     */
    public function refundPurchase(
        AiCreditPurchase $purchase,
        ?string $reason = null,
        ?string $createdBy = null,
    ): AiCreditPurchase {
        return DB::transaction(function () use ($purchase, $reason, $createdBy): AiCreditPurchase {
            $locked = AiCreditPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === AiCreditPurchaseStatus::Refunded) {
                return $locked;
            }

            if ($locked->status !== AiCreditPurchaseStatus::Credited) {
                throw new DomainException('ai_credit_purchase_not_refundable');
            }

            $this->walletService->revokePurchaseCredits(
                entityId: (string) $locked->entity_id,
                amount: $this->walletCreditOf($locked),
                subscriptionId: $locked->subscription_id ? (string) $locked->subscription_id : null,
                description: "Estorno administrativo da compra {$locked->id}.",
                idempotencyKey: "ai-credit-purchase:{$locked->id}:refund",
                createdBy: $createdBy,
                metadata: [
                    'ai_credit_purchase_id' => (string) $locked->id,
                    'package_code'          => (string) $locked->package_code,
                    'amount_cents'          => (int) $locked->amount_cents,
                    'refund_reason'         => $reason,
                ],
            );

            $locked->update([
                'status'      => AiCreditPurchaseStatus::Refunded->value,
                'refunded_at' => now(),
                'metadata'    => array_merge((array) $locked->metadata, [
                    'refund_reason' => $reason,
                    'refunded_by'   => $createdBy,
                ]),
            ]);

            return $locked->fresh();
        });
    }

    public function creditPaidPurchase(AiCreditPurchase $purchase, ?string $createdBy = null): AiCreditPurchase
    {
        return DB::transaction(function () use ($purchase, $createdBy): AiCreditPurchase {
            $locked = AiCreditPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === AiCreditPurchaseStatus::Credited) {
                return $locked;
            }

            if ($locked->status !== AiCreditPurchaseStatus::PendingPayment) {
                throw new DomainException('ai_credit_purchase_not_creditable');
            }

            $ledgerEntry = $this->walletService->purchaseCredits(
                entityId: (string) $locked->entity_id,
                amount: $this->walletCreditOf($locked),
                subscriptionId: $locked->subscription_id ? (string) $locked->subscription_id : null,
                description: $locked->description,
                idempotencyKey: "ai-credit-purchase:{$locked->id}:credit",
                createdBy: $createdBy,
                metadata: [
                    'ai_credit_purchase_id' => (string) $locked->id,
                    'package_code'          => (string) $locked->package_code,
                    'provider_hint'         => $locked->provider instanceof AiProvider
                        ? $locked->provider->value
                        : ($locked->provider ? (string) $locked->provider : null),
                    'amount_cents' => (int) $locked->amount_cents,
                    'currency'     => (string) $locked->currency,
                ],
            );

            $locked->update([
                'status'                   => AiCreditPurchaseStatus::Credited->value,
                'credited_ledger_entry_id' => $ledgerEntry->id,
                'credited_at'              => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Pagamento confirmado pelo gateway (checkout): credita uma vez só. O
     * dinheiro entrou, então vale mesmo para o pedido que o manager tinha
     * cancelado ou marcado como falha enquanto o Pix/boleto seguia aberto —
     * reaberto e creditado, com o registro em metadata.reopened_by_payment.
     * Já creditado (ou estornado): nada muda.
     */
    public function creditFromGatewayPayment(AiCreditPurchase $purchase, array $paymentContext = []): AiCreditPurchase
    {
        return DB::transaction(function () use ($purchase, $paymentContext): AiCreditPurchase {
            $locked = AiCreditPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [AiCreditPurchaseStatus::Credited, AiCreditPurchaseStatus::Refunded], true)) {
                return $locked;
            }

            if (in_array($locked->status, [AiCreditPurchaseStatus::Cancelled, AiCreditPurchaseStatus::Failed], true)) {
                $locked->update([
                    'status'       => AiCreditPurchaseStatus::PendingPayment->value,
                    'cancelled_at' => null,
                    'failed_at'    => null,
                    'metadata'     => array_merge((array) $locked->metadata, [
                        'reopened_by_payment' => [
                            'previous_status' => $locked->status->value,
                            'at'              => now()->toIso8601String(),
                        ],
                    ]),
                ]);
            }

            $locked->update([
                'metadata' => array_merge((array) $locked->metadata, ['payment' => $paymentContext]),
            ]);

            return $this->creditPaidPurchase($locked, null);
        });
    }

    /**
     * Estorno ou chargeback confirmado pelo gateway: mesma regra do estorno
     * do manager (refundPurchase — o saldo pode ficar negativo). Ainda não
     * creditado: o pedido é só cancelado (nada a tirar da carteira).
     */
    public function reverseFromGateway(AiCreditPurchase $purchase, string $reason): AiCreditPurchase
    {
        $status = $purchase->fresh()?->status;

        return match ($status) {
            AiCreditPurchaseStatus::Credited       => $this->reverseRemainderFromGateway($purchase, $reason),
            AiCreditPurchaseStatus::PendingPayment => $this->cancelPurchase($purchase, $reason),
            default                                => $purchase->fresh() ?? $purchase,
        };
    }

    /**
     * Estorno total/chargeback confirmado pelo gateway de pacote creditado:
     * tira da carteira só o que ainda não saiu — créditos do pacote menos os
     * já retirados por estornos parciais anteriores (PARTIALLY_REFUNDED e
     * depois REFUNDED do restante não tiram em dobro) —, sem deixar o saldo
     * negativo. O que faltar (a clínica já usou) fica em
     * metadata.gateway_reversal.shortfall para o chamador alertar o time.
     * Idempotente: chave própria no ledger e o pedido vira estornado.
     */
    private function reverseRemainderFromGateway(AiCreditPurchase $purchase, string $reason): AiCreditPurchase
    {
        return DB::transaction(function () use ($purchase, $reason): AiCreditPurchase {
            $locked = AiCreditPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AiCreditPurchaseStatus::Credited) {
                return $locked;
            }

            $alreadyRevoked = (int) data_get($locked->metadata, 'partial_refund.revoked_cents', 0);
            $toRevoke       = max(0, $this->walletCreditOf($locked) - $alreadyRevoked);
            $wallet         = AiCreditWallet::query()->where('entity_id', $locked->entity_id)->lockForUpdate()->first();
            $available      = max(0, (int) ($wallet?->balance_cents ?? 0));
            $revoke         = min($toRevoke, $available);

            if ($revoke > 0) {
                $this->walletService->revokePurchaseCredits(
                    entityId: (string) $locked->entity_id,
                    amount: $revoke,
                    subscriptionId: $locked->subscription_id ? (string) $locked->subscription_id : null,
                    description: "Estorno da compra {$locked->id} confirmado pelo gateway.",
                    idempotencyKey: "ai-credit-purchase:{$locked->id}:gateway-reversal",
                    metadata: [
                        'ai_credit_purchase_id' => (string) $locked->id,
                        'package_code'          => (string) $locked->package_code,
                        'amount_cents'          => (int) $locked->amount_cents,
                        'refund_reason'         => $reason,
                        'already_revoked'       => $alreadyRevoked,
                    ],
                );
            }

            $locked->update([
                'status'      => AiCreditPurchaseStatus::Refunded->value,
                'refunded_at' => now(),
                'metadata'    => array_merge((array) $locked->metadata, [
                    'refund_reason'    => $reason,
                    'gateway_reversal' => [
                        'already_revoked' => $alreadyRevoked,
                        'revoked'         => $revoke,
                        'shortfall'       => $toRevoke - $revoke,
                        'at'              => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Estorno PARCIAL do pagamento de um pacote já creditado: tira da
     * carteira os créditos proporcionais ao valor devolvido (arredondado para
     * baixo), sem deixar o saldo comprado negativo. O que faltar de saldo (a
     * clínica já usou) fica registrado como `shortfall` — o chamador alerta o
     * time. Idempotente pelo total devolvido: um novo evento com o mesmo
     * total não tira nada; um total maior tira só a diferença. O pedido segue
     * creditado (só o estorno total o marca como estornado).
     *
     * @return array{target: int, revoked: int, shortfall: int}
     */
    public function revokeProportionallyFromGateway(AiCreditPurchase $purchase, float $refundedTotal, float $paidAmount, string $reason): array
    {
        return DB::transaction(function () use ($purchase, $refundedTotal, $paidAmount, $reason): array {
            $locked = AiCreditPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AiCreditPurchaseStatus::Credited || $paidAmount <= 0) {
                return ['target' => 0, 'revoked' => 0, 'shortfall' => 0];
            }

            $ratio    = max(0.0, min(1.0, $refundedTotal / $paidAmount));
            $target   = (int) floor($this->walletCreditOf($locked) * $ratio + 1e-9);
            $partial  = (array) data_get($locked->metadata, 'partial_refund', []);
            $handled  = (int) ($partial['handled_cents'] ?? 0);
            $toRevoke = $target - $handled;

            if ($toRevoke <= 0) {
                return ['target' => $target, 'revoked' => 0, 'shortfall' => 0];
            }

            $wallet  = AiCreditWallet::query()->where('entity_id', $locked->entity_id)->lockForUpdate()->first();
            $balance = max(0, (int) ($wallet?->balance_cents ?? 0));
            $revoke  = min($toRevoke, $balance);

            if ($revoke > 0) {
                $this->walletService->revokePurchaseCredits(
                    entityId: (string) $locked->entity_id,
                    amount: $revoke,
                    subscriptionId: $locked->subscription_id ? (string) $locked->subscription_id : null,
                    description: "Estorno parcial da compra {$locked->id}.",
                    idempotencyKey: "ai-credit-purchase:{$locked->id}:partial-refund:{$target}",
                    metadata: [
                        'ai_credit_purchase_id' => (string) $locked->id,
                        'package_code'          => (string) $locked->package_code,
                        'refunded_total'        => round($refundedTotal, 2),
                        'refund_reason'         => $reason,
                    ],
                );
            }

            $locked->update(['metadata' => array_merge((array) $locked->metadata, [
                'partial_refund' => [
                    'handled_cents'  => $target,
                    'revoked_cents'  => (int) ($partial['revoked_cents'] ?? 0) + $revoke,
                    'shortfall'      => (int) ($partial['shortfall'] ?? 0) + ($toRevoke - $revoke),
                    'refunded_total' => round($refundedTotal, 2),
                    'at'             => now()->toIso8601String(),
                ],
            ])]);

            return ['target' => $target, 'revoked' => $revoke, 'shortfall' => $toRevoke - $revoke];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPurchase(AiCreditPurchase $purchase): array
    {
        $status = $purchase->status instanceof AiCreditPurchaseStatus
            ? $purchase->status
            : AiCreditPurchaseStatus::tryFrom((string) $purchase->status);

        $walletCents = $purchase->wallet_credit_cents !== null ? (int) $purchase->wallet_credit_cents : (int) $purchase->amount_cents;

        return [
            'id'                      => (string) $purchase->id,
            'package_code'            => (string) $purchase->package_code,
            'package_name'            => $this->packageText((string) $purchase->package_code, 'name', ucfirst((string) $purchase->package_code)),
            'amount_cents'            => (int) $purchase->amount_cents,
            'amount_formatted'        => $this->formatMoney((int) $purchase->amount_cents, (string) $purchase->currency),
            'wallet_credit_cents'     => $walletCents,
            'wallet_credit_formatted' => $this->formatMoney($walletCents, (string) $purchase->currency),
            'currency'                => (string) $purchase->currency,
            'status'                  => $status?->value ?? (string) $purchase->status,
            'status_label'            => $status ? __("ai.credit_purchase_status.{$status->value}") : (string) $purchase->status,
            'created_at'              => $purchase->created_at?->format('d/m/Y H:i'),
            'credited_at'             => $purchase->credited_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Resolve o pacote e o VALOR a cobrar no servidor — nunca confia em preço vindo do cliente. Pacote fixo: o valor é o da
     * config (e rejeita valor informado). Personalizado: reais inteiros (passo), dentro de [mínimo, máximo] vigentes.
     *
     * @return array<string, mixed> o pacote com `value_cents` resolvido
     *
     * @throws DomainException ai_credit_package_not_found|ai_credit_package_invalid|ai_credit_custom_amount_required|ai_credit_custom_amount_invalid|ai_credit_custom_amount_not_allowed
     */
    public function resolvePackage(string $packageCode, ?int $customAmountCents = null): array
    {
        foreach (config('ai.credit_purchases.packages', []) as $package) {
            if (($package['code'] ?? null) !== $packageCode) {
                continue;
            }

            if ((bool) ($package['custom'] ?? false)) {
                if ($customAmountCents === null) {
                    throw new DomainException('ai_credit_custom_amount_required');
                }

                if (! $this->customAmountValid($customAmountCents)) {
                    throw new DomainException('ai_credit_custom_amount_invalid');
                }

                return [...$package, 'value_cents' => $customAmountCents];
            }

            if ($customAmountCents !== null) {
                throw new DomainException('ai_credit_custom_amount_not_allowed');
            }

            if ((int) ($package['value_cents'] ?? 0) <= 0) {
                throw new DomainException('ai_credit_package_invalid');
            }

            return $package;
        }

        throw new DomainException('ai_credit_package_not_found');
    }

    /** Valor do personalizado aceito: inteiro em reais cheios, entre o mínimo e o máximo vigentes. */
    public function customAmountValid(int $cents): bool
    {
        return $cents >= $this->pricingSettings->customMinCents()
            && $cents <= $this->pricingSettings->customMaxCents()
            && $cents % $this->pricingSettings->customStepCents() === 0;
    }

    /** O que a compra credita na carteira (centavos). Falha alto se a coluna nova faltar: nunca cai nas colunas antigas. */
    private function walletCreditOf(AiCreditPurchase $purchase): int
    {
        $cents = $purchase->wallet_credit_cents;

        if ($cents === null || (int) $cents <= 0) {
            throw new DomainException('ai_credit_purchase_invalid_credits');
        }

        return (int) $cents;
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>
     */
    private function presentPackage(array $package): array
    {
        $code     = (string) ($package['code'] ?? '');
        $custom   = (bool) ($package['custom'] ?? false);
        $value    = $package['value_cents'] !== null ? (int) $package['value_cents'] : null;
        $currency = (string) config('ai.credit_purchases.currency', 'BRL');

        return [
            'code'            => $code,
            'name'            => $this->packageText($code, 'name', ucfirst($code)),
            'description'     => $this->packageText($code, 'description', ''),
            'value_cents'     => $value,
            'value_formatted' => $value !== null ? $this->formatMoney($value, $currency) : null,
            'currency'        => $currency,
            'featured'        => (bool) ($package['featured'] ?? false),
            'custom'          => $custom,
            // Limites do personalizado vêm do servidor (a UI só os exibe); o servidor sempre revalida.
            'custom_min_cents'  => $custom ? $this->pricingSettings->customMinCents() : null,
            'custom_max_cents'  => $custom ? $this->pricingSettings->customMaxCents() : null,
            'custom_step_cents' => $custom ? $this->pricingSettings->customStepCents() : null,
        ];
    }

    /** Texto traduzido do pacote (ai.credit_packages.{code}.{campo}); sem a chave, usa o padrão — nunca devolve a chave crua. */
    private function packageText(string $code, string $field, string $fallback): string
    {
        $key = "ai.credit_packages.{$code}.{$field}";

        return Lang::has($key) ? (string) __($key) : $fallback;
    }

    private function formatMoney(int $cents, string $currency): string
    {
        return $currency === 'BRL' ? Money::formatBrl($cents) : $currency . ' ' . number_format($cents / 100, 2, ',', '.');
    }
}
