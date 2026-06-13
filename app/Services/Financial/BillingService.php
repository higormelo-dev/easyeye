<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\{BillingBatchStatus, BillingClaimStatus, CashEntryNature, FinancialEntryStatus, FinancialEntryType, PaymentMethod, ScheduleSituation};
use App\Models\{BillingBatch, BillingClaim, Covenant, FinancialCashEntry, FinancialCategory, Schedule};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(
        private readonly TissXmlService $tissXmlService,
        private readonly ProcedurePriceService $procedurePrices,
    ) {
    }

    /**
     * Preço unitário informado tem prioridade; na ausência, busca na tabela
     * de preço por procedimento × convênio (ProcedurePrice). 0.0 se não houver.
     */
    private function resolveUnitPrice(array $data, ?string $covenantId, string $entityId): float
    {
        if (isset($data['unit_price']) && $data['unit_price'] !== null && $data['unit_price'] !== '') {
            return (float) $data['unit_price'];
        }

        return (float) ($this->procedurePrices->getPrice($data['procedure_id'] ?? null, $covenantId, $entityId) ?? 0.0);
    }

    public function createIndividual(array $data): BillingClaim
    {
        return DB::transaction(function () use ($data): BillingClaim {
            $entityId = (string) session('selected_entity_id');

            $schedule = Schedule::query()
                ->with(['patient.person', 'doctor', 'covenant'])
                ->where('entity_id', $entityId)
                ->where('id', $data['schedule_id'])
                ->where('situation', ScheduleSituation::Attended->value)
                ->whereNull('deleted_at')
                ->firstOrFail();

            $this->ensureScheduleNotBilled($schedule->id);

            $quantity  = (int) ($data['quantity'] ?? 1);
            $unitPrice = $this->resolveUnitPrice($data, $schedule->covenant_id, $entityId);

            return BillingClaim::query()->create([
                'entity_id'             => $entityId,
                'schedule_id'           => $schedule->id,
                'patient_id'            => $schedule->patient_id,
                'doctor_id'             => $schedule->doctor_id,
                'covenant_id'           => $schedule->covenant_id,
                'status'                => $data['status'] ?? BillingClaimStatus::Draft->value,
                'attendance_date'       => $schedule->date_time->toDateString(),
                'due_date'              => $data['due_date'] ?? null,
                'amount'                => $quantity * $unitPrice,
                'quantity'              => $quantity,
                'unit_price'            => $unitPrice,
                'tuss_code'             => $data['tuss_code'] ?? null,
                'procedure_description' => $data['procedure_description'] ?? null,
                'authorization_code'    => $data['authorization_code'] ?? null,
                'notes'                 => $data['notes'] ?? null,
            ]);
        });
    }

    public function createBatch(array $data): BillingBatch
    {
        return DB::transaction(function () use ($data): BillingBatch {
            $entityId = (string) session('selected_entity_id');

            $covenant = Covenant::query()
                ->where('id', $data['covenant_id'])
                ->where(function ($q) use ($entityId): void {
                    $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                })
                ->whereNull('deleted_at')
                ->firstOrFail();

            $batch = BillingBatch::query()->create([
                'entity_id'           => $entityId,
                'covenant_id'         => $covenant->id,
                'status'              => BillingBatchStatus::Draft->value,
                'tiss_version'        => $data['tiss_version'] ?? '202603',
                'tiss_layout_version' => $data['tiss_layout_version'] ?? '04.03.00',
                'period_start'        => $data['date_from'],
                'period_end'          => $data['date_until'],
                'due_date'            => $data['due_date'] ?? null,
                'issued_at'           => now(),
                'notes'               => $data['notes'] ?? null,
            ]);

            $schedules = $this->eligibleSchedulesForBatch(
                entityId: $entityId,
                covenantId: $covenant->id,
                dateFrom: $data['date_from'],
                dateUntil: $data['date_until'],
                selectedScheduleIds: $data['schedule_ids'] ?? [],
            );

            if ($schedules->isEmpty()) {
                throw ValidationException::withMessages([
                    'schedule_ids' => 'Nenhum agendamento elegível para faturar no período informado.',
                ]);
            }

            $quantity  = (int) ($data['quantity'] ?? 1);
            $unitPrice = $this->resolveUnitPrice($data, $covenant->id, $entityId);

            $claimsPayload = $schedules->map(fn (Schedule $schedule) => [
                'entity_id'             => $entityId,
                'batch_id'              => $batch->id,
                'schedule_id'           => $schedule->id,
                'patient_id'            => $schedule->patient_id,
                'doctor_id'             => $schedule->doctor_id,
                'covenant_id'           => $schedule->covenant_id,
                'status'                => $data['status'] ?? BillingClaimStatus::Draft->value,
                'attendance_date'       => $schedule->date_time->toDateString(),
                'due_date'              => $data['due_date'] ?? null,
                'amount'                => $quantity * $unitPrice,
                'quantity'              => $quantity,
                'unit_price'            => $unitPrice,
                'tuss_code'             => $data['tuss_code'] ?? null,
                'procedure_description' => $data['procedure_description'] ?? null,
                'authorization_code'    => $data['authorization_code'] ?? null,
                'notes'                 => $data['notes'] ?? null,
            ])->all();

            foreach ($claimsPayload as $claimData) {
                BillingClaim::query()->create($claimData);
            }

            $batch->update([
                'total_claims' => count($claimsPayload),
                'total_amount' => count($claimsPayload) * ($quantity * $unitPrice),
            ]);

            return $batch->fresh();
        });
    }

    public function submitBatch(BillingBatch $batch): BillingBatch
    {
        return DB::transaction(function () use ($batch): BillingBatch {
            $claimsCount = $batch->claims()->count();

            if ($claimsCount === 0) {
                throw ValidationException::withMessages([
                    'batch' => 'Este lote não possui guias para envio.',
                ]);
            }

            $batch->update([
                'status'       => BillingBatchStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            $batch->claims()
                ->where('status', BillingClaimStatus::Draft->value)
                ->update(['status' => BillingClaimStatus::Submitted->value]);

            return $batch->fresh();
        });
    }

    public function markClaimPaid(BillingClaim $claim, array $data = []): BillingClaim
    {
        return DB::transaction(function () use ($claim, $data): BillingClaim {
            $paidAmount = (float) ($data['paid_amount'] ?? $claim->amount);

            $claim->update([
                'status'      => BillingClaimStatus::Paid->value,
                'paid_at'     => $data['paid_at'] ?? now(),
                'paid_amount' => $paidAmount,
            ]);

            $alreadyLaunched = FinancialCashEntry::query()
                ->where('billing_claim_id', $claim->id)
                ->where('type', FinancialEntryType::Income->value)
                ->whereNull('deleted_at')
                ->exists();

            if (! $alreadyLaunched) {
                $incomeCategory = $this->defaultIncomeCategory((string) $claim->entity_id);

                FinancialCashEntry::query()->create([
                    'entity_id'        => $claim->entity_id,
                    'category_id'      => $incomeCategory?->id,
                    'covenant_id'      => $claim->covenant_id,
                    'billing_claim_id' => $claim->id,
                    'entry_date'       => now()->toDateString(),
                    'description'      => sprintf('Recebimento de guia %s', $claim->code),
                    'type'             => FinancialEntryType::Income->value,
                    'status'           => FinancialEntryStatus::Paid->value,
                    'amount'           => $paidAmount,
                    'payment_method'   => $data['payment_method'] ?? PaymentMethod::Transfer->value,
                    'nature'           => CashEntryNature::Covenant->value,
                    'reference_type'   => 'billing_claim',
                    'reference_id'     => $claim->id,
                    'notes'            => $data['notes'] ?? null,
                    'active'           => true,
                ]);
            }

            return $claim->fresh();
        });
    }

    public function markClaimDenied(BillingClaim $claim, array $data = []): BillingClaim
    {
        return DB::transaction(function () use ($claim, $data): BillingClaim {
            $claim->update([
                'status'       => BillingClaimStatus::Denied->value,
                'glosa_amount' => (float) ($data['glosa_amount'] ?? $claim->amount),
                'notes'        => $data['notes'] ?? $claim->notes,
            ]);

            return $claim->fresh();
        });
    }

    public function generateBatchXml(BillingBatch $batch): BillingBatch
    {
        return DB::transaction(function () use ($batch): BillingBatch {
            $xmlPath = $this->tissXmlService->generate($batch);

            $batch->update([
                'xml_path'  => $xmlPath,
                'issued_at' => $batch->issued_at ?? now(),
            ]);

            $batch->claims()->update(['is_tiss_exported' => true]);

            return $batch->fresh();
        });
    }

    /**
     * @param string[] $selectedScheduleIds
     */
    private function eligibleSchedulesForBatch(
        string $entityId,
        string $covenantId,
        string $dateFrom,
        string $dateUntil,
        array $selectedScheduleIds = [],
    ): Collection {
        $query = Schedule::query()
            ->where('entity_id', $entityId)
            ->where('covenant_id', $covenantId)
            ->where('situation', ScheduleSituation::Attended->value)
            ->whereBetween(DB::raw('DATE(date_time)'), [$dateFrom, $dateUntil])
            ->whereNull('deleted_at');

        if (! empty($selectedScheduleIds)) {
            $query->whereIn('id', $selectedScheduleIds);
        }

        $schedules = $query->get();

        $alreadyBilledScheduleIds = BillingClaim::query()
            ->where('entity_id', $entityId)
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->whereNotIn('status', [BillingClaimStatus::Cancelled->value, BillingClaimStatus::Denied->value])
            ->whereNull('deleted_at')
            ->pluck('schedule_id')
            ->all();

        return $schedules->reject(
            fn (Schedule $schedule): bool => in_array($schedule->id, $alreadyBilledScheduleIds, true),
        )->values();
    }

    private function ensureScheduleNotBilled(string $scheduleId): void
    {
        $alreadyBilled = BillingClaim::query()
            ->where('schedule_id', $scheduleId)
            ->whereNotIn('status', [BillingClaimStatus::Cancelled->value, BillingClaimStatus::Denied->value])
            ->whereNull('deleted_at')
            ->exists();

        if ($alreadyBilled) {
            throw ValidationException::withMessages([
                'schedule_id' => 'Este agendamento já possui guia de faturamento ativa.',
            ]);
        }
    }

    private function defaultIncomeCategory(string $entityId): ?FinancialCategory
    {
        return FinancialCategory::query()
            ->availableForEntity($entityId)
            ->where('type', FinancialEntryType::Income->value)
            ->orderByRaw("CASE WHEN name = 'RECEITA CONVÊNIO' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->first();
    }
}
