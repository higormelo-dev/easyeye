<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutBasis, DoctorPayoutBeneficiaryRole, DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutReceiptStatus, DoctorPayoutServiceType, DoctorPayoutStatus};
use App\Models\{DoctorPayout, DoctorPayoutRule};
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
        $scopes   = $this->ruleScopes($entityId, $items->pluck('ruleId')->all());

        return $items->map(fn (PayoutItemData $item) => [
            'key'           => $item->key(),
            'row_id'        => $item->key() . '#' . $item->tranche,
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
            'rule_id'              => $item->ruleId,
            'rule_scope'           => $scopes[(string) $item->ruleId] ?? null,
            'payout'               => $item->payoutCents / 100,
            'basis'                => DoctorPayoutBasis::Receipt->value,
            'tranche'              => $item->tranche,
            'received'             => $item->receivedCents === null ? null : $item->receivedCents / 100,
            'expected'             => $item->expectedCents === null ? null : $item->expectedCents / 100,
            'released_before'      => $item->releasedBeforeCents / 100,
            'forecast'             => $item->forecastCents === null ? null : $item->forecastCents / 100,
            'beneficiary_role'     => $item->beneficiaryRole?->value,
            'share_percentage'     => $item->sharePercentage === null ? null : (float) $item->sharePercentage,
            'net'                  => $item->netCents === null ? null : $item->netCents / 100,
            'deductions'           => $item->deductionsCents / 100,
            'deductions_breakdown' => $this->deductionsBreakdown($item->split['deductions'] ?? null),
            'status'               => $item->isRelease() ? 'pending' : 'awaiting',
            'payout_id'            => null,
            'payout_code'          => null,
            'warnings'             => array_map(fn ($warning) => $warning->value, $item->warnings),
        ])->values()->all();
    }

    /**
     * Itens dos fechamentos (fechados/pagos) do médico cujo período cruza o
     * período pedido — retrato, não recalcula. No regime por recebimento o
     * período do fechamento é o dos recebimentos.
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
            ->whereIn('dp.status', DoctorPayoutStatus::valid())
            ->whereNull('i.voided_at')
            ->whereDate('dp.period_start', '<=', $to->toDateString())
            ->whereDate('dp.period_end', '>=', $from->toDateString())
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
        $scopes   = $this->ruleScopes($entityId, $rows->pluck('doctor_payout_rule_id')->all());

        return $rows->map(fn (object $row) => [
            'key'           => $row->source_type . ':' . $row->source_id,
            'row_id'        => (string) $row->id,
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
            'rule_id'              => $row->doctor_payout_rule_id ?? null,
            'rule_scope'           => $scopes[(string) ($row->doctor_payout_rule_id ?? '')] ?? null,
            'payout'               => (float) $row->payout_amount,
            'basis'                => $row->basis ?? DoctorPayoutBasis::Production->value,
            'tranche'              => (int) ($row->tranche ?? 1),
            'received'             => $row->received_amount === null ? null : (float) $row->received_amount,
            'expected'             => $row->expected_amount === null ? null : (float) $row->expected_amount,
            'released_before'      => (float) ($row->released_before_amount ?? 0),
            'forecast'             => null,
            'beneficiary_role'     => $row->beneficiary_role ?? null,
            'share_percentage'     => isset($row->share_percentage) ? (float) $row->share_percentage : null,
            'net'                  => isset($row->net_amount) ? (float) $row->net_amount : null,
            'deductions'           => (float) ($row->deductions_amount ?? 0),
            'deductions_breakdown' => $this->deductionsBreakdown(
                isset($row->split) && $row->split !== null ? ((array) json_decode((string) $row->split, true))['deductions'] ?? null : null,
            ),
            'status'      => $row->payout_status ?? DoctorPayoutStatus::Closed->value,
            'payout_id'   => $row->doctor_payout_id,
            'payout_code' => $row->payout_code ?? null,
            'warnings'    => $row->warnings ? (array) json_decode((string) $row->warnings, true) : [],
        ])->values()->all();
    }

    /**
     * Cabeçalho de um fechamento (listagens, demonstrativo, PDF). `$forDoctor`
     * ("Meus repasses"): sem o estorno do regime anterior (motivo interno) e
     * sem nomes de quem fechou/cancelou — ficam só para a clínica.
     *
     * @return array<string, mixed>
     */
    public function payoutSummary(DoctorPayout $payout, array $userNames = [], bool $forDoctor = false): array
    {
        $summary = [
            'id'                 => $payout->id,
            'code'               => $payout->code,
            'doctor_id'          => $payout->doctor_id,
            'doctor_name'        => $payout->doctor_name,
            'doctor_record'      => $payout->doctor_record,
            'period_start'       => $payout->period_start->toDateString(),
            'period_end'         => $payout->period_end->toDateString(),
            'status'             => $payout->status->value,
            'basis'              => $payout->basis->value,
            'items_count'        => $payout->items_count,
            'gross_amount'       => (float) $payout->gross_amount,
            'items_amount'       => (float) $payout->items_amount,
            'adjustments_amount' => (float) $payout->adjustments_amount,
            'total_amount'       => (float) $payout->total_amount,
            'closed_at'          => $payout->closed_at?->toIso8601String(),
            'closed_by_name'     => $userNames[$payout->closed_by] ?? null,
            // Pagamentos (E5): paid_at = último pagamento válido; paid_amount =
            // soma dos válidos; remaining_amount = saldo a pagar.
            'paid_at'                  => $payout->paid_at?->toDateString(),
            'paid_amount'              => $payout->paid_amount === null ? null : (float) $payout->paid_amount,
            'remaining_amount'         => $payout->isCancelled() ? 0.0 : (Money::toCents($payout->total_amount) - Money::toCents($payout->paid_amount ?? 0)) / 100,
            'cancel_reason'            => $payout->cancel_reason,
            'cancelled_at'             => $payout->cancelled_at?->toIso8601String(),
            'cancelled_by_name'        => $userNames[$payout->cancelled_by] ?? null,
            'payment_reversal_reason'  => $payout->payment_reversal_reason,
            'payment_reversed_at'      => $payout->payment_reversed_at?->toIso8601String(),
            'payment_reversed_by_name' => $userNames[$payout->payment_reversed_by] ?? null,
            'notes'                    => $payout->notes,
        ];

        // Médico: sem o estorno do regime anterior nem nomes da equipe (quem
        // fechou/cancelou) — minimização; o que importa a ele são valores e datas.
        return $forDoctor
            ? [
                ...$summary,
                'closed_by_name'           => null,
                'cancelled_by_name'        => null,
                'payment_reversal_reason'  => null,
                'payment_reversed_at'      => null,
                'payment_reversed_by_name' => null,
            ]
            : $summary;
    }

    /**
     * Demonstrativo: cabeçalho, itens agrupados por tipo (com subtotais),
     * ajustes e pagamentos. `$forDoctor` ("Meus repasses"): só os pagamentos
     * válidos, com data, valor e forma — sem observações de pagamento, nomes
     * da equipe nem estornos.
     *
     * @return array{payout: array<string, mixed>, groups: list<array<string, mixed>>, adjustments: list<array<string, mixed>>, payments: list<array<string, mixed>>}
     */
    public function statement(DoctorPayout $payout, bool $forDoctor = false): array
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

        if ($forDoctor) {
            $items = array_map(fn (array $row) => $this->minimizeForParticipant($row), $items);
        }

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

        $payments = DB::table('doctor_payout_payments')
            ->where('doctor_payout_id', $payout->id)
            ->where('entity_id', $payout->entity_id)
            ->when($forDoctor, fn ($query) => $query->whereNull('reversed_at'))
            ->orderBy('paid_at')
            ->orderBy('created_at')
            ->get(['id', 'amount', 'paid_at', 'payment_method', 'notes', 'cash_entry_id', 'created_by', 'created_at', 'reversed_at', 'reversed_by', 'reversal_reason']);

        $userNames = $forDoctor ? [] : $this->userNames([
            $payout->closed_by,
            $payout->cancelled_by,
            $payout->payment_reversed_by,
            ...$adjustments->pluck('created_by')->all(),
            ...$payments->pluck('created_by')->all(),
            ...$payments->pluck('reversed_by')->all(),
        ]);

        return [
            'payout'      => $this->payoutSummary($payout, $userNames, $forDoctor),
            'groups'      => $groups,
            'adjustments' => $adjustments->map(fn (object $row) => [
                'id'              => $row->id,
                'description'     => $row->description,
                'amount'          => (float) $row->amount,
                'created_at'      => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                'created_by_name' => $userNames[$row->created_by] ?? null,
            ])->values()->all(),
            'payments' => $payments->map(fn (object $row) => $this->paymentRow($row, $userNames, $forDoctor))->values()->all(),
        ];
    }

    /**
     * "Meus repasses": ato em que o médico só PARTICIPA da divisão (não
     * atendeu — ex.: líder da equipe) mostra o paciente por iniciais + código
     * (minimização LGPD, como a planilha); nos próprios atendimentos, o nome.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function minimizeForParticipant(array $row): array
    {
        if (($row['beneficiary_role'] ?? null) !== DoctorPayoutBeneficiaryRole::Doctor->value) {
            return $row;
        }

        return [...$row, 'patient_name' => $this->covenantReport->initials($row['patient_name'] ?? null)];
    }

    /**
     * @param array<string, string> $userNames
     *
     * @return array<string, mixed>
     */
    private function paymentRow(object $row, array $userNames, bool $forDoctor): array
    {
        $base = [
            'id'             => $row->id,
            'paid_at'        => substr((string) $row->paid_at, 0, 10),
            'amount'         => (float) $row->amount,
            'payment_method' => $row->payment_method,
        ];

        if ($forDoctor) {
            return $base;
        }

        return $base + [
            'notes'            => $row->notes,
            'has_cash_entry'   => $row->cash_entry_id !== null,
            'paid_by_name'     => $userNames[$row->created_by] ?? null,
            'created_at'       => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'reversed_at'      => $row->reversed_at === null ? null : CarbonImmutable::parse($row->reversed_at)->toIso8601String(),
            'reversed_by_name' => $userNames[$row->reversed_by] ?? null,
            'reversal_reason'  => $row->reversal_reason,
        ];
    }

    /**
     * Linhas de planilha: paciente só como código + iniciais (minimização LGPD,
     * como no relatório de convênios). `$withReceipt` (apuração) acrescenta o
     * rastreio de recebimento da clínica no fim de cada linha.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<list<mixed>>
     */
    public function exportRows(array $rows, bool $withReceipt = false): array
    {
        $columns = (array) trans('financial_doctor_payouts.export_columns');

        $keys = [
            'date', 'patient_code', 'patient', 'service_type', 'description', 'payer', 'charged',
            'base_source', 'rule', 'payout', 'tranche', 'received_total', 'forecast', 'status', 'closing', 'warnings',
            'beneficiary_role', 'share_percentage', 'net', 'deductions',
        ];

        if ($withReceipt) {
            $keys = [...$keys, 'receipt_status', 'billed', 'glosa', 'received', 'open', 'difference', 'shared_by'];
        }

        $header = array_map(fn (string $key) => $columns[$key] ?? $key, $keys);

        $lines = array_map(fn (array $row) => [
            ...$this->baseCells($row),
            ...($withReceipt ? $this->receiptCells($row['receipt'] ?? null) : []),
        ], $rows);

        return [$header, ...$lines];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    private function baseCells(array $row): array
    {
        return [
            CarbonImmutable::parse($row['date'])->isoFormat('L'),
            $row['patient_code'] ?? '',
            $this->covenantReport->initials($row['patient_name'] ?? null),
            __("financial_doctor_payouts.service_types.{$row['service_type']}"),
            $row['description'],
            $this->payerLabel($row),
            (float) $row['charged'],
            __("financial_doctor_payouts.base_sources.{$row['base_source']}"),
            $this->ruleLabel($row['rule'], $row['basis'] ?? null),
            (float) $row['payout'],
            (int) ($row['tranche'] ?? 1) > 0 ? (int) ($row['tranche'] ?? 1) : '',
            isset($row['received']) ? (float) $row['received'] : '',
            isset($row['forecast']) ? (float) $row['forecast'] : '',
            __("financial_doctor_payouts.statuses.{$row['status']}"),
            $row['payout_code'] ?? '',
            implode(', ', array_map(fn (string $warning) => __("financial_doctor_payouts.warnings.{$warning}"), $row['warnings'])),
            // Divisão (E4/E6): a parte do médico da linha — papel, % do grupo,
            // líquido do atendimento após as deduções e as deduções.
            isset($row['beneficiary_role']) ? __("financial_doctor_payouts.beneficiary_roles.{$row['beneficiary_role']}") : '',
            isset($row['share_percentage']) ? (float) $row['share_percentage'] : '',
            isset($row['net']) ? (float) $row['net'] : '',
            (float) ($row['deductions'] ?? 0),
        ];
    }

    /**
     * Linhas da divisão sob a regra (PDF), com a mesma regra da tela
     * (PayoutItemsTable): papel e % só quando há divisão de fato (executor
     * com 100% do grupo não repete); líquido só quando houve dedução.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public function splitLines(array $row): array
    {
        $locale = app()->getLocale();
        $role   = $row['beneficiary_role'] ?? null;
        $share  = $row['share_percentage'] ?? null;
        $lines  = [];

        if ($role !== null && $share !== null && ($role !== 'executor' || (float) $share < 100)) {
            $lines[] = __('financial_doctor_payouts.split_role_line', [
                'role'  => __("financial_doctor_payouts.beneficiary_roles.{$role}"),
                'value' => Number::format((float) $share, maxPrecision: 2, locale: $locale),
            ]);
        }

        if (($row['net'] ?? null) !== null && (float) ($row['deductions'] ?? 0) > 0) {
            $lines[] = __('financial_doctor_payouts.split_net_line', [
                'net'        => Number::currency((float) $row['net'], 'BRL', $locale),
                'deductions' => Number::currency((float) $row['deductions'], 'BRL', $locale),
            ]);
        }

        return $lines;
    }

    /**
     * Situação + valores do recebimento (do atendimento inteiro; "itens no
     * atendimento" > 1 avisa que a linha divide o atendimento com outras —
     * não somar essas linhas duas vezes). Item sem cobrança própria fica com
     * os valores em branco (não é zero: não há o que rastrear).
     *
     * @param array{status: string, billed: ?float, glosa: ?float, received: ?float, open: ?float, difference: ?float, shared_by: int}|null $receipt
     *
     * @return list<mixed>
     */
    private function receiptCells(?array $receipt): array
    {
        $status = $receipt['status'] ?? DoctorPayoutReceiptStatus::NotLinked->value;
        $value  = fn (string $field): float|string => isset($receipt[$field]) ? (float) $receipt[$field] : '';

        return [
            __("financial_doctor_payouts.receipt_statuses.{$status}"),
            $value('billed'),
            $value('glosa'),
            $value('received'),
            $value('open'),
            $value('difference'),
            $status === DoctorPayoutReceiptStatus::NotLinked->value ? '' : (int) ($receipt['shared_by'] ?? 1),
        ];
    }

    /** @param array<string, mixed> $row */
    public function payerLabel(array $row): string
    {
        return $row['covenant_name'] ?? __('financial_doctor_payouts.particular');
    }

    /**
     * "60% do recebido líquido" (regime por recebimento) ou "60% do valor
     * cobrado" (itens do regime anterior, basis = production); "R$ 80,00 fixo".
     *
     * @param array{calculation: string, percentage: ?float, fixed: ?float}|null $rule
     */
    public function ruleLabel(?array $rule, ?string $basis = null): string
    {
        if ($rule === null) {
            return __('financial_doctor_payouts.rule_none');
        }

        $locale = app()->getLocale();

        if ($rule['calculation'] === 'percentage') {
            $key = $basis === DoctorPayoutBasis::Production->value ? 'rule_percentage_production' : 'rule_percentage';

            return __("financial_doctor_payouts.{$key}", ['value' => Number::format((float) $rule['percentage'], maxPrecision: 2, locale: $locale)]);
        }

        return __('financial_doctor_payouts.rule_fixed', ['value' => Number::currency((float) $rule['fixed'], 'BRL', $locale)]);
    }

    /**
     * Qual regra foi aplicada, por id: "Dra. Ana · Consulta · Unimed",
     * "Todos os médicos · Exame: OCT · Qualquer pagador". Inclui regras
     * excluídas (itens fechados guardam o id). Só regras da clínica.
     *
     * @param list<?string> $ids
     *
     * @return array<string, string>
     */
    public function ruleScopes(string $entityId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (string) $id, $ids))));

        if ($ids === []) {
            return [];
        }

        return DoctorPayoutRule::withTrashed()
            ->where('entity_id', $entityId)
            ->whereIn('id', $ids)
            ->with(['doctor.person:id,full_name', 'visitType:id,name', 'procedure:id,name', 'examType:id,name', 'covenant:id,name'])
            ->get()
            ->mapWithKeys(fn (DoctorPayoutRule $rule) => [(string) $rule->id => $this->ruleScope($rule)])
            ->all();
    }

    private function ruleScope(DoctorPayoutRule $rule): string
    {
        $type = __("financial_doctor_payouts.service_types.{$rule->service_type->value}");
        $item = $rule->visitType?->name ?? $rule->procedure?->name ?? $rule->examType?->name;

        $payer = $rule->payer_scope === DoctorPayoutPayerScope::Covenant && $rule->covenant !== null
            ? (string) $rule->covenant->name
            : __("financial_doctor_payouts.payer_scopes.{$rule->payer_scope->value}");

        return implode(' · ', [
            $rule->doctor?->person?->full_name ?? __('financial_doctor_payouts.all_doctors'),
            $item === null ? $type : __('financial_doctor_payouts.rule_scope_item', ['type' => $type, 'item' => $item]),
            $payer,
        ]);
    }

    /**
     * Deduções acumuladas do atendimento por tipo (cartão, imposto, taxa
     * administrativa), em reais; nulo sem dedução.
     *
     * @param array<string, int>|null $cents
     *
     * @return array{card: float, tax: float, admin: float}|null
     */
    private function deductionsBreakdown(?array $cents): ?array
    {
        if ($cents === null) {
            return null;
        }

        $breakdown = [
            'card'  => (int) ($cents['card'] ?? 0) / 100,
            'tax'   => (int) ($cents['tax'] ?? 0) / 100,
            'admin' => (int) ($cents['admin'] ?? 0) / 100,
        ];

        return array_sum($breakdown) > 0 ? $breakdown : null;
    }

    /**
     * Composição da linha no PDF — o mesmo que a tela mostra (PayoutItemsTable):
     * origem do valor, parcela e recebido acumulado, esperado do atendimento
     * (base), escopo da regra, divisão, deduções e fixo proporcional (regra),
     * já liberado e alertas (repasse).
     *
     * @param array<string, mixed> $row
     *
     * @return array{base: list<string>, rule: list<string>, payout: list<string>}
     */
    public function compositionLines(array $row): array
    {
        $locale = app()->getLocale();
        $money  = fn ($value) => Number::currency((float) $value, 'BRL', $locale);

        $base = [__("financial_doctor_payouts.base_sources.{$row['base_source']}")];

        if ((int) ($row['tranche'] ?? 1) > 1) {
            $base[] = __('financial_doctor_payouts.tranche_label', ['number' => (int) $row['tranche']])
                . ' · ' . __('financial_doctor_payouts.received_total', ['value' => $money($row['received'] ?? 0)]);
        }

        if ($this->hasExpectedGap($row)) {
            $base[] = __('financial_doctor_payouts.expected_line', ['value' => $money($row['expected'])]);
        }

        $rule = array_values(array_filter([$row['rule_scope'] ?? null]));
        $rule = [...$rule, ...$this->splitLines($row)];

        if (($row['deductions_breakdown'] ?? null) !== null) {
            $rule[] = $this->deductionsLine($row['deductions_breakdown']);
        }

        if ($this->isFixedProportional($row)) {
            $rule[] = __('financial_doctor_payouts.rule_fixed_proportional', [
                'received' => $money($row['received']),
                'expected' => $money($row['expected']),
            ]);
        }

        $payout = [];

        if ((float) ($row['released_before'] ?? 0) !== 0.0) {
            $payout[] = __('financial_doctor_payouts.released_before', ['value' => $money($row['released_before'])]);
        }

        foreach ((array) ($row['warnings'] ?? []) as $warning) {
            $payout[] = __("financial_doctor_payouts.warnings.{$warning}");
        }

        return ['base' => $base, 'rule' => $rule, 'payout' => $payout];
    }

    /** @param array{card: float, tax: float, admin: float} $breakdown */
    public function deductionsLine(array $breakdown): string
    {
        $locale = app()->getLocale();
        $parts  = [];

        foreach (['card', 'tax', 'admin'] as $kind) {
            if ((float) $breakdown[$kind] > 0) {
                $parts[] = __("financial_doctor_payouts.deduction_parts.{$kind}") . ' ' . Number::currency((float) $breakdown[$kind], 'BRL', $locale);
            }
        }

        return implode(' · ', $parts);
    }

    /** Regime por recebimento com esperado (cobrado − glosa) diferente do recebido acumulado. */
    private function hasExpectedGap(array $row): bool
    {
        return ($row['basis'] ?? null) === DoctorPayoutBasis::Receipt->value
            && isset($row['expected'], $row['received'])
            && (float) $row['expected'] > 0
            && Money::toCents($row['expected']) !== Money::toCents($row['received']);
    }

    /** Regra de valor fixo paga proporcionalmente: recebido acumulado < esperado. */
    private function isFixedProportional(array $row): bool
    {
        return ($row['rule']['calculation'] ?? null) === DoctorPayoutCalculation::Fixed->value
            && ($row['basis'] ?? null) === DoctorPayoutBasis::Receipt->value
            && isset($row['expected'], $row['received'])
            && Money::toCents($row['received']) > 0
            && Money::toCents($row['received']) < Money::toCents($row['expected']);
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
