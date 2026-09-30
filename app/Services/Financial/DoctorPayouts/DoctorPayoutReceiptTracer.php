<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\ReceiptTraceData;
use App\Enums\{BillingClaimStatus, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType, MedicalRecordProcedureStatus, PaymentMethod};
use App\Enums\DoctorPayout\{DoctorPayoutReceiptStatus, DoctorPayoutSourceType};
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;

/**
 * Recebimento dos atos de repasse: quanto do atendimento foi cobrado/
 * faturado, glosado e efetivamente recebido pela clínica. É a fonte do
 * rastreio na apuração e da base das parcelas no regime por recebimento
 * (DoctorPayoutReleaseService).
 *
 * Unidade de cobrança = agendamento:
 *  - balcão/coparticipação: receitas do caixa com reference_type = schedule;
 *  - convênio: guias (billing_claims.schedule_id) e a receita de cada guia.
 *
 * Prova de recebimento = receita PAGA e não excluída no Fluxo de Caixa (a do
 * balcão pelo agendamento; a da guia pelo billing_claim_id, criada por
 * BillingService::payLockedClaim), com data = entry_date. Não são
 * recebimento: guia "paga" sem lançamento (dado legado → unconfirmed),
 * tiss_guides.paid_amount (total − glosas, calculado) e recurso "recuperado"
 * (aceito não é dinheiro).
 *
 * $asOf (fim do período): só entram no recebido as receitas com data até
 * ele; faturado, glosa e a receber são o estado atual.
 *
 * Recebimento manual (DoctorPayoutReceiptAllocationService): parte de receita
 * avulsa do caixa alocada ao atendimento (soma ao recebido dele) ou ao
 * próprio ato sem cobrança própria (exame, procedimento fora do agendamento
 * — que passa a ter recebido), na data da receita.
 *
 * Item → unidade:
 *  - agendamento: o próprio agendamento;
 *  - procedimento do prontuário pareado com o agendamento (mesmo
 *    procedimento do tipo de atendimento): o agendamento. Procedimentos
 *    iguais pareados (ex.: OD e OE) trazem os valores do atendimento
 *    INTEIRO, com sharedBy/position para quem precisa ratear;
 *  - procedimento não pareado e exame de equipamento: sem cobrança própria
 *    (not_linked).
 *
 * Isolamento: toda consulta filtra a clínica explicitamente.
 */
final class DoctorPayoutReceiptTracer
{
    /** Lotes de whereIn (um período longo tem milhares de atendimentos). */
    private const CHUNK = 1000;

    /** Guias que ainda podem receber: o líquido (valor − glosa) está a receber. */
    private const CLAIM_OPEN_STATUSES = [BillingClaimStatus::Draft, BillingClaimStatus::Submitted, BillingClaimStatus::Denied];

    private const EMPTY_UNIT = ['billed' => 0, 'glosa' => 0, 'received' => 0, 'open' => 0, 'to_bill' => 0];

    /**
     * @param iterable<string> $keys         chaves de item "tipo:id" (PayoutItemData::key())
     * @param bool             $withReceipts inclui a lista de recebimentos (retrato da parcela)
     *
     * @return array<string, ReceiptTraceData> por chave de item
     */
    public function trace(string $entityId, iterable $keys, ?CarbonImmutable $asOf = null, bool $withReceipts = false): array
    {
        $traces       = [];
        $links        = []; // chave → [agendamento, itens que dividem o atendimento, posição]
        $procedureIds = []; // chave → id do procedimento do prontuário

        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, '');

