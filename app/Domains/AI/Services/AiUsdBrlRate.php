<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Carbon\CarbonInterface;

/**
 * Cotação US$→R$ usada pra mostrar o custo de IA (cotado em US$ pelos provedores) em reais. Fachada de
 * AiExchangeRateService (cotação gravada no manager > última recarga de provedor > fallback), preservando o contrato
 * antigo (`rate()`, `current()`, `FALLBACK`) do P&L (PlatformFinanceService), do catálogo e das estimativas de
 * medicamentos. O fallback 5,50 só vale sem nenhuma cotação e vem sinalizado (`is_fallback`).
 */
final class AiUsdBrlRate
{
    public const FALLBACK = AiExchangeRateService::FALLBACK;

    public function __construct(private readonly ?AiExchangeRateService $rates = null)
    {
    }

    /** @return array{rate: float, topped_up_at: ?CarbonInterface, is_fallback: bool, source: string} */
    public function current(): array
    {
        $current = ($this->rates ?? new AiExchangeRateService())->current();

        return [
            'rate'         => $current['rate'],
            'topped_up_at' => $current['effective_at'],
            'is_fallback'  => $current['is_fallback'],
            'source'       => $current['source'],
        ];
    }

    public function rate(): float
    {
        return $this->current()['rate'];
    }
}
