<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\Enums\DoctorPayout\DoctorPayoutSourceType;
use App\Enums\{ExamSource, FinancialEntryStatus, FinancialEntryType, MedicalRecordProcedureStatus, ScheduleSituation};
use App\Models\{DoctorPayoutReceiptAllocation, FinancialCashEntry};
use App\Support\Database\UniqueViolation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Collection, Number, Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recebimento MANUAL do repasse (E3): aloca parte de uma receita já lançada
 * no Fluxo de Caixa a atos de repasse — exame de equipamento e procedimento
 * fora do agendamento (sem cobrança própria), complemento de atendimento
 * (depósito avulso do convênio, guia paga a menor) e receita agregada
 * dividida item a item.
 *
 * Garantias:
 *  - só receita PAGA, de RECEITA, não excluída, da clínica e SEM VÍNCULO
 *    (sem agendamento/guia/repasse): o repasse nunca cria receita nem conta
 *    duas vezes a mesma entrada;
 *  - a soma das alocações válidas de uma receita nunca passa do valor dela —
 *    conferida com a linha da receita travada (FOR UPDATE): alocações
 *    concorrentes e a edição/exclusão da receita no caixa se serializam;
 *  - ato alvo precisa existir na clínica; procedimento pareado com o
 *    agendamento vira o atendimento (o recebido é do atendimento);
 *  - uma alocação válida por receita × ato (índice único); corrigir =
 *    estornar (com motivo, fica no histórico) e alocar de novo.
 *
 * O recebimento alocado entra no cálculo na data da receita (entry_date):
 * libera parcela/complemento; estornar depois de liberado gera parcela
 * negativa no próximo fechamento (DoctorPayoutReleaseService).
 */
final class DoctorPayoutReceiptAllocationService
{
    private const UNIQUE_INDEX = 'doctor_payout_allocations_active_unique';

    /** procedures.treatment de exame (agendamento de exame). */
    private const TREATMENT_EXAM = 3;

    /** Janela de receitas oferecidas para alocação (até o fim do período). */
    public const ELIGIBLE_LOOKBACK_DAYS = 365;

    private const ELIGIBLE_LIMIT = 200;

