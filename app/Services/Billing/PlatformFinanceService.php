<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Domains\AI\Models\AiProviderTopup;
use App\Enums\Billing\{CancellationReason, PaymentStatus, PlatformExpenseCategory};
use App\Enums\SubscriptionStatus;
use App\Models\Billing\{Cancellation, Payment, PlatformExpense};
use App\Models\{Entity, Subscription};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P&L interno do EasyEye — receita/despesa/lucro do próprio SaaS, para um
 * período arbitrário. NÃO confundir com App\Services\ManagerDashboardService
 * (KPIs operacionais gerais, MRR "como está agora") nem com o financeiro da
 * CLÍNICA (App\Services\Financial\*, dado do tenant).
 *
 * Fontes de dado — cada uma com sua semântica própria, de propósito:
 *  - Receita (regime de caixa): `payments` pagos no período (dinheiro que
 *    efetivamente entrou), não o preço implícito do plano.
 *  - MRR: reconstrução ponto-no-tempo via starts_at/cancelled_at/ends_at
 *    (mesma técnica de ManagerDashboardService::getMrrTrend()) — é uma
 *    métrica de "taxa de recorrência", não de caixa.
 *  - Despesas automáticas (IA, taxas de gateway): somadas de tabelas que já
 *    guardam custo real (ai_run_provider_calls.raw_cost_usd, payments.
 *    gateway_fee) — nunca lançamento manual, pra não divergir da fonte real.
 *  - Despesas manuais (servidor, folha, marketing, imposto, outros):
 *    PlatformExpense, lançamento do dono/admin.
 *  - Cancelamentos: tabela `cancellations` (evento histórico, effective_at)
 *    — não `subscriptions.status` atual, que só reflete o estado de HOJE.
 */
