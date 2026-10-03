<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiProviderTopup;
use Illuminate\Support\Carbon;

/**
 * Cotação US$→R$ usada pra mostrar o custo de IA (cotado em US$ pelos
 * provedores) em reais: a cotação REAL da recarga mais recente de provedor
 * (`ai_provider_topups.exchange_rate`, o que o EasyEye de fato pagou por US$).
 *
 * Não existe config de câmbio no sistema — inventar uma taxa seria dado
 * fabricado. O fallback 5,50 só vale enquanto nunca houve recarga (sistema
 * novo) e vem sinalizado (`is_fallback`) pra tela avisar que é estimativa.
 * Fonte única do P&L (PlatformFinanceService) e de Manager → Uso de IA.
 */
final class AiUsdBrlRate
{
    public const FALLBACK = 5.50;

    /** @return array{rate: float, topped_up_at: ?Carbon, is_fallback: bool} */
    public function current(): array
    {
        $topup = AiProviderTopup::query()
            ->whereNotNull('exchange_rate')
            ->latest('topped_up_at')
            ->first(['exchange_rate', 'topped_up_at']);

        if ($topup === null) {
            return ['rate' => self::FALLBACK, 'topped_up_at' => null, 'is_fallback' => true];
        }

        return [
            'rate'         => (float) $topup->exchange_rate,
            'topped_up_at' => $topup->topped_up_at,
            'is_fallback'  => false,
        ];
    }

    public function rate(): float
    {
        return $this->current()['rate'];
    }
}
