<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiProviderTopup;
use App\Enums\AI\AiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Custo REAL do EasyEye nos provedores de IA (lado "supplier" da relação).
 *
 * Diferente de AiCreditWalletService (que controla o saldo VIRTUAL do cliente
 * no EasyEye), este service responde: "quanto o EasyEye já gastou em USD com
 * OpenAI/Anthropic/Gemini neste mês?". Fonte de verdade: ai_run_provider_calls.raw_cost_usd.
 *
 * Útil para:
 *   - Dashboard interno do manager
 *   - Forecast mensal (extrapolação linear baseada nos últimos 7 dias)
 *   - Cálculo de margem real (receita de créditos vendidos - custo de provedor)
 *   - Alertas de threshold antes do saldo do provedor esgotar
 */
class AiProviderCostService
{
    /**
     * Retorna custo por provedor com 4 períodos + forecast + métricas operacionais.
     *
     * @return array{
     *     summary: array{
     *         month_to_date_usd: float,
     *         last_7d_usd: float,
     *         yesterday_usd: float,
     *         month_forecast_usd: float,
     *         total_calls_mtd: int,
     *     },
     *     by_provider: array<string, array{
     *         label: string,
     *         month_to_date_usd: float,
     *         last_7d_usd: float,
     *         yesterday_usd: float,
     *         month_forecast_usd: float,
     *         calls_mtd: int,
     *         avg_cost_per_call_usd: float,
     *         models_breakdown: list<array{model: string, calls: int, cost_usd: float}>,
     *     }>,
     *     margin: array{
     *         credits_consumed_mtd: int,
     *         revenue_estimate_usd: float,
     *         cost_mtd_usd: float,
     *         gross_margin_usd: float,
     *         gross_margin_pct: float,
     *     }
     * }
     */
    public function getCostDashboard(): array
    {
        $now              = CarbonImmutable::now();
        $startOfMonth     = $now->startOfMonth();
        $startOf7DaysAgo  = $now->subDays(7)->startOfDay();
        $startOfYesterday = $now->subDay()->startOfDay();
        $endOfYesterday   = $now->subDay()->endOfDay();

        $byProvider = [];
        $totalMtd   = 0.0;
        $total7d    = 0.0;
        $totalYday  = 0.0;
        $totalCalls = 0;

        foreach (AiProvider::cases() as $provider) {
            $mtd = $this->sumCostBetween($provider, $startOfMonth, $now);
            $w7d = $this->sumCostBetween($provider, $startOf7DaysAgo, $now);
            $yda = $this->sumCostBetween($provider, $startOfYesterday, $endOfYesterday);
            $callsMtd = $this->countCallsBetween($provider, $startOfMonth, $now);
            $balance  = $this->getEstimatedBalance($provider, $w7d);

            $byProvider[$provider->value] = [
                'label'                 => __("ai.providers.{$provider->value}"),
                'month_to_date_usd'     => round($mtd, 4),
                'last_7d_usd'           => round($w7d, 4),
                'yesterday_usd'         => round($yda, 4),
                'month_forecast_usd'    => round($this->forecastMonth($mtd, $w7d, $now), 4),
                'calls_mtd'             => $callsMtd,
                'avg_cost_per_call_usd' => $callsMtd > 0 ? round($mtd / $callsMtd, 6) : 0.0,
                'models_breakdown'      => $this->modelsBreakdown($provider, $startOfMonth, $now),
                'estimated_balance'     => $balance,
                'recent_topups'         => $this->getRecentTopups($provider, 5),
            ];

            $totalMtd   += $mtd;
            $total7d    += $w7d;
            $totalYday  += $yda;
            $totalCalls += $callsMtd;
        }

        return [
            'summary' => [
                'month_to_date_usd'   => round($totalMtd, 4),
                'last_7d_usd'         => round($total7d, 4),
                'yesterday_usd'       => round($totalYday, 4),
                'month_forecast_usd'  => round($this->forecastMonth($totalMtd, $total7d, $now), 4),
                'total_calls_mtd'     => $totalCalls,
            ],
            'by_provider' => $byProvider,
            'margin'      => $this->calculateMargin($totalMtd, $startOfMonth, $now),
        ];
    }

