<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiCreditPurchase;
use App\Enums\AI\AiCreditPurchaseStatus;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiCreditPurchaseService
{
    public function __construct(
        private readonly AiCreditWalletService $walletService,
    ) {
    }

    /**
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
        ?string $subscriptionId = null,
        ?string $requestedBy = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): AiCreditPurchase {
        $package = $this->resolvePackage($packageCode);
        $idempotencyKey ??= 'ai-credit-purchase:' . (string) Str::uuid();

        return DB::transaction(function () use (
            $entityId,
            $package,
            $subscriptionId,
            $requestedBy,
            $idempotencyKey,
            $metadata,
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
                'credits'         => (int) $package['credits'],
                'amount_cents'    => (int) $package['price_cents'],
                'currency'        => (string) config('ai.credit_purchases.currency', 'BRL'),
                'status'          => AiCreditPurchaseStatus::PendingPayment->value,
                'description'     => __('ai.credit_purchase_description', [
                    'credits' => (int) $package['credits'],
                ]),
                'metadata' => array_merge($metadata ?? [], [
                    'source'  => 'panel_ai_ui',
                    'package' => $this->presentPackage($package),
                ]),
                'idempotency_key' => $idempotencyKey,
            ]);
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
                amount: (int) $locked->credits,
                subscriptionId: $locked->subscription_id ? (string) $locked->subscription_id : null,
                description: $locked->description,
                idempotencyKey: "ai-credit-purchase:{$locked->id}:credit",
                createdBy: $createdBy,
                metadata: [
                    'ai_credit_purchase_id' => (string) $locked->id,
                    'package_code'          => (string) $locked->package_code,
                    'amount_cents'          => (int) $locked->amount_cents,
                    'currency'              => (string) $locked->currency,
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
     * @return array<string, mixed>
     */
    public function presentPurchase(AiCreditPurchase $purchase): array
    {
        $status = $purchase->status instanceof AiCreditPurchaseStatus
            ? $purchase->status
            : AiCreditPurchaseStatus::tryFrom((string) $purchase->status);

        return [
            'id'               => (string) $purchase->id,
            'package_code'     => (string) $purchase->package_code,
            'package_name'     => __("ai.credit_packages.{$purchase->package_code}.name"),
            'credits'          => (int) $purchase->credits,
            'amount_cents'     => (int) $purchase->amount_cents,
            'amount_formatted' => $this->formatMoney((int) $purchase->amount_cents, (string) $purchase->currency),
            'currency'         => (string) $purchase->currency,
            'status'           => $status?->value ?? (string) $purchase->status,
            'status_label'     => $status ? __("ai.credit_purchase_status.{$status->value}") : (string) $purchase->status,
            'created_at'       => $purchase->created_at?->format('d/m/Y H:i'),
            'credited_at'      => $purchase->credited_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePackage(string $packageCode): array
    {
        $packages = config('ai.credit_purchases.packages', []);

        foreach ($packages as $package) {
            if (($package['code'] ?? null) === $packageCode) {
                if ((int) ($package['credits'] ?? 0) <= 0 || (int) ($package['price_cents'] ?? 0) <= 0) {
                    throw new DomainException('ai_credit_package_invalid');
                }

                return $package;
            }
        }

        throw new DomainException('ai_credit_package_not_found');
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>
     */
    private function presentPackage(array $package): array
    {
        $credits    = (int) ($package['credits'] ?? 0);
        $priceCents = (int) ($package['price_cents'] ?? 0);
        $currency   = (string) config('ai.credit_purchases.currency', 'BRL');

        return [
            'code'                 => (string) ($package['code'] ?? ''),
            'name'                 => __('ai.credit_packages.' . ($package['code'] ?? '') . '.name'),
            'description'          => __('ai.credit_packages.' . ($package['code'] ?? '') . '.description'),
            'credits'              => $credits,
            'price_cents'          => $priceCents,
            'price_formatted'      => $this->formatMoney($priceCents, $currency),
            'unit_price_formatted' => $credits > 0
                ? $this->formatMoney((int) ceil($priceCents / $credits), $currency)
                : $this->formatMoney(0, $currency),
            'currency' => $currency,
            'featured' => (bool) ($package['featured'] ?? false),
        ];
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $value = number_format($cents / 100, 2, ',', '.');

        if ($currency === 'BRL') {
            return 'R$ ' . $value;
        }

        return $currency . ' ' . $value;
    }
}
