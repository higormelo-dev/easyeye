<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Parcelas do regime por recebimento já com a regra aplicada — a mesma conta
 * usada na apuração (tela/exportação) e no fechamento, para que o valor
 * conferido seja exatamente o valor fechado.
 */
final class DoctorPayoutCalculator
{
    public function __construct(
        private readonly DoctorPayoutReleaseService $releases,
    ) {
    }

    /**
     * Parcelas a liberar até o fim do período (o que o fechamento grava).
     *
     * @return Collection<int, PayoutItemData>
     */
    public function pending(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->releases->compute($entityId, $doctorId, $from, $to)['releases'];
    }

    /**
     * Parcelas a liberar + atos aguardando recebimento (apuração).
     *
     * @return array{releases: Collection<int, PayoutItemData>, awaiting: Collection<int, PayoutItemData>}
     */
    public function apuracao(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->releases->compute($entityId, $doctorId, $from, $to);
    }

    /** Fim do último fechamento válido do médico (parcelas acumuladas até ele). */
    public function lastClosedUntil(string $entityId, string $doctorId): ?string
    {
        return $this->releases->lastClosedUntil($entityId, $doctorId);
    }

    /**
     * Totais em centavos (o fechamento compara estes números com os que o
     * usuário conferiu na prévia): base = recebido liberado nas parcelas.
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
