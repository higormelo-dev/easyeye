<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutServiceType, DoctorPayoutStatus};
use App\Models\DoctorPayout;
use App\Services\Financial\CovenantReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\{Collection, Number};
use Illuminate\Support\Facades\DB;

/**
 * Formata itens e fechamentos de repasse para a tela, a exportação e o PDF —
 * uma única forma de linha para item pendente (calculado ao vivo) e item
 * fechado (retrato), para a apuração misturar os dois sem ramificar a UI.
 */
final class DoctorPayoutPresenter
{
    public function __construct(
        private readonly CovenantReportService $covenantReport,
    ) {
    }

    /**
     * @param Collection<int, PayoutItemData> $items
     *
     * @return list<array<string, mixed>>
     */
    public function pendingRows(string $entityId, Collection $items): array
    {
        $patients = $this->patients($entityId, $items->pluck('patientId')->all());

        return $items->map(fn (PayoutItemData $item) => [
            'key'           => $item->key(),
            'date'          => $item->performedAt->format('Y-m-d\TH:i:s'),
            'patient_id'    => $item->patientId,
            'patient_name'  => $patients[$item->patientId]['name'] ?? null,
            'patient_code'  => $patients[$item->patientId]['code'] ?? null,
            'service_type'  => $item->serviceType->value,
            'description'   => $item->description,
            'covenant_name' => $item->covenantName,
            'is_particular' => $item->isParticular,
            'charged'       => $item->baseCents / 100,
            'base_source'   => $item->baseSource->value,
            'rule'          => $item->ruleCalculation === null ? null : [
                'calculation' => $item->ruleCalculation->value,
                'percentage'  => $item->rulePercentage === null ? null : (float) $item->rulePercentage,
                'fixed'       => $item->ruleFixedCents === null ? null : $item->ruleFixedCents / 100,
            ],
            'payout'      => $item->payoutCents / 100,
            'status'      => 'pending',
            'payout_id'   => null,
            'payout_code' => null,
            'warnings'    => array_map(fn ($warning) => $warning->value, $item->warnings),
        ])->values()->all();
    }

    /**
     * Itens fechados/pagos do médico no período (retrato — não recalcula).
     *
     * @return list<array<string, mixed>>
     */
    public function closedRows(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('doctor_payout_items as i')
            ->join('doctor_payouts as dp', 'dp.id', '=', 'i.doctor_payout_id')
            ->where('i.entity_id', $entityId)
            ->where('dp.entity_id', $entityId)
            ->where('dp.doctor_id', $doctorId)
            ->whereIn('dp.status', [DoctorPayoutStatus::Closed->value, DoctorPayoutStatus::Paid->value])
            ->whereNull('i.voided_at')
            ->whereBetween('i.performed_at', [$from->startOfDay()->format('Y-m-d H:i:s'), $to->endOfDay()->format('Y-m-d H:i:s')])
            ->orderBy('i.performed_at')
            ->orderBy('i.id')
            ->get(['i.*', 'dp.code as payout_code', 'dp.status as payout_status']);

        return $this->itemRows($entityId, $rows);
    }

    /**
     * @param Collection<int, object> $rows linhas de doctor_payout_items (+ payout_code/payout_status)
     *
     * @return list<array<string, mixed>>
     */
    public function itemRows(string $entityId, Collection $rows): array
    {
        $patients = $this->patients($entityId, $rows->pluck('patient_id')->all());

        return $rows->map(fn (object $row) => [
            'key'           => $row->source_type . ':' . $row->source_id,
            'date'          => CarbonImmutable::parse($row->performed_at)->format('Y-m-d\TH:i:s'),
            'patient_id'    => $row->patient_id,
            'patient_name'  => $patients[$row->patient_id]['name'] ?? null,
            'patient_code'  => $patients[$row->patient_id]['code'] ?? null,
            'service_type'  => $row->service_type,
            'description'   => $row->description,
            'covenant_name' => $row->covenant_name,
            'is_particular' => (bool) $row->is_particular,
            'charged'       => (float) $row->base_amount,
            'base_source'   => $row->base_source,
            'rule'          => $row->rule_calculation === null ? null : [
                'calculation' => $row->rule_calculation,
                'percentage'  => $row->rule_percentage === null ? null : (float) $row->rule_percentage,
                'fixed'       => $row->rule_fixed_amount === null ? null : (float) $row->rule_fixed_amount,
            ],
            'payout'      => (float) $row->payout_amount,
            'status'      => $row->payout_status ?? DoctorPayoutStatus::Closed->value,
            'payout_id'   => $row->doctor_payout_id,
            'payout_code' => $row->payout_code ?? null,
            'warnings'    => $row->warnings ? (array) json_decode((string) $row->warnings, true) : [],
        ])->values()->all();
    }