    /**
     * Calcula custo agregado em USD para um provedor em um período.
     */
    private function sumCostBetween(AiProvider $provider, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) DB::table('ai_run_provider_calls')
            ->where('provider', $provider->value)
            ->whereNotNull('raw_cost_usd')
            ->whereBetween('created_at', [$from, $to])
            ->sum('raw_cost_usd');
    }

    private function countCallsBetween(AiProvider $provider, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('ai_run_provider_calls')
            ->where('provider', $provider->value)
            ->whereNotNull('raw_cost_usd')
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /**
     * Forecast linear do custo mensal: extrapola consumo dos últimos 7 dias
     * para os dias restantes do mês, somando ao já gasto.
     *
     * Fórmula: cost_mtd + (cost_7d / 7) × dias_restantes
     */
    private function forecastMonth(float $costMtd, float $cost7d, CarbonImmutable $now): float
    {
        $endOfMonth   = $now->endOfMonth();
        $daysLeft     = $now->diffInDays($endOfMonth);
        $dailyAvg     = $cost7d / 7;

        return $costMtd + ($dailyAvg * $daysLeft);
    }

    /**
     * Top 5 modelos por custo no período, para um provedor.
     *
     * @return list<array{model: string, calls: int, cost_usd: float}>
     */
    private function modelsBreakdown(AiProvider $provider, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('ai_run_provider_calls')
            ->where('provider', $provider->value)
            ->whereNotNull('raw_cost_usd')
            ->whereBetween('created_at', [$from, $to])
            ->select('model')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(raw_cost_usd) as cost_usd')
            ->groupBy('model')
            ->orderByDesc('cost_usd')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'model'    => (string) $row->model,
                'calls'    => (int) $row->calls,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])
            ->all();
    }

    /**
     * Saldo estimado restante no provedor.
     *
     * Fórmula: SUM(topups) − SUM(custo consumido após o primeiro topup registrado).
     * Se não há topups ainda, retorna null (sem base de cálculo).
     *
     * Inclui também:
     *   - days_remaining: estimativa de quantos dias o saldo dura no ritmo dos últimos 7d
     *   - alert_level: ok | warning (< 14 dias) | critical (< 7 dias) | exhausted (≤ 0)
     *
     * @return array{
     *     has_topups: bool,
     *     total_topped_up_usd: float,
     *     consumed_since_first_topup_usd: float,
     *     remaining_usd: ?float,
     *     daily_burn_usd: float,
     *     days_remaining: ?int,
     *     alert_level: string,
     *     first_topup_at: ?string,
     * }
     */
    private function getEstimatedBalance(AiProvider $provider, float $weekCost): array
    {
        $totalTopups = (float) DB::table('ai_provider_topups')
            ->where('provider', $provider->value)
            ->sum('amount_usd');

        $firstTopupAt = DB::table('ai_provider_topups')
            ->where('provider', $provider->value)
            ->min('topped_up_at');

        $dailyBurn = $weekCost / 7;

        if (! $firstTopupAt) {
            return [
                'has_topups'                     => false,
                'total_topped_up_usd'            => 0.0,
                'consumed_since_first_topup_usd' => 0.0,
                'remaining_usd'                  => null,
                'daily_burn_usd'                 => round($dailyBurn, 4),
                'days_remaining'                 => null,
                'alert_level'                    => 'unknown',
                'first_topup_at'                 => null,
            ];
        }

        $consumedSince = (float) DB::table('ai_run_provider_calls')
            ->where('provider', $provider->value)
            ->whereNotNull('raw_cost_usd')
            ->where('created_at', '>=', $firstTopupAt)
            ->sum('raw_cost_usd');

        $remaining = $totalTopups - $consumedSince;

        $daysRemaining = $dailyBurn > 0 ? (int) floor($remaining / $dailyBurn) : null;

        $alertLevel = match (true) {
            $remaining <= 0                                          => 'exhausted',
            $dailyBurn > 0 && $daysRemaining !== null && $daysRemaining < 7  => 'critical',
            $dailyBurn > 0 && $daysRemaining !== null && $daysRemaining < 14 => 'warning',
            default                                                  => 'ok',
        };

        return [
            'has_topups'                     => true,
            'total_topped_up_usd'            => round($totalTopups, 4),
            'consumed_since_first_topup_usd' => round($consumedSince, 4),
            'remaining_usd'                  => round($remaining, 4),
            'daily_burn_usd'                 => round($dailyBurn, 4),
            'days_remaining'                 => $daysRemaining,
            'alert_level'                    => $alertLevel,
            'first_topup_at'                 => (string) $firstTopupAt,
        ];
    }

    /**
     * Lista os topups mais recentes de um provedor.
     *
     * @return list<array{id: string, amount_usd: float, topped_up_at: string, reference: ?string, note: ?string, created_by_name: ?string}>
     */
    private function getRecentTopups(AiProvider $provider, int $limit = 5): array
    {
        return DB::table('ai_provider_topups')
            ->leftJoin('users', 'users.id', '=', 'ai_provider_topups.created_by')
            ->where('ai_provider_topups.provider', $provider->value)
            ->select([
                'ai_provider_topups.id',
                'ai_provider_topups.amount_usd',
                'ai_provider_topups.topped_up_at',
                'ai_provider_topups.reference',
                'ai_provider_topups.note',
                'users.name as created_by_name',
            ])
            ->orderByDesc('ai_provider_topups.topped_up_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id'              => (string) $row->id,
                'amount_usd'      => round((float) $row->amount_usd, 4),
                'topped_up_at'    => (string) $row->topped_up_at,
                'reference'       => $row->reference ? (string) $row->reference : null,
                'note'            => $row->note ? (string) $row->note : null,
                'created_by_name' => $row->created_by_name ? (string) $row->created_by_name : null,
            ])
            ->all();
    }

    /**
     * Margem bruta no mês corrente.
     *
     * Receita estimada = créditos consumidos no mês × usd_per_credit × margin_multiplier
     * (representa a parte da receita "alocada" ao consumo deste mês).
     */
    private function calculateMargin(float $costMtd, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $creditsConsumed = (int) DB::table('ai_credit_ledger_entries')
            ->where('type', 'consume')
            ->whereBetween('created_at', [$from, $to])
            ->sum(DB::raw("COALESCE((metadata->>'consumed_from_reservation')::int, 0)"));

        $usdPerCredit    = (float) config('ai.pricing.usd_per_credit', 0.01);
        $marginMult      = (float) config('ai.pricing.margin_multiplier', 2.0);
        $revenueEstimate = $creditsConsumed * $usdPerCredit;     // valor "cobrado" no consumo
        $grossMargin     = $revenueEstimate - $costMtd;
        $grossMarginPct  = $revenueEstimate > 0
            ? round(($grossMargin / $revenueEstimate) * 100, 1)
            : 0.0;

        return [
            'credits_consumed_mtd' => $creditsConsumed,
            'revenue_estimate_usd' => round($revenueEstimate, 4),
            'cost_mtd_usd'         => round($costMtd, 4),
            'gross_margin_usd'     => round($grossMargin, 4),
            'gross_margin_pct'     => $grossMarginPct,
            'margin_multiplier'    => $marginMult,
        ];
    }
}
