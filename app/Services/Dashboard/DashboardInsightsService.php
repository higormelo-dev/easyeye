<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\{BillingClaimStatus, FinancialEntryStatus, FinancialEntryType, ScheduleSituation};
use App\Models\{BillingClaim, FinancialCashEntry, Schedule};
use App\Services\Financial\{CashFlowService, ClinicBiService, GlosaQueueService};
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Support\Facades\Cache;

/**
 * Números de GESTÃO do Dashboard: comparativo do mês × mesmo intervalo do mês
 * anterior, série diária dos últimos 30 dias, tendência financeira de 6 meses,
 * contas a receber e glosas.
 *
 * Não vão no polling de 30 s: são carregados na abertura do painel e no botão
 * "Atualizar" (que descarta o cache — forget()). Cache curto por clínica (e
 * por médico, no "meu mês"), sempre com o dia na chave; só agregados, nenhum
 * dado de paciente. O resumo da clínica vem do ClinicBiService (o mesmo do BI,
 * com o mesmo cache), então os números batem com a tela do BI.
 */
class DashboardInsightsService
{
    /** Minutos de cache dos agregados próprios do Dashboard (mesmo prazo do resumo do BI). */
    public const TTL_MINUTES = ClinicBiService::SUMMARY_TTL_MINUTES;

    /** Dias da série "consultas × faltas". */
    public const DAILY_DAYS = 30;

    /** Meses da tendência financeira. */
    public const TREND_MONTHS = 6;

    private const CACHE_VERSION = 'v1';

    public function __construct(
        private readonly ClinicBiService $bi,
        private readonly CashFlowService $cashFlow,
        private readonly GlosaQueueService $glosaQueue,
    ) {
    }