class PlatformFinanceService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $previous = $this->previousPeriod($from, $to);

        $revenue          = $this->revenue($from, $to);
        $revenueByPlan    = $this->revenueByPlan($from, $to);
        $expenses         = $this->expenses($from, $to);
        $previousRevenue  = $this->revenue($previous['from'], $previous['to'])['gross'];
        $previousExpenses = $this->expenses($previous['from'], $previous['to'])['total'];

        $profit         = $revenue['gross'] - $expenses['total'];
        $margin         = $revenue['gross'] > 0 ? round(($profit / $revenue['gross']) * 100, 1) : 0.0;
        $previousProfit = $previousRevenue - $previousExpenses;

        $mrr         = $this->mrrAsOf($to);
        $previousMrr = $this->mrrAsOf($previous['to']);

        $payingClinics = $this->payingClinicsAsOf($to);
        $newClinics    = $this->newClinics($from, $to);
        $cancellations = $this->cancellations($from, $to);
        $delinquency   = $this->delinquencyAsOf($to);

        $arpu = $payingClinics > 0 ? round($mrr / $payingClinics, 2) : 0.0;

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ],
            'revenue' => [
                'gross'     => round($revenue['gross'], 2),
                'net'       => round($revenue['net'], 2),
                'delta_pct' => $this->deltaPct($revenue['gross'], $previousRevenue),
                'by_plan'   => $revenueByPlan,
            ],
            'expenses' => [
                'total'       => round($expenses['total'], 2),
                'delta_pct'   => $this->deltaPct($expenses['total'], $previousExpenses),
                'by_category' => $expenses['by_category'],
            ],
            'profit' => [
                'amount'    => round($profit, 2),
                'margin'    => $margin,
                'delta_pct' => $this->deltaPct($profit, $previousProfit),
            ],
            'mrr' => [
                'amount'    => round($mrr, 2),
                'delta_pct' => $this->deltaPct($mrr, $previousMrr),
            ],
            'arpu'           => $arpu,
            'paying_clinics' => $payingClinics,
            'new_clinics'    => $newClinics,
            'cancellations'  => $cancellations,
            'delinquency'    => $delinquency,
        ];
    }

    /**
     * @return array{gross: float, net: float}
     */
    private function revenue(Carbon $from, Carbon $to): array
    {
        $row = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount), 0) as gross, COALESCE(SUM(net_amount), 0) as net')
            ->first();

        return [
            'gross' => (float) ($row->gross ?? 0),
            'net'   => (float) ($row->net ?? 0),
        ];
    }

    /**
     * @return list<array{plan_name: string, amount: float}>
     */
    private function revenueByPlan(Carbon $from, Carbon $to): array
    {
        return Payment::query()
            ->where('payments.status', PaymentStatus::Paid->value)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->join('plans', 'invoices.plan_id', '=', 'plans.id')
            ->selectRaw('plans.name as plan_name, COALESCE(SUM(payments.amount), 0) as amount')
            ->groupBy('plans.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => ['plan_name' => (string) $row->plan_name, 'amount' => round((float) $row->amount, 2)])
            ->all();
    }

    /**
     * @return array{total: float, by_category: list<array{category: string, label: string, amount: float, auto: bool}>}
     */
    private function expenses(Carbon $from, Carbon $to): array
    {
        $manual = PlatformExpense::query()
            ->whereBetween('effective_at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('category')
            ->pluck('amount', 'category');

        $aiCost = (float) DB::table('ai_run_provider_calls')
            ->whereNotNull('raw_cost_usd')
            ->whereBetween('created_at', [$from, $to])
            ->sum('raw_cost_usd');

        $gatewayFees = (float) Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('gateway_fee');

        $byCategory = [];
        $total      = 0.0;

        foreach (PlatformExpenseCategory::cases() as $category) {
            $amount       = (float) ($manual[$category->value] ?? 0);
            $byCategory[] = [
                'category' => $category->value,
                'label'    => $category->label(),
                'amount'   => round($amount, 2),
                'auto'     => false,
            ];
            $total += $amount;
        }

        // IA é cotada em USD (raw_cost_usd) — convertida pra BRL pra somar no
        // mesmo P&L que o resto (planos/pagamentos são sempre BRL). Não existe
        // config de câmbio no sistema (config('ai.pricing.*') não tem essa
        // chave) — inventar uma taxa fixa aqui seria dado fabricado numa tela
        // que promete "aponte a origem do número". Em vez disso, usa a cotação
        // REAL da recarga mais recente em `ai_provider_topups.exchange_rate`
        // (o que o EasyEye de fato pagou por USD da última vez) — só cai pro
        // fallback se NUNCA houve recarga ainda (sistema novo).
        $usdToBrl  = $this->latestUsdToBrlRate();
        $aiCostBrl = $aiCost * $usdToBrl;

        $byCategory[] = ['category' => 'ai', 'label' => 'Inteligência Artificial', 'amount' => round($aiCostBrl, 2), 'auto' => true];
        $byCategory[] = ['category' => 'gateway_fees', 'label' => 'Taxas de gateway', 'amount' => round($gatewayFees, 2), 'auto' => true];

        $total += $aiCostBrl + $gatewayFees;

        usort($byCategory, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return ['total' => $total, 'by_category' => $byCategory];
    }

    /**
     * MRR reconstruído ponto-no-tempo (assinaturas que estavam ativas NA DATA
     * informada) — mesma técnica de ManagerDashboardService::getMrrTrend(),
     * generalizada pra uma data arbitrária (não só "hoje" ou "os últimos 12
     * meses fixos"). Necessário pra period picker: MRR de "3 meses atrás" não
     * é igual ao MRR de hoje reconstituído pelas contagens atuais.
     */
    private function mrrAsOf(Carbon $asOf): float
    {
        return (float) Subscription::query()
            ->whereIn('subscriptions.status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Cancelled->value,
                SubscriptionStatus::Expired->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->where(function ($q) use ($asOf) {
                $q->where('subscriptions.starts_at', '<=', $asOf)
                    ->orWhere(function ($q2) use ($asOf) {
                        $q2->whereNull('subscriptions.starts_at')
                            ->where('subscriptions.created_at', '<=', $asOf);
                    });
            })
            ->where(function ($q) use ($asOf) {
                $q->whereNull('subscriptions.cancelled_at')
                    ->orWhere('subscriptions.cancelled_at', '>', $asOf);
            })
            ->where(function ($q) use ($asOf) {
                $q->whereNull('subscriptions.ends_at')
                    ->orWhere('subscriptions.ends_at', '>', $asOf);
            })
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->sum('plans.price');
    }

    private function payingClinicsAsOf(Carbon $asOf): int
    {
        return Subscription::query()
            ->whereIn('subscriptions.status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Cancelled->value,
                SubscriptionStatus::Expired->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->where(function ($q) use ($asOf) {
                $q->where('subscriptions.starts_at', '<=', $asOf)
                    ->orWhere(function ($q2) use ($asOf) {
                        $q2->whereNull('subscriptions.starts_at')
                            ->where('subscriptions.created_at', '<=', $asOf);
                    });
            })
            ->where(function ($q) use ($asOf) {
                $q->whereNull('subscriptions.cancelled_at')
                    ->orWhere('subscriptions.cancelled_at', '>', $asOf);
            })
            ->where(function ($q) use ($asOf) {
                $q->whereNull('subscriptions.ends_at')
                    ->orWhere('subscriptions.ends_at', '>', $asOf);
            })
            ->distinct('subscriptions.entity_id')
            ->count('subscriptions.entity_id');
    }

    private function newClinics(Carbon $from, Carbon $to): int
    {
        return Entity::query()
            ->where('is_client', true)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /**
     * @return array{count: int, by_reason: array<string, int>}
     */
    private function cancellations(Carbon $from, Carbon $to): array
    {
        $rows = Cancellation::query()
            ->whereBetween('effective_at', [$from, $to])
            ->selectRaw('reason, COUNT(*) as cnt')
            ->groupBy('reason')
            ->pluck('cnt', 'reason');

        $byReason = [];

        foreach (CancellationReason::cases() as $reason) {
            $byReason[$reason->value] = (int) ($rows[$reason->value] ?? 0);
        }

        return [
            'count'     => (int) $rows->sum(),
            'by_reason' => $byReason,
        ];
    }

    /**
     * @return array{count: int, amount_at_risk: float}
     */
    private function delinquencyAsOf(Carbon $asOf): array
    {
        $query = Subscription::query()
            ->where('subscriptions.status', SubscriptionStatus::PastDue->value)
            ->where(function ($q) use ($asOf) {
                $q->whereNull('subscriptions.grace_period_ends_at')
                    ->orWhere('subscriptions.grace_period_ends_at', '<=', $asOf->copy()->endOfDay());
            })
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id');

        return [
            'count'          => (clone $query)->count('subscriptions.id'),
            'amount_at_risk' => round((float) (clone $query)->sum('plans.price'), 2),
        ];
    }

    /**
     * Período anterior de MESMA duração, imediatamente antes de `$from` — base
     * de comparação para as variações percentuais (delta_pct) que a IA usa
     * pra fundamentar "custo de IA subiu X%" em vez de citar números soltos.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    private function previousPeriod(Carbon $from, Carbon $to): array
    {
        $days = max(1, $from->diffInDays($to));

        return [
            'from' => $from->copy()->subDays($days + 1),
            'to'   => $from->copy()->subDay(),
        ];
    }

    /**
     * Última cotação USD→BRL efetivamente paga numa recarga de provider IA.
     * Fallback 5.50 só se o sistema nunca recarregou nenhum provider ainda —
     * marcado explicitamente pra quem ler o código, e o valor não fabrica
     * precisão: é só pra não quebrar o P&L num ambiente recém-criado.
     */
    private function latestUsdToBrlRate(): float
    {
        $rate = AiProviderTopup::query()
            ->whereNotNull('exchange_rate')
            ->latest('topped_up_at')
            ->value('exchange_rate');

        return $rate !== null ? (float) $rate : 5.50;
    }

    private function deltaPct(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.005) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
