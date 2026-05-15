<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\ScheduleSituation;
use App\Models\{BillingClaim, FinancialCashEntry, Patient, Schedule};
use Illuminate\Support\Facades\Cache;

class ClinicBiService
{
    /**
     * Agrega todos os KPIs e séries do período solicitado.
     * Cache de 10 minutos por entidade + intervalo.
     */
    public function summary(string $entityId, string $from, string $to): array
    {
        $cacheKey = "bi.{$entityId}.summary." . md5("{$from}.{$to}");

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($entityId, $from, $to): array {
            return $this->buildSummary($entityId, $from, $to);
        });
    }

    /**
     * Tendência de receita vs despesa dos últimos N meses.
     * Cache de 30 minutos — muda lentamente.
     */
    public function monthlyTrend(string $entityId, int $months = 6): array
    {
        $cacheKey = "bi.{$entityId}.trend.{$months}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($entityId, $months): array {
            return $this->buildMonthlyTrend($entityId, $months);
        });
    }

    // ── private builders ──────────────────────────────────────────────────────

    private function buildSummary(string $entityId, string $from, string $to): array
    {
        // ── Fluxo de caixa (entradas pagas) ──────────────────────────────────
        $entries = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->where('status', 'paid')
            ->whereNull('deleted_at')
            ->get(['type', 'amount', 'covenant_id']);

        $income  = (float) $entries->where('type', 'income')->sum('amount');
        $expense = (float) $entries->where('type', 'expense')->sum('amount');
        $balance = round($income - $expense, 2);

        // ── Faturamento TISS / convênios ──────────────────────────────────────
        $claims = BillingClaim::query()
            ->with('covenant:id,name')
            ->where('entity_id', $entityId)
            ->whereBetween('attendance_date', [$from, $to])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNull('deleted_at')
            ->get(['id', 'covenant_id', 'status', 'amount', 'paid_amount', 'glosa_amount']);

        $totalBilled = (float) $claims->sum('amount');
        $paidClaims  = $claims->where('status', 'paid');
        $totalPaid   = (float) $paidClaims->sum('paid_amount');
        $totalGlosa  = (float) $claims->sum('glosa_amount');
        $paidCount   = $paidClaims->count();
        $ticketMedio = $paidCount > 0 ? round($totalPaid / $paidCount, 2) : 0.0;
        $receiptRate = $totalBilled > 0 ? round(($totalPaid / $totalBilled) * 100, 1) : 0.0;

        // Gráfico por convênio: top 6 por valor faturado
        $byCovenantChart = $claims
            ->groupBy(fn ($c) => $c->covenant?->name ?? 'Particular')
            ->map(fn ($group, $name) => [
                'label' => $name,
                'value' => (float) $group->sum('amount'),
            ])
            ->values()
            ->sortByDesc('value')
            ->take(6)
            ->values()
            ->all();

        // ── Agenda ────────────────────────────────────────────────────────────
        $schedules = Schedule::query()
            ->where('entity_id', $entityId)
            ->whereDate('date_time', '>=', $from)
            ->whereDate('date_time', '<=', $to)
            ->whereNull('deleted_at')
            ->get(['situation']);

        $attended  = $schedules->where('situation', ScheduleSituation::Attended)->count();
        $noshow    = $schedules->where('situation', ScheduleSituation::NoShow)->count();
        $cancelled = $schedules->where('situation', ScheduleSituation::Cancelled)->count();
        $total     = $schedules->count();

        $comparable     = $attended + $noshow;
        $attendanceRate = $comparable > 0 ? round(($attended / $comparable) * 100, 1) : 0.0;
        $occupancyRate  = ($total - $cancelled) > 0
            ? round(($attended / ($total - $cancelled)) * 100, 1)
            : 0.0;

        $scheduleChart = array_values(array_filter([
            $attended > 0 ? ['label' => __('financial.bi.chart_attended'), 'value' => $attended] : null,
            $noshow > 0 ? ['label' => __('financial.bi.chart_no_show'), 'value' => $noshow] : null,
            $cancelled > 0 ? ['label' => __('financial.bi.chart_cancelled'), 'value' => $cancelled] : null,
        ]));

        // ── Pacientes novos ───────────────────────────────────────────────────
        $newPatients = Patient::query()
            ->where('entity_id', $entityId)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNull('deleted_at')
            ->count();

        return [
            'kpis' => [
                'income'          => $income,
                'expense'         => $expense,
                'balance'         => $balance,
                'total_billed'    => $totalBilled,
                'total_paid'      => $totalPaid,
                'total_glosa'     => $totalGlosa,
                'ticket_medio'    => $ticketMedio,
                'receipt_rate'    => $receiptRate,
                'attended'        => $attended,
                'noshow'          => $noshow,
                'cancelled'       => $cancelled,
                'total_schedules' => $total,
                'attendance_rate' => $attendanceRate,
                'occupancy_rate'  => $occupancyRate,
                'new_patients'    => $newPatients,
            ],
            'by_covenant_chart' => $byCovenantChart,
            'schedule_chart'    => $scheduleChart,
        ];
    }

    private function buildMonthlyTrend(string $entityId, int $months): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();

        $entries = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->where('entry_date', '>=', $start->toDateString())
            ->where('status', 'paid')
            ->whereNull('deleted_at')
            ->get(['entry_date', 'type', 'amount']);

        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month     = now()->subMonths($i)->startOfMonth();
            $yearMonth = $month->format('Y-m');
            $label     = $month->format('m/Y');

            $monthEntries = $entries->filter(
                fn ($e) => $e->entry_date->format('Y-m') === $yearMonth,
            );

            $series[] = [
                'period'  => $label,
                'income'  => (float) $monthEntries->where('type', 'income')->sum('amount'),
                'expense' => (float) $monthEntries->where('type', 'expense')->sum('amount'),
            ];
        }

        return $series;
    }
}