    /**
     * Cabeçalho de um fechamento (listagens, demonstrativo, PDF).
     *
     * @return array<string, mixed>
     */
    public function payoutSummary(DoctorPayout $payout, array $userNames = []): array
    {
        return [
            'id'                       => $payout->id,
            'code'                     => $payout->code,
            'doctor_id'                => $payout->doctor_id,
            'doctor_name'              => $payout->doctor_name,
            'doctor_record'            => $payout->doctor_record,
            'period_start'             => $payout->period_start->toDateString(),
            'period_end'               => $payout->period_end->toDateString(),
            'status'                   => $payout->status->value,
            'items_count'              => $payout->items_count,
            'gross_amount'             => (float) $payout->gross_amount,
            'items_amount'             => (float) $payout->items_amount,
            'adjustments_amount'       => (float) $payout->adjustments_amount,
            'total_amount'             => (float) $payout->total_amount,
            'closed_at'                => $payout->closed_at?->toIso8601String(),
            'closed_by_name'           => $userNames[$payout->closed_by] ?? null,
            'paid_at'                  => $payout->paid_at?->toDateString(),
            'paid_amount'              => $payout->paid_amount === null ? null : (float) $payout->paid_amount,
            'payment_method'           => $payout->payment_method?->value,
            'payment_notes'            => $payout->payment_notes,
            'cash_entry_id'            => $payout->cash_entry_id,
            'cancel_reason'            => $payout->cancel_reason,
            'cancelled_at'             => $payout->cancelled_at?->toIso8601String(),
            'cancelled_by_name'        => $userNames[$payout->cancelled_by] ?? null,
            'payment_reversal_reason'  => $payout->payment_reversal_reason,
            'payment_reversed_at'      => $payout->payment_reversed_at?->toIso8601String(),
            'payment_reversed_by_name' => $userNames[$payout->payment_reversed_by] ?? null,
            'notes'                    => $payout->notes,
        ];
    }

    /**
     * Demonstrativo: cabeçalho, itens agrupados por tipo (com subtotais) e ajustes.
     *
     * @return array{payout: array<string, mixed>, groups: list<array<string, mixed>>, adjustments: list<array<string, mixed>>}
     */
    public function statement(DoctorPayout $payout): array
    {
        $rows = DB::table('doctor_payout_items')
            ->where('doctor_payout_id', $payout->id)
            ->where('entity_id', $payout->entity_id)
            ->orderBy('performed_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($payout): void {
                $row->payout_code   = $payout->code;
                $row->payout_status = $payout->status->value;
            });

        $items = $this->itemRows((string) $payout->entity_id, $rows);

        $groups = collect(DoctorPayoutServiceType::cases())
            ->map(function (DoctorPayoutServiceType $type) use ($items): array {
                $groupItems = array_values(array_filter($items, fn (array $row) => $row['service_type'] === $type->value));

                return [
                    'service_type' => $type->value,
                    'count'        => count($groupItems),
                    'charged'      => $this->sum($groupItems, 'charged'),
                    'payout'       => $this->sum($groupItems, 'payout'),
                    'items'        => $groupItems,
                ];
            })
            ->filter(fn (array $group) => $group['count'] > 0)
            ->values()
            ->all();

