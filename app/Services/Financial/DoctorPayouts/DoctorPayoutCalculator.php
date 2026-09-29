<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Produção pendente do médico já com a regra aplicada e o repasse de cada
 * item — a mesma conta usada na apuração (tela/exportação) e no fechamento,
 * para que o valor conferido seja exatamente o valor fechado.
 */
final class DoctorPayoutCalculator
{
    public function __construct(
        private readonly DoctorPayoutProductionService $production,
        private readonly DoctorPayoutRuleService $rules,
        private readonly DoctorPayoutRuleResolver $resolver,
    ) {
    }

    /**
     * @return Collection<int, PayoutItemData>
     */
    public function pending(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->resolver->resolve(
            $this->rules->activeRulesFor($entityId, $doctorId),
            $this->production->pendingItems($entityId, $doctorId, $from, $to),
        );
    }

    /**
     * Totais em centavos (o fechamento compara estes números com os que o
     * usuário conferiu na prévia).
     *
     * @param Collection<int, PayoutItemData> $items
     *
     * @return array{count: int, charged_cents: int, payout_cents: int, blocking: int}
     */
    public static function totals(Collection $items): array
    {
        return [
            'count'         => $items->count(),
            'charged_cents' => (int) $items->sum(fn (PayoutItemData $item) => $item->baseCents),
            'payout_cents'  => (int) $items->sum(fn (PayoutItemData $item) => $item->payoutCents),
            'blocking'      => $items->filter(fn (PayoutItemData $item) => $item->blocksClosing())->count(),
        ];
    }
}
