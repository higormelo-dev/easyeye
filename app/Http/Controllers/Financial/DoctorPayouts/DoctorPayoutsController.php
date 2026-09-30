<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\{PayoutItemData, ReceiptTraceData};
use App\Enums\DoctorPayout\{DoctorPayoutReceiptStatus, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutStatus, DoctorPayoutWarning};
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\{CloseDoctorPayoutRequest, DoctorPayoutReasonRequest};
use App\Models\DoctorPayout;
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutExporter, DoctorPayoutOptions, DoctorPayoutPresenter, DoctorPayoutProductionService, DoctorPayoutReceiptAllocationService, DoctorPayoutReceiptTracer};
use App\Support\{Money, ReportPeriod};
use Carbon\CarbonImmutable;
use Illuminate\Http\{RedirectResponse, Request, Response};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\{Collection, Str};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Financeiro › Repasse médico › Apuração: médico + período (data de
 * realização) → produção (pendente ao vivo + itens já fechados/pagos), KPIs,
 * resumo por tipo, itens paginados no servidor e a prévia do fechamento.
 * Cada item traz o rastreio de recebimento da clínica (somente leitura: não
 * muda a base nem o repasse).
 */
class DoctorPayoutsController extends Controller
{
    use AuthorizesDoctorPayouts;

    private const PER_PAGE = 25;

    private const ITEM_STATUSES = ['pending', 'awaiting', 'closed', 'partially_paid', 'paid', self::IN_PAYOUT];

    /**
     * Filtro "com recebimento": tudo menos a previsão (aguardando) — as
     * mesmas linhas que compõem o resumo por tipo.
     */
    private const IN_PAYOUT = 'in_payout';

    /** Situações de recebimento que ainda esperam dinheiro (KPI "A receber"). */
    private const RECEIPT_OPEN_STATUSES = [DoctorPayoutReceiptStatus::ToBill, DoctorPayoutReceiptStatus::Awaiting, DoctorPayoutReceiptStatus::Partial];

    public function __construct(
        private readonly DoctorPayoutCalculator $calculator,
        private readonly DoctorPayoutPresenter $presenter,
        private readonly DoctorPayoutOptions $options,
        private readonly DoctorPayoutExporter $exporter,
        private readonly DoctorPayoutReceiptTracer $tracer,
        private readonly DoctorPayoutReceiptAllocationService $allocations,
        private readonly DoctorPayoutProductionService $production,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;
        $doctors  = $this->options->doctors($entityId);

        [$from, $to, $capped] = $this->period($request);
        $doctorId             = $this->doctorFilter($request, $doctors);
        $status               = $this->choice($request, 'status', self::ITEM_STATUSES);
        $serviceType          = $this->choice($request, 'service_type', DoctorPayoutServiceType::values());
        $receipt              = $this->choice($request, 'receipt', DoctorPayoutReceiptStatus::values());
        $warning              = $this->choice($request, 'warning', array_column(DoctorPayoutWarning::cases(), 'value'));

        // Calculada uma vez e só quando pedida: a recarga parcial das receitas
        // do recebimento manual (only: manual_receipts) não refaz a apuração.
        $computed = null;
        $data     = function () use (&$computed, $entityId, $doctorId, $from, $to): ?array {
            if ($doctorId === null) {
                return null;
            }

            return $computed ??= $this->compute($entityId, $doctorId, $from, $to);
        };

        return Inertia::render('Panel/Financial/DoctorPayouts/Index', [
            'breadcrumbs' => $this->payoutBreadcrumbs(__('financial_doctor_payouts.tabs.apuracao')),
            'tabs'        => $this->payoutTabs(),
            'filters'     => [
                'doctor'       => $doctorId ?? '',
                'from'         => $from->toDateString(),
                'to'           => $to->toDateString(),
                'status'       => $status,
                'service_type' => $serviceType,
                'receipt'      => $receipt,
                'warning'      => $warning,
            ],
            'period_capped'   => $capped,
            'today'           => now()->toDateString(),
            'options'         => ['doctors' => $doctors],
            'selected_doctor' => $doctorId === null ? null : collect($doctors)->firstWhere('id', $doctorId),
            'kpis'            => fn () => $data()['kpis'] ?? null,
            'summary'         => fn () => $data()['summary'] ?? null,
            'items'           => fn () => $data() === null ? null : $this->paginate($this->filterRows($data()['rows'], $status, $serviceType, $receipt, $warning), $request),
            'close_preview'   => fn () => $data()['preview'] ?? null,
            // Exames do equipamento sem médico no período (não entram no
            // repasse de ninguém): aviso da clínica, não do médico escolhido.
            'unassigned_exams' => fn () => $this->production->unassignedExamCount($entityId, $from, $to),
            // Receitas avulsas com saldo para o recebimento manual: carregadas
            // só quando o modal de alocação pede (partial reload).
            'manual_receipts' => Inertia::optional(fn () => $this->allocations->eligibleEntries($entityId, $to)),
            'reason_limits'   => ['min' => DoctorPayoutReasonRequest::REASON_MIN, 'max' => DoctorPayoutReasonRequest::REASON_MAX],
            // Textos do ConfirmationWithReasonModal (estorno de alocação): os do
            // repasse (mínimo 10 caracteres), como no demonstrativo.
            't_hardening' => fn () => array_merge(
                (array) trans('manager_hardening'),
                (array) trans('financial_doctor_payouts.reason_modal'),
            ),
            'routes' => [
                'index'        => route('panel.financial.doctor-payouts.index'),
                'export'       => route('panel.financial.doctor-payouts.export'),
                'close'        => route('panel.financial.doctor-payouts.closings.store'),
                'rules'        => route('panel.financial.doctor-payouts.rules.index'),
                'closing_show' => route('panel.financial.doctor-payouts.closings.show', ['__ID__']),
                'allocate'     => route('panel.financial.doctor-payouts.allocations.store'),
                'allocation'   => route('panel.financial.doctor-payouts.allocations.destroy', ['__ID__']),
            ],
            't'      => trans('financial_doctor_payouts'),
            'shared' => trans('financial_shared'),
        ]);
    }