        $adjustments = DB::table('doctor_payout_adjustments')
            ->where('doctor_payout_id', $payout->id)
            ->where('entity_id', $payout->entity_id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get(['id', 'description', 'amount', 'created_at', 'created_by']);

        $userNames = $this->userNames([
            $payout->closed_by,
            $payout->cancelled_by,
            $payout->payment_reversed_by,
            ...$adjustments->pluck('created_by')->all(),
        ]);

        return [
            'payout'      => $this->payoutSummary($payout, $userNames),
            'groups'      => $groups,
            'adjustments' => $adjustments->map(fn (object $row) => [
                'id'              => $row->id,
                'description'     => $row->description,
                'amount'          => (float) $row->amount,
                'created_at'      => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                'created_by_name' => $userNames[$row->created_by] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * Linhas de planilha: paciente só como código + iniciais (minimização LGPD,
     * como no relatório de convênios).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<list<mixed>>
     */
    public function exportRows(array $rows): array
    {
        $columns = (array) trans('financial_doctor_payouts.export_columns');

        $header = array_map(fn (string $key) => $columns[$key] ?? $key, [
            'date', 'patient_code', 'patient', 'service_type', 'description', 'payer', 'charged',
            'base_source', 'rule', 'payout', 'status', 'closing', 'warnings',
        ]);

        $lines = array_map(fn (array $row) => [
            CarbonImmutable::parse($row['date'])->isoFormat('L'),
            $row['patient_code'] ?? '',
            $this->covenantReport->initials($row['patient_name'] ?? null),
            __("financial_doctor_payouts.service_types.{$row['service_type']}"),
            $row['description'],
            $this->payerLabel($row),
            (float) $row['charged'],
            __("financial_doctor_payouts.base_sources.{$row['base_source']}"),
            $this->ruleLabel($row['rule']),
            (float) $row['payout'],
            __("financial_doctor_payouts.statuses.{$row['status']}"),
            $row['payout_code'] ?? '',
            implode(', ', array_map(fn (string $warning) => __("financial_doctor_payouts.warnings.{$warning}"), $row['warnings'])),
        ], $rows);

        return [$header, ...$lines];
    }

    /** @param array<string, mixed> $row */
    public function payerLabel(array $row): string
    {
        return $row['covenant_name'] ?? __('financial_doctor_payouts.particular');
    }

    /** @param array{calculation: string, percentage: ?float, fixed: ?float}|null $rule */
    public function ruleLabel(?array $rule): string
    {
        if ($rule === null) {
            return __('financial_doctor_payouts.rule_none');
        }

        $locale = app()->getLocale();

        return $rule['calculation'] === 'percentage'
            ? __('financial_doctor_payouts.rule_percentage', ['value' => Number::format((float) $rule['percentage'], maxPrecision: 2, locale: $locale)])
            : __('financial_doctor_payouts.rule_fixed', ['value' => Number::currency((float) $rule['fixed'], 'BRL', $locale)]);
    }

    /**
     * Nome e código dos pacientes (só da clínica).
     *
     * @param list<?string> $ids
     *
     * @return array<string, array{name: ?string, code: ?string}>
     */
    public function patients(string $entityId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return DB::table('patients as pt')
            ->leftJoin('people as p', 'p.id', '=', 'pt.person_id')
            ->where('pt.entity_id', $entityId)
            ->whereIn('pt.id', $ids)
            ->get(['pt.id', 'pt.code', 'p.full_name'])
            ->mapWithKeys(fn (object $row) => [(string) $row->id => ['name' => $row->full_name, 'code' => $row->code]])
            ->all();
    }

    /**
     * @param list<?string> $ids
     *
     * @return array<string, string>
     */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function sum(array $rows, string $field): float
    {
        return array_sum(array_map(fn (array $row) => Money::toCents($row[$field]), $rows)) / 100;
    }
}
