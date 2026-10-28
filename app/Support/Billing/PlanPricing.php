<?php

namespace App\Support\Billing;

use App\Enums\BillingCycle;
use App\Models\Plan;

/**
 * Preços do plano por ciclo, prontos para a tela: valor do ciclo, valor
 * equivalente por mês e economia em relação a pagar mês a mês. Os números
 * vão crus — a formatação de moeda fica no navegador, no idioma do usuário.
 */
final class PlanPricing
{
    /**
     * @return list<array{cycle: string, label: string, period_label: string, months: int, price: float, monthly_equivalent: float, savings_percent: int}>
     */
    public static function cycles(Plan $plan): array
    {
        $prices  = $plan->cyclePrices();
        $monthly = $prices[BillingCycle::Monthly->value] ?? null;
        $rows    = [];

        foreach ($prices as $value => $price) {
            $cycle  = BillingCycle::from($value);
            $months = $cycle->months();

            $rows[] = [
                'cycle'              => $value,
                'label'              => $cycle->label(),
                'period_label'       => Plan::periodLabel($cycle),
                'months'             => $months,
                'price'              => $price,
                'monthly_equivalent' => round($price / $months, 2),
                'savings_percent'    => self::savingsPercent($monthly, $price, $months),
            ];
        }

        return $rows;
    }

    /**
     * Economia sobre o mensal × meses, arredondada para baixo (nunca anuncia
     * mais desconto do que o real). Sem preço mensal não há com o que comparar.
     */
    public static function savingsPercent(?float $monthly, float $price, int $months): int
    {
        if ($monthly === null || $monthly <= 0 || $months <= 1) {
            return 0;
        }

        $full = $monthly * $months;

        return max(0, (int) floor((($full - $price) / $full) * 100 + 1e-9));
    }
}