    public function export(Request $request): Response|RedirectResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;
        $doctors  = $this->options->doctors($entityId);

        [$from, $to] = $this->period($request);
        $doctorId    = $this->doctorFilter($request, $doctors);

        if ($doctorId === null) {
            return back()->with('error', __('financial_doctor_payouts.errors.doctor_required'));
        }

        $rows   = $this->compute($entityId, $doctorId, $from, $to)['rows'];
        $format = DoctorPayoutExporter::normalizeFormat($request->query('format'));
        $doctor = collect($doctors)->firstWhere('id', $doctorId);

        $this->exporter->audit($request, $entityId, 'doctor_payouts', $format, [
            'doctor_id' => $doctorId,
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
        ], count($rows));

        return $this->exporter->download(
            $format,
            $this->presenter->exportRows($rows, withReceipt: true),
            sprintf('%s_%s_%s_%s', __('financial_doctor_payouts.export_filename'), Str::slug((string) ($doctor['name'] ?? 'medico')), $from->toDateString(), $to->toDateString()),
            __('financial_doctor_payouts.title'),
        );
    }

    /**
     * Regime por recebimento: parcelas a liberar até o fim do período, atos
     * aguardando recebimento (previsão) e os itens dos fechamentos do período.
     *
     * @return array{rows: list<array<string, mixed>>, kpis: array<string, mixed>, summary: list<array<string, mixed>>, preview: array<string, mixed>}
     */
    private function compute(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        ['releases' => $releases, 'awaiting' => $awaiting] = $this->calculator->apuracao($entityId, $doctorId, $from, $to);

        $lastClosedUntil = $this->calculator->lastClosedUntil($entityId, $doctorId);

        // Período que termina antes do último fechamento: tudo o que foi
        // recebido até lá já foi consolidado nos fechamentos (fechar exige fim
        // ≥ último fechamento). As parcelas "a liberar" daqui seriam fantasmas
        // — devido até o fim do período menos o liberado DEPOIS dele — e o
        // saldo em aberto de verdade aparece na apuração do período atual.
        $historical = $lastClosedUntil !== null && $to->toDateString() < $lastClosedUntil;

        if ($historical) {
            $releases = collect();
        }

        $totals = DoctorPayoutCalculator::totals($releases);

        $rows = collect([
            ...$this->presenter->closedRows($entityId, $doctorId, $from, $to),
            ...$this->presenter->pendingRows($entityId, $releases),
            ...$this->presenter->pendingRows($entityId, $awaiting),
        ])
            ->sortBy([['date', 'asc'], ['row_id', 'asc']])
            ->values()
            ->all();

        // Rastreio de recebimento de TODAS as linhas do período (os KPIs somam
        // o período inteiro, não só a página) + recebimentos manuais de cada
        // item (do atendimento ou do próprio ato), para conferir e estornar.
        $traces = $this->tracer->trace($entityId, array_unique(array_column($rows, 'key')));
        $manual = $this->allocations->activeByTarget($entityId, [
            ...array_column($rows, 'key'),
            ...array_map(fn (ReceiptTraceData $trace) => $this->allocationUnitKey($trace), array_values($traces)),
        ]);

        $rows = array_map(function (array $row) use ($traces, $manual): array {
            $trace = $traces[$row['key']] ?? ReceiptTraceData::notLinked();
            $unit  = $this->allocationUnitKey($trace);

            return [
                ...$row,
                'receipt'            => $trace->toArray(),
                'manual_allocations' => array_values(array_unique([
                    ...($manual[$row['key']] ?? []),
                    ...($unit !== $row['key'] ? ($manual[$unit] ?? []) : []),
                ], SORT_REGULAR)),
            ];
        }, $rows);

        return [
            'rows'    => $rows,
            'kpis'    => [...$this->kpis($entityId, $rows, $totals['blocking']), 'receipt' => $this->receiptKpis($traces)],
            'summary' => $this->summary(array_values(array_filter($rows, fn (array $row) => $row['status'] !== 'awaiting'))),
            'preview' => [
                'period_start'      => $from->toDateString(),
                'period_end'        => $to->toDateString(),
                'count'             => $totals['count'],
                'charged_cents'     => $totals['charged_cents'],
                'payout_cents'      => $totals['payout_cents'],
                'blocking'          => $totals['blocking'],
                'warnings'          => $this->warningCounts($releases),
                'last_closed_until' => $lastClosedUntil,
                'historical'        => $historical,
                'can_close'         => $totals['count'] > 0
                    && $totals['blocking'] === 0
                    && $totals['payout_cents'] >= 0
                    && $to->toDateString() <= now()->toDateString()
                    && ($lastClosedUntil === null || $to->toDateString() >= $lastClosedUntil),
            ],
        ];
    }

    /**
     * Alvo de alocação do atendimento do item: "schedule:{id}" quando o item é
     * do agendamento (ou pareado com ele); a própria chave para ato com
     * recebimento só manual.
     */
    private function allocationUnitKey(ReceiptTraceData $trace): string
    {
        $unit = (string) $trace->unitId;

        return str_contains($unit, ':') ? $unit : DoctorPayoutSourceType::Schedule->value . ':' . $unit;
    }

    /**
     * Situação do repasse no período: a liberar (parcelas pendentes), já
     * fechado/pago e a PREVISÃO dos atos que ainda aguardam recebimento —
     * previsão e liberado nunca somam juntos.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int|float>
     */
    private function kpis(string $entityId, array $rows, int $blocking): array
    {
        $of    = fn (string $status) => array_filter($rows, fn (array $row) => $row['status'] === $status);
        $sum   = fn (array $subset, string $field) => array_sum(array_map(fn (array $row) => Money::toCents($row[$field] ?? 0), $subset)) / 100;
        $count = fn (string $status) => count($of($status));

        [$paid, $toPay, $adjustments] = $this->payoutBalances($entityId, $rows);

        $toRelease = $sum($of('pending'), 'payout');

        return [
            // Atos distintos: um ato recebido em parcelas aparece em mais de uma linha.
            'production_count' => $this->actCount($rows),
            'to_release'       => $toRelease,
            'release_base'     => $sum($of('pending'), 'charged'),
            'release_count'    => $count('pending'),
            'paid'             => $paid,
            'to_pay'           => $toPay,
            // Ajustes manuais dos fechamentos listados (já dentro de pago/a pagar).
            'adjustments' => $adjustments,
            // Total a repassar = a liberar + fechado ainda não pago (em centavos).
            'to_transfer'       => (Money::toCents($toRelease) + Money::toCents($toPay)) / 100,
            'awaiting_count'    => $this->actCount(array_values($of('awaiting'))),
            'awaiting_forecast' => $sum($of('awaiting'), 'forecast'),
            'no_rule'           => $blocking,
        ];
    }

    /**
     * Já pago e a pagar dos fechamentos que aparecem na apuração, pelo que
     * foi pago de fato (pagamentos parciais, E5): pago = pagamentos válidos;
     * a pagar = total do fechamento (itens + ajustes) − pago; e a soma dos
     * ajustes manuais desses fechamentos (o resumo por tipo só soma itens).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function payoutBalances(string $entityId, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (array $row) => $row['payout_id'] ?? null, $rows))));

        if ($ids === []) {
            return [0.0, 0.0, 0.0];
        }

        $paid        = 0;
        $toPay       = 0;
        $adjustments = 0;

        DoctorPayout::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', $ids)
            ->whereIn('status', DoctorPayoutStatus::valid())
            ->get(['id', 'total_amount', 'paid_amount', 'adjustments_amount'])
            ->each(function (DoctorPayout $payout) use (&$paid, &$toPay, &$adjustments): void {
                $done = Money::toCents($payout->paid_amount ?? 0);

                $paid += $done;
                $toPay += max(0, Money::toCents($payout->total_amount) - $done);
                $adjustments += Money::toCents($payout->adjustments_amount ?? 0);
            });

        return [$paid / 100, $toPay / 100, $adjustments / 100];
    }

    /**
     * Atos distintos das linhas (um ato recebido em parcelas tem uma linha
     * por parcela).
     *
     * @param list<array<string, mixed>> $rows
     */
    private function actCount(array $rows): int
    {
        return count(array_unique(array_column($rows, 'key')));
    }

    /**
     * Recebimento da clínica no período, em centavos, contando cada
     * atendimento UMA vez (procedimentos pareados trazem o atendimento
     * inteiro). Contagens de "aguardando" e "paga sem lançamento" são de
     * atendimentos; "sem cobrança própria", de itens.
     *
     * @param array<string, ReceiptTraceData> $traces
     *
     * @return array<string, int|float>
     */
    private function receiptKpis(array $traces): array
    {
        $units = [];

        foreach ($traces as $trace) {
            if ($trace->isLinked() && $trace->unitId !== null) {
                $units[$trace->unitId] = $trace;
            }
        }

        $sum   = fn (string $field) => array_sum(array_map(fn (ReceiptTraceData $trace) => $trace->{$field}, $units)) / 100;
        $count = fn (array $statuses) => count(array_filter($units, fn (ReceiptTraceData $trace) => in_array($trace->status, $statuses, true)));

        return [
            'billed'            => $sum('billedCents'),
            'glosa'             => $sum('glosaCents'),
            'received'          => $sum('receivedCents'),
            'open'              => $sum('openCents'),
            'difference'        => $sum('differenceCents'),
            'open_count'        => $count(self::RECEIPT_OPEN_STATUSES),
            'unconfirmed_count' => $count([DoctorPayoutReceiptStatus::Unconfirmed]),
            'not_linked_count'  => count(array_filter($traces, fn (ReceiptTraceData $trace) => ! $trace->isLinked())),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{service_type: string, count: int, charged: float, payout: float}>
     */
    private function summary(array $rows): array
    {
        return collect(DoctorPayoutServiceType::cases())
            ->map(function (DoctorPayoutServiceType $type) use ($rows): array {
                $typeRows = array_filter($rows, fn (array $row) => $row['service_type'] === $type->value);

                return [
                    'service_type' => $type->value,
                    'count'        => $this->actCount(array_values($typeRows)),
                    'charged'      => array_sum(array_map(fn (array $row) => Money::toCents($row['charged']), $typeRows)) / 100,
                    'payout'       => array_sum(array_map(fn (array $row) => Money::toCents($row['payout']), $typeRows)) / 100,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, PayoutItemData> $items
     *
     * @return array<string, int>
     */
    private function warningCounts(Collection $items): array
    {
        return collect(DoctorPayoutWarning::cases())
            ->mapWithKeys(fn (DoctorPayoutWarning $warning) => [
                $warning->value => $items->filter(fn (PayoutItemData $item) => $item->hasWarning($warning))->count(),
            ])
            ->filter()
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function filterRows(array $rows, string $status, string $serviceType, string $receipt, string $warning = ''): array
    {
        return array_values(array_filter($rows, fn (array $row) => $this->statusMatches($row['status'], $status)
            && ($serviceType === '' || $row['service_type'] === $serviceType)
            && ($receipt === '' || $row['receipt']['status'] === $receipt)
            && ($warning === '' || in_array($warning, (array) ($row['warnings'] ?? []), true))));
    }

    private function statusMatches(string $rowStatus, string $status): bool
    {
        return match ($status) {
            ''              => true,
            self::IN_PAYOUT => $rowStatus !== 'awaiting',
            default         => $rowStatus === $status,
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function paginate(array $rows, Request $request): LengthAwarePaginator
    {
        $page     = is_numeric($request->query('page')) ? max(1, (int) $request->query('page')) : 1;
        $lastPage = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page     = min($page, $lastPage);

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($rows),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * Período da query (inválido → mês atual) limitado a 366 dias, como os
     * relatórios financeiros; o corte é devolvido para a tela avisar.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: array<string, mixed>|null}
     */
    private function period(Request $request): array
    {
        [$from, $to] = ReportPeriod::resolve($request->query('from'), $request->query('to'));

        $earliest = CarbonImmutable::parse($to)->subDays(CloseDoctorPayoutRequest::MAX_PERIOD_DAYS - 1)->toDateString();

        if ($from >= $earliest) {
            return [CarbonImmutable::parse($from), CarbonImmutable::parse($to), null];
        }

        return [CarbonImmutable::parse($earliest), CarbonImmutable::parse($to), [
            'requested_from' => $from,
            'requested_to'   => $to,
            'max_days'       => CloseDoctorPayoutRequest::MAX_PERIOD_DAYS,
        ]];
    }

    /** @param list<array{id: string}> $doctors */
    private function doctorFilter(Request $request, array $doctors): ?string
    {
        $doctor = $request->query('doctor');

        return is_string($doctor) && in_array($doctor, array_column($doctors, 'id'), true) ? $doctor : null;
    }

    /** @param list<string> $allowed */
    private function choice(Request $request, string $key, array $allowed): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}
