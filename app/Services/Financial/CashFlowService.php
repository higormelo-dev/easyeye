<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\{CashEntryNature, FinancialEntryStatus, FinancialEntryType};
use App\Exceptions\Financial\{CashPeriodClosedException, DuplicateCashEntryException};
use App\Models\{CashClose, FinancialCashEntry, Schedule};
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class CashFlowService
{
    public function __construct(
        private readonly ProcedurePriceService $procedurePrices,
    ) {
    }

    public function create(array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($data): FinancialCashEntry {
            $data['entity_id'] = (string) session('selected_entity_id');
            $data['active']    = $data['active'] ?? true;

            $this->assertDateNotClosed($data['entity_id'], $data['entry_date'] ?? null);

            return FinancialCashEntry::query()->create($data);
        });
    }

    /**
     * Cria um lançamento de caixa (entrada) vinculado a um agendamento,
     * disparado pela chegada do paciente. Impede duplicidade de lançamento
     * ativo para o mesmo agendamento.
     *
     * @throws DuplicateCashEntryException
     */
    public function createForSchedule(Schedule $schedule, array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($schedule, $data): FinancialCashEntry {
            $exists = $schedule->financialEntries()
                ->where('status', '!=', FinancialEntryStatus::Cancelled->value)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw new DuplicateCashEntryException(__('schedules.cash_entry_duplicate'));
            }

            $covenantId  = $data['covenant_id'] ?? $schedule->covenant_id;
            $procedureId = $data['procedure_id'] ?? null;

            // Co-participação quando o convênio é cobrável (faturado via guia);
            // caso contrário é recebimento de balcão (particular).
            $nature = $this->procedurePrices->isCharging($procedureId, $covenantId, (string) $schedule->entity_id)
                ? CashEntryNature::Copay->value
                : CashEntryNature::Desk->value;

            $payload = array_merge($data, [
                'type'           => FinancialEntryType::Income->value,
                'status'         => $data['status'] ?? FinancialEntryStatus::Paid->value,
                'nature'         => $nature,
                'reference_type' => 'schedule',
                'reference_id'   => $schedule->id,
                'patient_id'     => $data['patient_id'] ?? $schedule->patient_id,
                'doctor_id'      => $data['doctor_id'] ?? $schedule->doctor_id,
                'covenant_id'    => $covenantId,
            ]);

            return $this->create($payload);
        });
    }

    public function update(FinancialCashEntry $entry, array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($entry, $data): FinancialCashEntry {
            $entityId = (string) $entry->entity_id;

            // Bloqueia se a data atual OU a nova data caírem em período fechado.
            $this->assertDateNotClosed($entityId, $entry->entry_date?->toDateString());

            if (array_key_exists('entry_date', $data)) {
                $this->assertDateNotClosed($entityId, $data['entry_date']);
            }

            $entry->update($data);

            return $entry->fresh();
        });
    }

    /**
     * Garante que a data não pertence a um período de caixa fechado.
     *
     * @throws CashPeriodClosedException
     */
    private function assertDateNotClosed(string $entityId, mixed $date): void
    {
        if (empty($date)) {
            return;
        }

        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

        $closed = CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();

        if ($closed) {
            throw new CashPeriodClosedException(__('financial.cash_period_closed'));
        }
    }

    public function summary(string $entityId, string $from, string $to): array
    {
        $base = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled');

        $income  = (float) (clone $base)->where('type', 'income')->sum('amount');
        $expense = (float) (clone $base)->where('type', 'expense')->sum('amount');
        $pending = (float) (clone $base)->where('type', 'income')->where('status', 'pending')->sum('amount');

        return [
            'income'  => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
            'pending' => $pending,
        ];
    }
}