            if ($type === DoctorPayoutSourceType::Schedule->value && Str::isUuid($id)) {
                $links[$key] = [$id, 1, 0];
            } elseif ($type === DoctorPayoutSourceType::MedicalRecordProcedure->value && Str::isUuid($id)) {
                $procedureIds[$key] = $id;
            } else {
                $traces[$key] = ReceiptTraceData::notLinked();
            }
        }

        $procedureLinks = $this->procedureLinks($entityId, array_values(array_unique($procedureIds)));

        foreach ($procedureIds as $key => $id) {
            if (isset($procedureLinks[$id])) {
                $links[$key] = $procedureLinks[$id];
            } else {
                $traces[$key] = ReceiptTraceData::notLinked();
            }
        }

        $scheduleIds = array_values(array_unique(array_column($links, 0)));
        $units       = $this->unitTotals($entityId, $scheduleIds, $asOf);
        $receipts    = $withReceipts ? $this->receiptLists($entityId, $scheduleIds, $asOf) : [];

        foreach ($links as $key => [$scheduleId, $sharedBy, $position]) {
            $traces[$key] = $this->unitTrace(
                $scheduleId,
                $units[$scheduleId] ?? self::EMPTY_UNIT,
                $sharedBy,
                $position,
                $receipts[$scheduleId] ?? [],
            );
        }

        // Recebimento manual alocado ao próprio ato: exame/procedimento sem
        // cobrança própria passa a ter recebido; procedimento pareado soma à parte dele.
        $actKeys = array_values(array_filter(array_keys($traces), fn (string $key) => ! str_starts_with($key, DoctorPayoutSourceType::Schedule->value . ':')));

        foreach ($this->manualAllocations($entityId, $actKeys, $asOf) as $key => $manual) {
            $trace = $traces[$key];

            // No ato pareado, o manual próprio não entra no rateio do atendimento (kind manual_act).
            $traces[$key] = $trace->isLinked()
                ? $trace->withActManual($manual['cents'], $withReceipts ? array_map(fn (array $receipt) => [...$receipt, 'kind' => 'manual_act'], $manual['receipts']) : [])
                : new ReceiptTraceData(
                    status: $manual['cents'] > 0 ? DoctorPayoutReceiptStatus::Received : DoctorPayoutReceiptStatus::NotLinked,
                    receivedCents: $manual['cents'],
                    unitId: $key,
                    receipts: $withReceipts ? $manual['receipts'] : [],
                );
        }

        return $traces;
    }

    /**
     * Procedimento do prontuário → agendamento pareado (mesmo procedimento do
     * tipo de atendimento) + quantos procedimentos iguais executados dividem
     * esse atendimento e a posição deste (a mesma ordem da base: executed_at,
     * id). Não pareado, não mais executado ou prontuário excluído: fica sem
     * cobrança própria.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    private function procedureLinks(string $entityId, array $ids): array
    {
        $links = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('medical_record_procedures as mrp')
                ->join('medical_records as mr', 'mr.id', '=', 'mrp.medical_record_id')
                ->join('schedules as s', 's.id', '=', 'mr.schedule_id')
                ->join('visit_types as vt', 'vt.id', '=', 's.visit_id')
                ->where('mrp.entity_id', $entityId)
                ->where('s.entity_id', $entityId)
                ->where('mrp.status', MedicalRecordProcedureStatus::Done->value)
                ->whereNull('mrp.deleted_at')
                ->whereNull('mr.deleted_at')
                ->whereColumn('vt.procedure_id', 'mrp.procedure_id')
                ->whereIn('mrp.id', $chunk)
                ->select(['mrp.id', 's.id as schedule_id'])
                ->selectRaw(DoctorPayoutProductionService::pairedSiblingsSelect())
                ->get()
                ->each(function (object $row) use (&$links): void {
                    $shared = max(1, (int) $row->paired_count);

                    $links[(string) $row->id] = [(string) $row->schedule_id, $shared, min((int) $row->paired_rank, $shared - 1)];
                });
        }

        return $links;
    }

    /**
     * Totais de cobrança de cada agendamento, em centavos.
     *
     * @param list<string> $scheduleIds
     *
     * @return array<string, array{billed: int, glosa: int, received: int, open: int, to_bill: int}>
     */
    private function unitTotals(string $entityId, array $scheduleIds, ?CarbonImmutable $asOf): array
    {
        $units = [];

        foreach (array_chunk($scheduleIds, self::CHUNK) as $chunk) {
            foreach ($this->deskRows($entityId, $chunk, $asOf) as $row) {
                $unit = $units[(string) $row->schedule_id] ?? self::EMPTY_UNIT;

                $unit['billed'] += Money::toCents($row->billed);
                $unit['received'] += Money::toCents($row->received);
                $unit['open'] += Money::toCents($row->open);

                $units[(string) $row->schedule_id] = $unit;
            }

            foreach ($this->manualAllocations($entityId, array_map(fn (string $id) => DoctorPayoutSourceType::Schedule->value . ':' . $id, $chunk), $asOf) as $key => $manual) {
                $scheduleId = substr($key, strlen(DoctorPayoutSourceType::Schedule->value) + 1);
                $unit       = $units[$scheduleId] ?? self::EMPTY_UNIT;

                $unit['received'] += $manual['cents'];

                $units[$scheduleId] = $unit;
            }

            foreach ($this->claimRows($entityId, $chunk, $asOf) as $row) {
                $unit   = $units[(string) $row->schedule_id] ?? self::EMPTY_UNIT;
                $status = BillingClaimStatus::tryFrom((string) $row->status);
                $net    = max(0, Money::toCents($row->amount) - Money::toCents($row->glosa_amount));

                $unit['billed'] += Money::toCents($row->amount);
                $unit['glosa'] += Money::toCents($row->glosa_amount);
                $unit['received'] += Money::toCents($row->received);

                if (in_array($status, self::CLAIM_OPEN_STATUSES, true)) {
                    $unit['open'] += $net;
                    $unit['to_bill'] += $status === BillingClaimStatus::Draft ? $net : 0;
                }

                $units[(string) $row->schedule_id] = $unit;
            }
        }

        return $units;
    }

    /**
     * Receitas de balcão/coparticipação por agendamento (canceladas e
     * excluídas não contam): pendente = a receber, paga = recebida (até $asOf).
     *
     * @param list<string> $scheduleIds
     */
    private function deskRows(string $entityId, array $scheduleIds, ?CarbonImmutable $asOf): Collection
    {
        $paid    = FinancialEntryStatus::Paid->value;
        $pending = FinancialEntryStatus::Pending->value;

        [$receivedFilter, $receivedBindings] = $asOf === null
            ? ['status = ?', [$paid]]
            : ['status = ? AND entry_date <= ?', [$paid, $asOf->toDateString()]];

        return DB::table('financial_cash_entries')
            ->where('entity_id', $entityId)
            ->where('reference_type', CashEntryReferenceType::Schedule->value)
            ->where('type', FinancialEntryType::Income->value)
            ->whereIn('status', [$paid, $pending])
            ->whereNull('deleted_at')
            ->whereIn('reference_id', $scheduleIds)
            ->groupBy('reference_id')
            ->select('reference_id as schedule_id')
            ->selectRaw('SUM(amount) AS billed')
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE {$receivedFilter}), 0) AS received", $receivedBindings)
            ->selectRaw('COALESCE(SUM(amount) FILTER (WHERE status = ?), 0) AS open', [$pending])
            ->get();
    }

    /**
     * Guias por agendamento (canceladas e excluídas fora), cada uma com a
     * soma das receitas pagas vinculadas a ela (até $asOf).
     *
     * @param list<string> $scheduleIds
     */
    private function claimRows(string $entityId, array $scheduleIds, ?CarbonImmutable $asOf): Collection
    {
        return DB::table('billing_claims as bc')
            ->where('bc.entity_id', $entityId)
            ->where('bc.status', '<>', BillingClaimStatus::Cancelled->value)
            ->whereNull('bc.deleted_at')
            ->whereIn('bc.schedule_id', $scheduleIds)
            ->select(['bc.schedule_id', 'bc.status', 'bc.amount', 'bc.glosa_amount'])
            ->selectSub(fn (Builder $query) => $this->claimReceipts($query->from('financial_cash_entries as ce'), $entityId, $asOf)
                ->selectRaw('COALESCE(SUM(ce.amount), 0)')
                ->whereColumn('ce.billing_claim_id', 'bc.id'), 'received')
            ->get();
    }

    /**
     * Recebimentos considerados de cada agendamento (retrato da parcela).
     *
     * @param list<string> $scheduleIds
     *
     * @return array<string, list<array{id: string, date: string, amount_cents: int, kind: string}>>
     */
    private function receiptLists(string $entityId, array $scheduleIds, ?CarbonImmutable $asOf): array
    {
        $lists = [];

        foreach (array_chunk($scheduleIds, self::CHUNK) as $chunk) {
            $desk = DB::table('financial_cash_entries')
                ->where('entity_id', $entityId)
                ->where('reference_type', CashEntryReferenceType::Schedule->value)
                ->where('type', FinancialEntryType::Income->value)
                ->where('status', FinancialEntryStatus::Paid->value)
                ->whereNull('deleted_at')
                ->whereIn('reference_id', $chunk)
                ->when($asOf !== null, fn (Builder $q) => $q->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->get(['id', 'reference_id as schedule_id', 'entry_date', 'amount', 'amount_credit', 'amount_debit', 'payment_method'])
                ->map(fn (object $row) => [$row, 'desk']);

            $claims = $this->claimReceipts(DB::table('financial_cash_entries as ce'), $entityId, $asOf)
                ->join('billing_claims as bc', 'bc.id', '=', 'ce.billing_claim_id')
                ->where('bc.entity_id', $entityId)
                ->whereIn('bc.schedule_id', $chunk)
                ->get(['ce.id', 'bc.schedule_id', 'ce.entry_date', 'ce.amount'])
                ->map(fn (object $row) => [$row, 'claim']);

            foreach ($desk->concat($claims) as [$row, $kind]) {
                $lists[(string) $row->schedule_id][] = [
                    'id'           => (string) $row->id,
                    'date'         => substr((string) $row->entry_date, 0, 10),
                    'amount_cents' => Money::toCents($row->amount),
                    'kind'         => $kind,
                    ...($kind === 'desk' ? $this->cardParts($row) : []),
                ];
            }
        }

        $scheduleKeys = array_map(fn (string $id) => DoctorPayoutSourceType::Schedule->value . ':' . $id, $scheduleIds);

        foreach ($this->manualAllocations($entityId, $scheduleKeys, $asOf) as $key => $manual) {
            $scheduleId = substr($key, strlen(DoctorPayoutSourceType::Schedule->value) + 1);

            foreach ($manual['receipts'] as $receipt) {
                $lists[$scheduleId][] = $receipt;
            }
        }

        foreach ($lists as $scheduleId => $list) {
            usort($list, fn (array $a, array $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $lists[$scheduleId] = $list;
        }

        return $lists;
    }

    /**
     * Recebimentos manuais válidos por alvo "tipo:id": alocação não estornada
     * de receita paga, não excluída, da clínica (até $asOf, pela data da receita).
     *
     * @param list<string> $targetKeys
     *
     * @return array<string, array{cents: int, receipts: list<array{id: string, date: string, amount_cents: int, kind: string}>}>
     */
    private function manualAllocations(string $entityId, array $targetKeys, ?CarbonImmutable $asOf): array
    {
        $byType = [];

        foreach ($targetKeys as $key) {
            [$type, $id]     = array_pad(explode(':', $key, 2), 2, '');
            $byType[$type][] = $id;
        }

        $manual = [];

        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
                DB::table('doctor_payout_receipt_allocations as a')
                    ->join('financial_cash_entries as ce', 'ce.id', '=', 'a.cash_entry_id')
                    ->where('a.entity_id', $entityId)
                    ->where('ce.entity_id', $entityId)
                    ->whereNull('a.reversed_at')
                    ->where('ce.type', FinancialEntryType::Income->value)
                    ->where('ce.status', FinancialEntryStatus::Paid->value)
                    ->whereNull('ce.deleted_at')
                    ->where('a.source_type', $type)
                    ->whereIn('a.source_id', $chunk)
                    ->when($asOf !== null, fn (Builder $q) => $q->whereDate('ce.entry_date', '<=', $asOf->toDateString()))
                    ->orderBy('ce.entry_date')
                    ->orderBy('a.id')
                    ->get(['a.id', 'a.source_id', 'a.amount', 'ce.entry_date'])
                    ->each(function (object $row) use ($type, &$manual): void {
                        $key   = $type . ':' . $row->source_id;
                        $cents = Money::toCents($row->amount);

                        $manual[$key]['cents']      = ($manual[$key]['cents'] ?? 0) + $cents;
                        $manual[$key]['receipts'][] = [
                            'id'           => (string) $row->id,
                            'date'         => substr((string) $row->entry_date, 0, 10),
                            'amount_cents' => $cents,
                            'kind'         => 'manual',
                        ];
                    });
            }
        }

        return $manual;
    }

    /**
     * Parte paga com cartão de um recebimento do balcão (taxa de cartão, E4):
     * divisão dinheiro + cartão pelos valores da agenda; "crédito" sem divisão
     * = tudo no crédito. Sem cartão: zeros.
     *
     * @return array{credit_cents: int, debit_cents: int}
     */
    private function cardParts(object $row): array
    {
        if ($row->amount_credit !== null || $row->amount_debit !== null) {
            return ['credit_cents' => Money::toCents($row->amount_credit), 'debit_cents' => Money::toCents($row->amount_debit)];
        }

        return [
            'credit_cents' => $row->payment_method === PaymentMethod::Credit->value ? Money::toCents($row->amount) : 0,
            'debit_cents'  => 0,
        ];
    }

    /** Receitas pagas, não excluídas, da clínica, vinculadas a guias (até $asOf). */
    private function claimReceipts(Builder $query, string $entityId, ?CarbonImmutable $asOf): Builder
    {
        return $query
            ->where('ce.entity_id', $entityId)
            ->where('ce.type', FinancialEntryType::Income->value)
            ->where('ce.status', FinancialEntryStatus::Paid->value)
            ->whereNull('ce.deleted_at')
            ->whereNotNull('ce.billing_claim_id')
            ->when($asOf !== null, fn (Builder $q) => $q->whereDate('ce.entry_date', '<=', $asOf->toDateString()));
    }

    /**
     * Valores do atendimento inteiro; a situação sai dos totais.
     *
     * @param array{billed: int, glosa: int, received: int, open: int, to_bill: int} $unit
     * @param list<array{id: string, date: string, amount_cents: int, kind: string}> $receipts
     */
    private function unitTrace(string $scheduleId, array $unit, int $sharedBy, int $position, array $receipts): ReceiptTraceData
    {
        return new ReceiptTraceData(
            status: DoctorPayoutReceiptStatus::fromTotals($unit['billed'], $unit['glosa'], $unit['received'], $unit['open'], $unit['to_bill']),
            billedCents: $unit['billed'],
            glosaCents: $unit['glosa'],
            receivedCents: $unit['received'],
            openCents: $unit['open'],
            differenceCents: $unit['billed'] - $unit['glosa'] - $unit['received'] - $unit['open'],
            unitId: $scheduleId,
            sharedBy: $sharedBy,
            position: $position,
            receipts: $receipts,
        );
    }
}
