<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\ScheduleSituation;
use App\Models\{FinancialCashEntry, Patient, Schedule};
use Illuminate\Support\Facades\Cache;

class ClinicBiService
{
    /** Minutos que o resumo do período fica em cache (a tela mostra "Atualizado às"). */
    public const SUMMARY_TTL_MINUTES = 10;

    /** Minutos que a tendência mensal fica em cache — muda lentamente. */
    public const TREND_TTL_MINUTES = 30;

    /** Convênios na lista "Faturamento por convênio" (os de maior faturado). */
    public const COVENANT_CHART_LIMIT = 6;

    /**
     * Versão do formato em cache. v2: payload sem textos traduzidos (os
     * rótulos entram depois da leitura, no idioma de quem abriu a tela) e com
     * `generated_at`. Antes o cache guardava __() do primeiro usuário e a chave
     * não tinha o idioma: quem usava inglês via rótulos em português.
     * v3: faturamento por convênio vindo do BillingReportService (mesma fonte
     * do relatório de convênios), com covenant_id/paid/denied por linha.
     */
    private const CACHE_VERSION = 'v3';

    public function __construct(
        private readonly BillingReportService $billingReports,
    ) {
    }

    /**
     * Agrega todos os KPIs e séries do período solicitado.
     * Cache de 10 minutos por entidade + intervalo; `generated_at` (ISO 8601)
     * diz quando os números foram calculados.
     */
    public function summary(string $entityId, string $from, string $to): array
    {
        $cached = Cache::remember(
            $this->summaryKey($entityId, $from, $to),
            now()->addMinutes(self::SUMMARY_TTL_MINUTES),
            fn (): array => [
                ...$this->buildSummary($entityId, $from, $to),
                'generated_at' => now()->toIso8601String(),
            ],
        );

        return $this->localizeSummary($cached);
    }

    /**
     * Tendência de receita vs despesa dos últimos N meses.
     * Cache de 30 minutos — muda lentamente.
     */
    public function monthlyTrend(string $entityId, int $months = 6): array
    {
        return $this->trendSnapshot($entityId, $months)['series'];
    }

    /**
     * Tendência + quando foi calculada.
     *
     * @return array{series: list<array{period: string, month: string, income: float, expense: float}>, generated_at: string}
     */
    public function trendSnapshot(string $entityId, int $months = 6): array
    {
        return Cache::remember(
            $this->trendKey($entityId, $months),
            now()->addMinutes(self::TREND_TTL_MINUTES),
            fn (): array => [
                'series'       => $this->buildMonthlyTrend($entityId, $months),
                'generated_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Botão "Atualizar" do dashboard: descarta o cache desta clínica (resumo
     * do período + tendência) para o próximo acesso recalcular na hora.
     */
    public function forget(string $entityId, string $from, string $to, int $months = 6): void
    {
        Cache::forget($this->summaryKey($entityId, $from, $to));
        Cache::forget($this->trendKey($entityId, $months));
    }

    // ── cache ─────────────────────────────────────────────────────────────────

    /** entityId sempre na chave (isolamento entre clínicas). */
    private function summaryKey(string $entityId, string $from, string $to): string
    {
        return "bi.{$entityId}.summary." . self::CACHE_VERSION . '.' . md5("{$from}.{$to}");
    }

    /** O mês corrente entra na chave: na virada do mês a série anda na hora. */
    private function trendKey(string $entityId, int $months): string
    {
        return "bi.{$entityId}.trend." . self::CACHE_VERSION . ".{$months}." . now()->format('Y-m');
    }

    /** Rótulos no idioma atual, aplicados DEPOIS do cache (o cache não tem texto traduzido). */
    private function localizeSummary(array $summary): array
    {
        $summary['by_covenant_chart'] = array_map(
            fn (array $row): array => [
                ...$row,
                'label' => ($row['inactive'] ?? false)
                    ? __('financial_bi.covenant_inactive', ['name' => $row['label'] ?? __('financial_bi.no_covenant')])
                    : ($row['label'] ?? __('financial_bi.no_covenant')),
            ],
            $summary['by_covenant_chart'] ?? [],
        );

        $summary['schedule_chart'] = array_map(
            fn (array $row): array => [
                'key'   => $row['key'],
                'label' => __("financial_bi.chart_{$row['key']}"),
                'value' => $row['value'],
            ],
            $summary['schedule_chart'] ?? [],
        );

        return $summary;
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
        // Fonte única com o relatório de convênios (BillingReportService):
        // guias sem rascunho/canceladas, "Recebido" só de guia paga, agregado
        // no banco pelo id do convênio (convênio excluído mantém a linha).
        $covenantRows = $this->billingReports->byCovenant($entityId, $from, $to);
        $billing      = $this->billingReports->totals($covenantRows);

        $totalBilled = $billing['amount'];
        $totalPaid   = $billing['paid'];
        $totalGlosa  = $billing['denied'];
        $paidCount   = $billing['paid_claims'];
        $ticketMedio = $paidCount > 0 ? round($totalPaid / $paidCount, 2) : 0.0;
        $receiptRate = $totalBilled > 0 ? round(($totalPaid / $totalBilled) * 100, 1) : 0.0;

        // Lista por convênio: as primeiras linhas do relatório (maior faturado;
        // empate por nome e id). Sem convênio → label null; `inactive` marca
        // convênio excluído — os dois viram texto traduzido depois do cache,
        // em localizeSummary(). covenant_id/paid/denied: mesma linha do relatório.
        $byCovenantChart = array_map(
            fn (array $row): array => [
                'covenant_id' => $row['covenant_id'],
                'label'       => $row['covenant_name'],
                'inactive'    => $row['inactive'],
                'value'       => $row['amount'],
                'paid'        => $row['paid'],
                'denied'      => $row['denied'],
            ],
            array_slice($covenantRows, 0, self::COVENANT_CHART_LIMIT),
        );

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

        // Só a chave vai para o cache; o rótulo traduzido entra em localizeSummary().
        $scheduleChart = array_values(array_filter([
            $attended > 0 ? ['key' => 'attended', 'value' => $attended] : null,
            $noshow > 0 ? ['key' => 'no_show', 'value' => $noshow] : null,
            $cancelled > 0 ? ['key' => 'cancelled', 'value' => $cancelled] : null,
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
        // Ancora no dia 1 ANTES de subtrair: now()->subMonths() estoura no dia
        // 29-31 (31/10 - 1 mês = 01/10) e a série repetia/pulava meses.
        $currentMonth = now()->startOfMonth();
        $start        = $currentMonth->copy()->subMonths($months - 1);

        $entries = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->where('entry_date', '>=', $start->toDateString())
            ->where('status', 'paid')
            ->whereNull('deleted_at')
            ->get(['entry_date', 'type', 'amount']);

        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month     = $currentMonth->copy()->subMonths($i);
            $yearMonth = $month->format('Y-m');
            $label     = $month->format('m/Y');

            $monthEntries = $entries->filter(
                fn ($e) => $e->entry_date->format('Y-m') === $yearMonth,
            );

            // `period` (m/Y) mantido; `month` (Y-m, ISO) para a tela formatar no idioma do usuário.
            $series[] = [
                'period'  => $label,
                'month'   => $yearMonth,
                'income'  => (float) $monthEntries->where('type', 'income')->sum('amount'),
                'expense' => (float) $monthEntries->where('type', 'expense')->sum('amount'),
            ];
        }

        return $series;
    }
}