    /**
     * @param list<array{key: string, amount_cents: int}> $items
     *
     * @return Collection<int, DoctorPayoutReceiptAllocation>
     *
     * @throws ValidationException
     */
    public function allocate(string $entityId, string $cashEntryId, array $items, ?string $notes): Collection
    {
        try {
            return DB::transaction(function () use ($entityId, $cashEntryId, $items, $notes): Collection {
                $entry = $this->lockEligibleEntry($entityId, $cashEntryId);

                // Alvos primeiro (item inválido ou repetido tem mensagem própria);
                // depois a soma contra o saldo da receita.
                $targets = [];

                foreach ($items as $index => $item) {
                    $target = $this->target($entityId, $item['key'])
                        ?? throw ValidationException::withMessages([
                            "items.{$index}.key" => __('financial_doctor_payouts.errors.allocation_target_invalid'),
                        ]);

                    $targetKey = $target[0]->value . ':' . $target[1];

                    if (isset($targets[$targetKey])) {
                        throw ValidationException::withMessages([
                            "items.{$index}.key" => __('financial_doctor_payouts.errors.allocation_duplicate'),
                        ]);
                    }

                    $targets[$targetKey] = [$target, $item['amount_cents']];
                }

                $requested = array_sum(array_map(fn (array $pair) => $pair[1], $targets));
                $available = Money::toCents($entry->amount) - $this->allocatedCents((string) $entry->id);

                if ($requested > $available) {
                    throw ValidationException::withMessages([
                        'items' => __('financial_doctor_payouts.errors.allocation_exceeds', [
                            'available' => Number::currency(max(0, $available) / 100, 'BRL', app()->getLocale()),
                        ]),
                    ]);
                }

                return collect($targets)->map(fn (array $pair) => DoctorPayoutReceiptAllocation::query()->create([
                    'entity_id'     => $entityId,
                    'cash_entry_id' => $entry->id,
                    'source_type'   => $pair[0][0]->value,
                    'source_id'     => $pair[0][1],
                    'amount'        => Money::fromCents($pair[1]),
                    'notes'         => $notes,
                ]))->values();
            });
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::violates($e, self::UNIQUE_INDEX)) {
                throw ValidationException::withMessages(['items' => __('financial_doctor_payouts.errors.allocation_duplicate')]);
            }

            throw $e;
        }
    }

    /**
     * Estorno (admin ou financeiro, com motivo): a alocação sai do recebido;
     * se já foi liberada, o próximo fechamento desconta.
     */
    public function reverse(DoctorPayoutReceiptAllocation $allocation, string $reason, ?string $userId): DoctorPayoutReceiptAllocation
    {
        return DB::transaction(function () use ($allocation, $reason, $userId): DoctorPayoutReceiptAllocation {
            // Mesma ordem do alocar (receita → alocação): sem deadlock.
            FinancialCashEntry::query()
                ->where('entity_id', $allocation->entity_id)
                ->whereKey($allocation->cash_entry_id)
                ->lockForUpdate()
                ->first();

            $locked = DoctorPayoutReceiptAllocation::query()
                ->where('entity_id', $allocation->entity_id)
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isReversed()) {
                throw ValidationException::withMessages(['reason' => __('financial_doctor_payouts.errors.allocation_already_reversed')]);
            }

            $locked->update([
                'reversed_at'     => now(),
                'reversed_by'     => $userId,
                'reversal_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Receitas que podem receber alocação: pagas, sem vínculo, da clínica,
     * com saldo, na janela até o fim do período (mais recentes primeiro).
     *
     * @return list<array{id: string, date: string, description: string, amount: float, allocated: float, remaining: float}>
     */
    public function eligibleEntries(string $entityId, CarbonImmutable $to): array
    {
        return $this->eligibleQuery($entityId)
            ->whereDate('ce.entry_date', '<=', $to->toDateString())
            ->whereDate('ce.entry_date', '>=', $to->subDays(self::ELIGIBLE_LOOKBACK_DAYS)->toDateString())
            ->leftJoinSub(
                DB::table('doctor_payout_receipt_allocations')
                    ->where('entity_id', $entityId)
                    ->whereNull('reversed_at')
                    ->groupBy('cash_entry_id')
                    ->select('cash_entry_id')
                    ->selectRaw('SUM(amount) AS allocated'),
                'al',
                'al.cash_entry_id',
                '=',
                'ce.id',
            )
            ->whereRaw('ce.amount > COALESCE(al.allocated, 0)')
            ->orderByDesc('ce.entry_date')
            ->orderBy('ce.id')
            ->limit(self::ELIGIBLE_LIMIT)
            ->get(['ce.id', 'ce.entry_date', 'ce.description', 'ce.amount', DB::raw('COALESCE(al.allocated, 0) AS allocated')])
            ->map(fn (object $row) => [
                'id'          => (string) $row->id,
                'date'        => substr((string) $row->entry_date, 0, 10),
                'description' => (string) $row->description,
                'amount'      => Money::toCents($row->amount) / 100,
                'allocated'   => Money::toCents($row->allocated) / 100,
                'remaining'   => (Money::toCents($row->amount) - Money::toCents($row->allocated)) / 100,
            ])
            ->values()
            ->all();
    }

    /**
     * Alocações válidas dos atos/atendimentos pedidos (para mostrar e estornar
     * na apuração), agrupadas pelo alvo "tipo:id".
     *
     * @param list<string> $targetKeys
     *
     * @return array<string, list<array{id: string, amount: float, date: string, description: string}>>
     */
    public function activeByTarget(string $entityId, array $targetKeys): array
    {
        $byTarget = [];
        $byType   = [];

        foreach (array_unique($targetKeys) as $key) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, '');

            if (DoctorPayoutSourceType::tryFrom($type) !== null && $id !== '') {
                $byType[$type][] = $id;
            }
        }

        foreach ($byType as $type => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('doctor_payout_receipt_allocations as a')
                    ->join('financial_cash_entries as ce', 'ce.id', '=', 'a.cash_entry_id')
                    ->where('a.entity_id', $entityId)
                    ->where('ce.entity_id', $entityId)
                    ->whereNull('a.reversed_at')
                    ->where('a.source_type', $type)
                    ->whereIn('a.source_id', $chunk)
                    ->orderBy('ce.entry_date')
                    ->orderBy('a.id')
                    ->get(['a.id', 'a.source_type', 'a.source_id', 'a.amount', 'ce.entry_date', 'ce.description'])
                    ->each(function (object $row) use (&$byTarget): void {
                        $byTarget[$row->source_type . ':' . $row->source_id][] = [
                            'id'          => (string) $row->id,
                            'amount'      => Money::toCents($row->amount) / 100,
                            'date'        => substr((string) $row->entry_date, 0, 10),
                            'description' => (string) $row->description,
                        ];
                    });
            }
        }

        return $byTarget;
    }

    private function lockEligibleEntry(string $entityId, string $cashEntryId): FinancialCashEntry
    {
        $entry = Str::isUuid($cashEntryId)
            ? FinancialCashEntry::query()
                ->where('entity_id', $entityId)
                ->whereKey($cashEntryId)
                ->lockForUpdate()
                ->first()
            : null;

        $eligible = $entry !== null
            && $entry->type === FinancialEntryType::Income
            && $entry->status === FinancialEntryStatus::Paid
            && $entry->reference_type === null
            && $entry->billing_claim_id === null;

        if (! $eligible) {
            throw ValidationException::withMessages(['cash_entry_id' => __('financial_doctor_payouts.errors.allocation_entry_invalid')]);
        }

        return $entry;
    }

    private function allocatedCents(string $cashEntryId): int
    {
        return Money::toCents(
            DB::table('doctor_payout_receipt_allocations')
                ->where('cash_entry_id', $cashEntryId)
                ->whereNull('reversed_at')
                ->sum('amount'),
        );
    }

    /**
     * Alvo da alocação a partir da chave do ato: atendimento (agendamento, ou
     * procedimento pareado com ele) ou o próprio ato sem cobrança própria.
     * Nulo se o ato não existe na clínica.
     *
     * @return array{0: DoctorPayoutSourceType, 1: string}|null
     */
    private function target(string $entityId, string $key): ?array
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, '');

        return match (DoctorPayoutSourceType::tryFrom($type)) {
            DoctorPayoutSourceType::Schedule               => $this->scheduleTarget($entityId, $id),
            DoctorPayoutSourceType::MedicalRecordProcedure => $this->procedureTarget($entityId, $id),
            DoctorPayoutSourceType::PatientExam            => $this->examTarget($entityId, $id),
            default                                        => null,
        };
    }

    /** @return array{0: DoctorPayoutSourceType, 1: string}|null */
    private function scheduleTarget(string $entityId, string $id): ?array
    {
        $exists = Str::isUuid($id) && DB::table('schedules')
            ->where('entity_id', $entityId)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->where('situation', ScheduleSituation::Attended->value)
            ->exists();

        return $exists ? [DoctorPayoutSourceType::Schedule, $id] : null;
    }

    /** @return array{0: DoctorPayoutSourceType, 1: string}|null */
    private function procedureTarget(string $entityId, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = DB::table('medical_record_procedures as mrp')
            ->join('medical_records as mr', 'mr.id', '=', 'mrp.medical_record_id')
            ->leftJoin('schedules as s', fn ($join) => $join->on('s.id', '=', 'mr.schedule_id')->where('s.entity_id', '=', $entityId))
            ->leftJoin('visit_types as vt', 'vt.id', '=', 's.visit_id')
            ->where('mrp.entity_id', $entityId)
            ->where('mrp.id', $id)
            ->where('mrp.status', MedicalRecordProcedureStatus::Done->value)
            ->whereNull('mrp.deleted_at')
            ->whereNull('mr.deleted_at')
            ->first(['s.id as schedule_id', DB::raw('(vt.procedure_id IS NOT NULL AND vt.procedure_id = mrp.procedure_id) AS paired')]);

        if ($row === null) {
            return null;
        }

        // Procedimento pareado com o agendamento: o recebido é do atendimento.
        return (bool) $row->paired
            ? [DoctorPayoutSourceType::Schedule, (string) $row->schedule_id]
            : [DoctorPayoutSourceType::MedicalRecordProcedure, $id];
    }

    /** @return array{0: DoctorPayoutSourceType, 1: string}|null */
    private function examTarget(string $entityId, string $key): ?array
    {
        [$patientId, $examTypeId, $date] = array_pad(explode('|', $key, 3), 3, '');

        if (! Str::isUuid($patientId) || ! Str::isUuid($examTypeId) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        // Mesmos filtros da produção (DoctorPayoutProductionService::examRows):
        // importação externa não é ato; exame de agendamento de exame atendido
        // conta pelo agendamento — o recebido vai para ele.
        $row = DB::table('patient_exams as pe')
            ->join('patients as pat', 'pat.id', '=', 'pe.patient_id')
            ->leftJoin('schedules as s', fn ($join) => $join->on('s.id', '=', 'pe.schedule_id')->where('s.entity_id', '=', $entityId))
            ->leftJoin('visit_types as vt', 'vt.id', '=', 's.visit_id')
            ->leftJoin('procedures as vp', 'vp.id', '=', 'vt.procedure_id')
            ->where('pat.entity_id', $entityId)
            ->where('pe.patient_id', $patientId)
            ->where('pe.exam_id', $examTypeId)
            ->where('pe.active', true)
            ->where(fn (Builder $q) => $q->whereNull('pe.source')->orWhere('pe.source', '<>', ExamSource::ExternalImport->value))
            ->whereRaw('CAST(COALESCE(pe.exam_performed_at, pe.created_at) AS date) = ?', [$date])
            ->selectRaw('COUNT(*) AS exams')
            ->selectRaw(
                'MIN(CASE WHEN COALESCE(vp.treatment, 0) = ? AND s.situation = ? AND s.deleted_at IS NULL THEN s.id::text END) AS exam_schedule_id',
                [self::TREATMENT_EXAM, ScheduleSituation::Attended->value],
            )
            ->first();

        if ($row === null || (int) $row->exams === 0) {
            return null;
        }

        return $row->exam_schedule_id !== null
            ? [DoctorPayoutSourceType::Schedule, (string) $row->exam_schedule_id]
            : [DoctorPayoutSourceType::PatientExam, $key];
    }

    /** Receitas pagas, sem vínculo, não excluídas, da clínica. */
    private function eligibleQuery(string $entityId): Builder
    {
        return DB::table('financial_cash_entries as ce')
            ->where('ce.entity_id', $entityId)
            ->where('ce.type', FinancialEntryType::Income->value)
            ->where('ce.status', FinancialEntryStatus::Paid->value)
            ->whereNull('ce.deleted_at')
            ->whereNull('ce.reference_type')
            ->whereNull('ce.billing_claim_id');
    }
}