    /**
     * Mês atual até hoje × o MESMO intervalo de dias do mês anterior (6/10 →
     * 1–6/10 × 1–6/09). No fim de um mês mais longo que o anterior, o
     * anterior para no último dia dele (31/10 → 1–30/09).
     *
     * @return array{current: array{from: string, to: string}, previous: array{from: string, to: string}}
     */
    public static function periods(?CarbonInterface $today = null): array
    {
        $today        = Carbon::parse($today ?? now())->startOfDay();
        $currentFrom  = $today->copy()->startOfMonth();
        $previousFrom = $currentFrom->copy()->subMonthNoOverflow();
        $previousTo   = $previousFrom->copy()->addDays($today->day - 1);
        $previousEnd  = $previousFrom->copy()->endOfMonth()->startOfDay();

        if ($previousTo->greaterThan($previousEnd)) {
            $previousTo = $previousEnd;
        }

        return [
            'current'  => ['from' => $currentFrom->toDateString(), 'to' => $today->toDateString()],
            'previous' => ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString()],
        ];
    }

    /**
     * Indicadores do mês da clínica nos dois intervalos (ClinicBiService,
     * mesmo cache do BI). Valores financeiros só com $withFinance (Gate
     * ViewFinancial — decidido por quem chama).
     *
     * @return array{current: array<string, mixed>, previous: array<string, mixed>, covenants: list<array<string, mixed>>, generated_at: string}
     */
    public function clinicMonth(string $entityId, bool $withFinance): array
    {
        $periods  = self::periods();
        $current  = $this->bi->summary($entityId, $periods['current']['from'], $periods['current']['to']);
        $previous = $this->bi->summary($entityId, $periods['previous']['from'], $periods['previous']['to']);
        $schedule = Cache::remember(
            $this->key($entityId, 'clinic.month'),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => [...$this->scheduleMonth($entityId), 'generated_at' => now()->toIso8601String()],
        );

        return [
            'current'  => [...$schedule['current'], ...$this->monthKpis($current['kpis'], $withFinance)],
            'previous' => [...$schedule['previous'], ...$this->monthKpis($previous['kpis'], $withFinance)],
            // Faturado × recebido por convênio no mês (sem dado de paciente).
            'covenants'    => $withFinance ? array_values($current['by_covenant_chart'] ?? []) : [],
            'generated_at' => $this->oldest($current['generated_at'] ?? null, $schedule['generated_at']),
        ];
    }

    /**
     * "Meu mês" do médico: atendimentos, faltas e taxa de falta DELE nos dois
     * intervalos — uma consulta agregada, em cache por clínica + médico.
     *
     * @return array{current: array<string, mixed>, previous: array<string, mixed>, generated_at: string}
     */
    public function doctorMonth(string $entityId, string $doctorId): array
    {
        return Cache::remember(
            $this->key($entityId, "doctor.{$doctorId}.month"),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => [...$this->scheduleMonth($entityId, $doctorId), 'generated_at' => now()->toIso8601String()],
        );
    }

    /**
     * Agenda nos dois intervalos numa consulta agregada (clínica inteira ou só
     * o médico). A ocupação conta só o que JÁ ACONTECEU (horário até agora):
     * com o mês "até hoje", as consultas que ainda vão acontecer hoje entravam
     * no denominador e a ocupação despencava de manhã — injusto contra o mês
     * anterior, que já está todo no passado.
     *
     * @return array{current: array<string, mixed>, previous: array<string, mixed>}
     */
    private function scheduleMonth(string $entityId, ?string $doctorId = null): array
    {
        $periods  = self::periods();
        $attended = ScheduleSituation::Attended->value;
        $noShow   = ScheduleSituation::NoShow->value;
        $cancel   = ScheduleSituation::Cancelled->value;
        $now      = now();

        $select = [];
        $params = [];

        foreach (['current', 'previous'] as $range) {
            $between = 'schedules.date_time BETWEEN ? AND ?';
            $bounds  = [
                Carbon::parse($periods[$range]['from'])->startOfDay(),
                Carbon::parse($periods[$range]['to'])->endOfDay(),
            ];

            $select[] = "SUM(CASE WHEN {$between} AND schedules.situation = ? THEN 1 ELSE 0 END) AS {$range}_attended";
            $select[] = "SUM(CASE WHEN {$between} AND schedules.situation = ? THEN 1 ELSE 0 END) AS {$range}_noshow";
            $select[] = "SUM(CASE WHEN {$between} AND schedules.situation = ? THEN 1 ELSE 0 END) AS {$range}_cancelled";
            $select[] = "SUM(CASE WHEN {$between} THEN 1 ELSE 0 END) AS {$range}_total";
            $select[] = "SUM(CASE WHEN {$between} AND schedules.date_time <= ? AND schedules.situation <> ? THEN 1 ELSE 0 END) AS {$range}_past_booked";
            $params   = [
                ...$params,
                ...$bounds, $attended,
                ...$bounds, $noShow,
                ...$bounds, $cancel,
                ...$bounds,
                ...$bounds, $now, $cancel,
            ];
        }

        $row = Schedule::query()
            ->where('schedules.entity_id', $entityId)
            ->when($doctorId !== null, fn ($q) => $q->where('schedules.doctor_id', $doctorId))
            ->whereBetween('schedules.date_time', [
                Carbon::parse($periods['previous']['from'])->startOfDay(),
                Carbon::parse($periods['current']['to'])->endOfDay(),
            ])
            ->toBase()
            ->selectRaw(implode(', ', $select), $params)
            ->first();

        $range = fn (string $name): array => $this->scheduleKpis(
            (int) ($row->{"{$name}_attended"} ?? 0),
            (int) ($row->{"{$name}_noshow"} ?? 0),
            (int) ($row->{"{$name}_cancelled"} ?? 0),
            (int) ($row->{"{$name}_total"} ?? 0),
            (int) ($row->{"{$name}_past_booked"} ?? 0),
        );

        return ['current' => $range('current'), 'previous' => $range('previous')];
    }

    /**
     * Consultas × faltas por dia nos últimos 30 dias (hoje incluso), dias sem
     * consulta com zero. Agregado em PHP (só data e situação são lidas) para
     * valer em qualquer banco suportado; em cache por clínica.
     *
     * @return array{days: list<array{date: string, attended: int, noshow: int, cancelled: int, total: int}>, generated_at: string}
     */
    public function daily(string $entityId): array
    {
        return Cache::remember(
            $this->key($entityId, 'daily.' . self::DAILY_DAYS),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($entityId): array {
                $to   = now()->startOfDay();
                $from = $to->copy()->subDays(self::DAILY_DAYS - 1);

                $days = [];

                for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
                    $days[$day->toDateString()] = ['date' => $day->toDateString(), 'attended' => 0, 'noshow' => 0, 'cancelled' => 0, 'total' => 0];
                }

                Schedule::query()
                    ->where('schedules.entity_id', $entityId)
                    ->whereBetween('schedules.date_time', [$from, $to->copy()->endOfDay()])
                    ->toBase()
                    ->get(['schedules.date_time', 'schedules.situation'])
                    ->each(function (object $row) use (&$days): void {
                        $date = Carbon::parse($row->date_time)->toDateString();

                        if (! isset($days[$date])) {
                            return;
                        }

                        $days[$date]['total']++;

                        $key = match ((int) $row->situation) {
                            ScheduleSituation::Attended->value  => 'attended',
                            ScheduleSituation::NoShow->value    => 'noshow',
                            ScheduleSituation::Cancelled->value => 'cancelled',
                            default                             => null,
                        };

                        if ($key !== null) {
                            $days[$date][$key]++;
                        }
                    });

                return ['days' => array_values($days), 'generated_at' => now()->toIso8601String()];
            },
        );
    }

    /**
     * Receita × despesa dos últimos 6 meses (ClinicBiService::trendSnapshot,
     * mesmo cache do BI).
     *
     * @return array{series: list<array<string, mixed>>, generated_at: string}
     */
    public function financeTrend(string $entityId): array
    {
        return $this->bi->trendSnapshot($entityId, self::TREND_MONTHS);
    }

    /**
     * Contas a receber na posição de hoje: lançamentos de receita pendentes no
     * caixa (vencidos = data antes de hoje) e guias de convênio enviadas
     * aguardando pagamento (vencidas = vencimento antes de hoje). Os links
     * abrem as listas com o mesmo recorte, para o número bater.
     *
     * @return array<string, mixed>
     */
    public function receivables(string $entityId): array
    {
        return Cache::remember(
            $this->key($entityId, 'receivables'),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($entityId): array {
                $today = now()->toDateString();

                $cash = FinancialCashEntry::query()
                    ->where('financial_cash_entries.entity_id', $entityId)
                    ->where('financial_cash_entries.type', FinancialEntryType::Income->value)
                    ->where('financial_cash_entries.status', FinancialEntryStatus::Pending->value)
                    ->toBase()
                    ->selectRaw('COALESCE(SUM(CASE WHEN entry_date < ? THEN amount ELSE 0 END), 0) AS overdue_amount', [$today])
                    ->selectRaw('COALESCE(SUM(CASE WHEN entry_date < ? THEN 1 ELSE 0 END), 0) AS overdue_count', [$today])
                    ->selectRaw('COALESCE(SUM(CASE WHEN entry_date >= ? THEN amount ELSE 0 END), 0) AS upcoming_amount', [$today])
                    ->selectRaw('COALESCE(SUM(CASE WHEN entry_date >= ? THEN 1 ELSE 0 END), 0) AS upcoming_count', [$today])
                    ->selectRaw('MIN(entry_date) AS first_date, MAX(entry_date) AS last_date')
                    ->first();

                $claims = BillingClaim::query()
                    ->where('billing_claims.entity_id', $entityId)
                    ->where('billing_claims.status', BillingClaimStatus::Submitted->value)
                    ->toBase()
                    ->selectRaw('COALESCE(SUM(amount), 0) AS open_amount, COUNT(*) AS open_count')
                    ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN amount ELSE 0 END), 0) AS overdue_amount', [$today])
                    ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) AS overdue_count', [$today])
                    ->selectRaw('MIN(attendance_date) AS first_date, MAX(attendance_date) AS last_date')
                    ->first();

                $cashFirst  = $this->dateOrNull($cash->first_date ?? null);
                $cashLast   = $this->dateOrNull($cash->last_date ?? null);
                $claimFirst = $this->dateOrNull($claims->first_date ?? null);
                $claimLast  = $this->dateOrNull($claims->last_date ?? null);
                $yesterday  = now()->subDay()->toDateString();

                $cashUpcoming = round((float) ($cash->upcoming_amount ?? 0), 2);
                $cashOverdue  = round((float) ($cash->overdue_amount ?? 0), 2);
                $claimsOpen   = round((float) ($claims->open_amount ?? 0), 2);

                return [
                    'cash' => [
                        'upcoming'       => $cashUpcoming,
                        'upcoming_count' => (int) ($cash->upcoming_count ?? 0),
                        'overdue'        => $cashOverdue,
                        'overdue_count'  => (int) ($cash->overdue_count ?? 0),
                        'url'            => $cashFirst ? route('panel.financial.cash-flow.index', ['type' => 'income', 'status' => 'pending', 'from' => $cashFirst, 'to' => $cashLast]) : null,
                        'overdue_url'    => $cashFirst && $cashFirst <= $yesterday ? route('panel.financial.cash-flow.index', ['type' => 'income', 'status' => 'pending', 'from' => $cashFirst, 'to' => $yesterday]) : null,
                    ],
                    'claims' => [
                        'open'          => $claimsOpen,
                        'open_count'    => (int) ($claims->open_count ?? 0),
                        'overdue'       => round((float) ($claims->overdue_amount ?? 0), 2),
                        'overdue_count' => (int) ($claims->overdue_count ?? 0),
                        'url'           => $claimFirst ? route('panel.financial.billing.index', ['tab' => 'claims', 'claim_status' => BillingClaimStatus::Submitted->value, 'from' => $claimFirst, 'to' => $claimLast]) : null,
                    ],
                    'total'        => round($cashUpcoming + $cashOverdue + $claimsOpen, 2),
                    'overdue'      => round($cashOverdue + (float) ($claims->overdue_amount ?? 0), 2),
                    'generated_at' => now()->toIso8601String(),
                ];
            },
        );
    }

    /**
     * Glosas a tratar (fila de glosas: abertas e em recurso de qualquer data,
     * prazos vencidos/vencendo) e o glosado no mês — GlosaQueueService::summary.
     *
     * @return array<string, mixed>
     */
    public function glosas(string $entityId): array
    {
        $periods = self::periods();

        return Cache::remember(
            $this->key($entityId, 'glosas'),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => [
                ...$this->glosaQueue->summary($entityId, null, $periods['current']['from'], $periods['current']['to']),
                'url'          => route('panel.financial.tiss.glosas.index', ['tab' => GlosaQueueService::TAB_PENDING]),
                'overdue_url'  => route('panel.financial.tiss.glosas.index', ['tab' => GlosaQueueService::TAB_PENDING, 'due' => GlosaQueueService::DUE_OVERDUE]),
                'due_soon_url' => route('panel.financial.tiss.glosas.index', ['tab' => GlosaQueueService::TAB_PENDING, 'due' => GlosaQueueService::DUE_SOON]),
                'generated_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Caixa de HOJE (vai no polling: uma consulta agregada, sem cache) —
     * mesmas regras do Fluxo de Caixa (CashFlowService::overview).
     *
     * @return array<string, mixed>
     */
    public function cashToday(string $entityId): array
    {
        $today = now()->toDateString();

        return [
            ...$this->cashFlow->overview($entityId, $today, $today),
            'url' => route('panel.financial.cash-flow.index', ['from' => $today, 'to' => $today]),
        ];
    }

    /**
     * "Atualizar" do Dashboard: descarta o cache desta clínica (resumos do BI
     * dos dois intervalos + tendência e os agregados do Dashboard).
     */
    public function forget(string $entityId, ?string $doctorId = null): void
    {
        $periods = self::periods();

        $this->bi->forget($entityId, $periods['current']['from'], $periods['current']['to'], self::TREND_MONTHS);
        $this->bi->forget($entityId, $periods['previous']['from'], $periods['previous']['to'], self::TREND_MONTHS);

        foreach (['clinic.month', 'daily.' . self::DAILY_DAYS, 'receivables', 'glosas'] as $name) {
            Cache::forget($this->key($entityId, $name));
        }

        if ($doctorId !== null) {
            Cache::forget($this->key($entityId, "doctor.{$doctorId}.month"));
        }
    }

    // ── internos ─────────────────────────────────────────────────────────────

    /**
     * Recorte do resumo do BI que o Dashboard usa. Taxas sem base (nenhuma
     * consulta comparável) vão null — "—" na tela, não um 0% enganoso.
     *
     * @param array<string, mixed> $kpis
     *
     * @return array<string, mixed>
     */
    private function monthKpis(array $kpis, bool $withFinance): array
    {
        $month = ['new_patients' => (int) ($kpis['new_patients'] ?? 0)];

        if (! $withFinance) {
            return $month;
        }

        return [
            ...$month,
            'income'       => (float) ($kpis['income'] ?? 0),
            'expense'      => (float) ($kpis['expense'] ?? 0),
            'balance'      => (float) ($kpis['balance'] ?? 0),
            'billed'       => (float) ($kpis['total_billed'] ?? 0),
            'paid'         => (float) ($kpis['total_paid'] ?? 0),
            'glosa'        => (float) ($kpis['total_glosa'] ?? 0),
            'ticket'       => (float) ($kpis['ticket_medio'] ?? 0),
            'receipt_rate' => (float) ($kpis['total_billed'] ?? 0) > 0 ? (float) ($kpis['receipt_rate'] ?? 0) : null,
        ];
    }

    /**
     * Mesmas fórmulas do BI: comparecimento = atendidos ÷ (atendidos +
     * faltas); taxa de falta = faltas ÷ (atendidos + faltas); ocupação =
     * atendidos ÷ agendamentos não cancelados — aqui só os que já aconteceram
     * (ver scheduleMonth()). Sem base → null ("—" na tela, não um 0%).
     *
     * @return array{attended: int, noshow: int, cancelled: int, total: int, attendance_rate: ?float, occupancy_rate: ?float, noshow_rate: ?float}
     */
    private function scheduleKpis(int $attended, int $noShow, int $cancelled, int $total, int $pastBooked): array
    {
        $comparable = $attended + $noShow;

        return [
            'attended'        => $attended,
            'noshow'          => $noShow,
            'cancelled'       => $cancelled,
            'total'           => $total,
            'attendance_rate' => $comparable > 0 ? round($attended / $comparable * 100, 1) : null,
            'occupancy_rate'  => $pastBooked > 0 ? round(min($attended, $pastBooked) / $pastBooked * 100, 1) : null,
            'noshow_rate'     => $comparable > 0 ? round($noShow / $comparable * 100, 1) : null,
        ];
    }

    /** Clínica (e o dia) sempre na chave — isolamento entre clínicas e virada do dia. */
    private function key(string $entityId, string $name): string
    {
        return 'dashboard.' . self::CACHE_VERSION . ".{$entityId}.{$name}." . now()->toDateString();
    }

    private function oldest(?string $first, ?string $second): string
    {
        $dates = array_filter([$first, $second]);

        if ($dates === []) {
            return now()->toIso8601String();
        }

        usort($dates, fn (string $a, string $b) => Carbon::parse($a) <=> Carbon::parse($b));

        return $dates[0];
    }

    private function dateOrNull(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}
