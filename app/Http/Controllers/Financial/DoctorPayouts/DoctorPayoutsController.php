<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutServiceType, DoctorPayoutWarning};
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\CloseDoctorPayoutRequest;
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutExporter, DoctorPayoutOptions, DoctorPayoutPresenter};
use App\Support\{Money, ReportPeriod};
use Carbon\CarbonImmutable;
use Illuminate\Http\{RedirectResponse, Request, Response};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\{Collection, Str};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Financeiro › Repasse médico › Apuração: médico + período → produção
 * (pendente ao vivo + itens já fechados/pagos), KPIs, resumo por tipo,
 * itens paginados no servidor e a prévia do fechamento.
 */
class DoctorPayoutsController extends Controller
{
    use AuthorizesDoctorPayouts;

    private const PER_PAGE = 25;

    private const ITEM_STATUSES = ['pending', 'closed', 'paid'];

    public function __construct(
        private readonly DoctorPayoutCalculator $calculator,
        private readonly DoctorPayoutPresenter $presenter,
        private readonly DoctorPayoutOptions $options,
        private readonly DoctorPayoutExporter $exporter,
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

        $data = $doctorId === null ? null : $this->compute($entityId, $doctorId, $from, $to);

        return Inertia::render('Panel/Financial/DoctorPayouts/Index', [
            'breadcrumbs' => $this->payoutBreadcrumbs(__('financial_doctor_payouts.tabs.apuracao')),
            'tabs'        => $this->payoutTabs(),
            'filters'     => [
                'doctor'       => $doctorId ?? '',
                'from'         => $from->toDateString(),
                'to'           => $to->toDateString(),
                'status'       => $status,
                'service_type' => $serviceType,
            ],
            'period_capped'   => $capped,
            'today'           => now()->toDateString(),
            'options'         => ['doctors' => $doctors],
            'selected_doctor' => $doctorId === null ? null : collect($doctors)->firstWhere('id', $doctorId),
            'kpis'            => $data['kpis'] ?? null,
            'summary'         => $data['summary'] ?? null,
            'items'           => $data === null ? null : $this->paginate($this->filterRows($data['rows'], $status, $serviceType), $request),
            'close_preview'   => $data['preview'] ?? null,
            'routes'          => [
                'index'        => route('panel.financial.doctor-payouts.index'),
                'export'       => route('panel.financial.doctor-payouts.export'),
                'close'        => route('panel.financial.doctor-payouts.closings.store'),
                'rules'        => route('panel.financial.doctor-payouts.rules.index'),
                'closing_show' => route('panel.financial.doctor-payouts.closings.show', ['__ID__']),
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
            $this->presenter->exportRows($rows),
            sprintf('%s_%s_%s_%s', __('financial_doctor_payouts.export_filename'), Str::slug((string) ($doctor['name'] ?? 'medico')), $from->toDateString(), $to->toDateString()),
            __('financial_doctor_payouts.title'),
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, kpis: array<string, mixed>, summary: list<array<string, mixed>>, preview: array<string, mixed>}
     */
    private function compute(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pending = $this->calculator->pending($entityId, $doctorId, $from, $to);
        $totals  = DoctorPayoutCalculator::totals($pending);

        $rows = collect([
            ...$this->presenter->closedRows($entityId, $doctorId, $from, $to),
            ...$this->presenter->pendingRows($entityId, $pending),
        ])
            ->sortBy([['date', 'asc'], ['key', 'asc']])
            ->values()
            ->all();

        return [
            'rows'    => $rows,
            'kpis'    => $this->kpis($rows, $totals['blocking']),
            'summary' => $this->summary($rows),
            'preview' => [
                'period_start'  => $from->toDateString(),
                'period_end'    => $to->toDateString(),
                'count'         => $totals['count'],
                'charged_cents' => $totals['charged_cents'],
                'payout_cents'  => $totals['payout_cents'],
                'blocking'      => $totals['blocking'],
                'warnings'      => $this->warningCounts($pending),
                'can_close'     => $totals['count'] > 0
                    && $totals['blocking'] === 0
                    && $to->toDateString() <= now()->toDateString(),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int|float>
     */
    private function kpis(array $rows, int $blocking): array
    {
        $sum = fn (?string $status, string $field) => array_sum(array_map(
            fn (array $row) => $status === null || $row['status'] === $status ? Money::toCents($row[$field]) : 0,
            $rows,
        )) / 100;

        return [
            'production_count' => count($rows),
            'charged'          => $sum(null, 'charged'),
            'payout_total'     => $sum(null, 'payout'),
            'paid'             => $sum('paid', 'payout'),
            'to_pay'           => $sum('closed', 'payout'),
            'pending'          => $sum('pending', 'payout'),
            'no_rule'          => $blocking,
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
                    'count'        => count($typeRows),
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
    private function filterRows(array $rows, string $status, string $serviceType): array
    {
        return array_values(array_filter($rows, fn (array $row) => ($status === '' || $row['status'] === $status)
            && ($serviceType === '' || $row['service_type'] === $serviceType)));
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
