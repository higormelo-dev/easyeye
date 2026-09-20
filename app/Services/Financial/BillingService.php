<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissGlosa, TissGlosaReason, TissGuide};
use App\Domains\Tiss\Services\TissWorkflowService;
use App\Enums\{BillingBatchStatus, BillingClaimStatus, CashEntryNature, FinancialEntryStatus, FinancialEntryType, PaymentMethod, ScheduleSituation};
use App\Models\{BillingBatch, BillingClaim, Covenant, FinancialCashEntry, FinancialCategory, Schedule};
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class BillingService
{
    public function __construct(
        private readonly TissXmlService $tissXmlService,
        private readonly ProcedurePriceService $procedurePrices,
        private readonly ResolveTissOperatorForCovenantAction $resolveOperator,
        private readonly TissWorkflowService $tissWorkflow,
    ) {
    }

    /**
     * Preço unitário informado tem prioridade; na ausência, busca na tabela
     * de preço por procedimento × convênio (ProcedurePrice). 0.0 se não houver.
     */
    private function resolveUnitPrice(array $data, ?string $covenantId, string $entityId): float
    {
        if (isset($data['unit_price']) && $data['unit_price'] !== null && $data['unit_price'] !== '') {
            return round((float) $data['unit_price'], 2);
        }

        return round((float) ($this->procedurePrices->getPrice($data['procedure_id'] ?? null, $covenantId, $entityId) ?? 0.0), 2);
    }

    public function createIndividual(array $data): BillingClaim
    {
        return DB::transaction(function () use ($data): BillingClaim {
            $entityId = (string) session('selected_entity_id');

            // lockForUpdate: serializa 2 requests faturando o mesmo agendamento em
            // paralelo (duplo clique/2 abas). O índice único parcial em
            // billing_claims(schedule_id) é o cinto-e-suspensório caso esse lock
            // seja contornado por algum caminho futuro (job, comando artisan).
            $schedule = Schedule::query()
                ->with(['patient.person', 'doctor', 'covenant'])
                ->where('entity_id', $entityId)
                ->where('id', $data['schedule_id'])
                ->where('situation', ScheduleSituation::Attended->value)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureScheduleNotBilled($schedule->id);

            $quantity  = (int) ($data['quantity'] ?? 1);
            $unitPrice = $this->resolveUnitPrice($data, $schedule->covenant_id, $entityId);

            $claimData = [
                'entity_id'             => $entityId,
                'schedule_id'           => $schedule->id,
                'patient_id'            => $schedule->patient_id,
                'doctor_id'             => $schedule->doctor_id,
                'covenant_id'           => $schedule->covenant_id,
                'status'                => $data['status'] ?? BillingClaimStatus::Draft->value,
                'attendance_date'       => $schedule->date_time->toDateString(),
                'due_date'              => $data['due_date'] ?? null,
                'amount'                => round($quantity * $unitPrice, 2),
                'quantity'              => $quantity,
                'unit_price'            => $unitPrice,
                'tuss_code'             => $data['tuss_code'] ?? null,
                'procedure_description' => $data['procedure_description'] ?? null,
                'authorization_code'    => $data['authorization_code'] ?? null,
                'clinical_indication'   => $data['clinical_indication'] ?? null,
                'notes'                 => $data['notes'] ?? null,
            ];

            $claim = BillingClaim::query()->create($claimData);

            // Convênios sem registro ANS (ex.: "Particular") são cobrança direta ao
            // beneficiário e não seguem o protocolo TISS — nesse caso o claim fica
            // sem guia TISS vinculada, exatamente como antes da consolidação.
            if ($schedule->covenant && $this->resolveOperator->isEligible($schedule->covenant)) {
                $contract = $this->resolveOperator->__invoke($schedule->covenant, $entityId);
                $guide    = $this->createTissGuideForSchedule($schedule, $claimData, $entityId, $contract, $data['eye_side'] ?? null);

                $claim->update(['tiss_guide_id' => $guide->id]);
            }

            return $claim->fresh();
        });
    }

    /**
     * Cria a guia TISS (Domains\Tiss) correspondente a um agendamento faturado.
     * A guia nasce em rascunho e só é anexada a um lote (com pré-validação) em createBatch().
     *
     * @param array<string, mixed> $claimData
     */
    private function createTissGuideForSchedule(
        Schedule $schedule,
        array $claimData,
        string $entityId,
        TissEntityOperatorContract $contract,
        ?string $eyeSide = null,
    ): TissGuide {
        return $this->tissWorkflow->createGuide([
            'entity_id'               => $entityId,
            'operator_id'             => $contract->operator_id,
            'contract_id'             => $contract->id,
            'patient_id'              => $schedule->patient_id,
            'doctor_id'               => $schedule->doctor_id,
            'schedule_id'             => $schedule->id,
            'guide_type'              => 'consultation',
            'attendance_date'         => $schedule->date_time->toDateString(),
            'beneficiary_card_number' => $schedule->patient?->card_number ?? null,
            'beneficiary_name'        => $schedule->patient?->person?->name ?? null,
            'clinical_indication'     => $claimData['clinical_indication'] ?? null,
            'items'                   => [[
                'tuss_code'            => $claimData['tuss_code'] ?: '10101012',
                'description'          => $claimData['procedure_description'] ?: 'CONSULTA OFTALMOLOGICA',
                'quantity'             => $claimData['quantity'],
                'unit_amount'          => $claimData['unit_price'],
                'authorization_number' => $claimData['authorization_code'] ?? null,
                'metadata'             => filled($eyeSide) ? ['eye_side' => $eyeSide] : null,
            ]],
        ]);
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

            // Convênios sem registro ANS (ex.: "Particular") são cobrança direta ao
            // beneficiário e não seguem o protocolo TISS — o lote inteiro fica fora
            // do domínio Domains\Tiss, exatamente como antes da consolidação.
            $tissEligible = $this->resolveOperator->isEligible($covenant);
            $contract     = null;
            $tissBatch    = null;

            if ($tissEligible) {
                $contract  = $this->resolveOperator->__invoke($covenant, $entityId);
                $tissBatch = $this->tissWorkflow->createBatch([
                    'entity_id'       => $entityId,
                    'operator_id'     => $contract->operator_id,
                    'contract_id'     => $contract->id,
                    'reference_month' => now()->format('Y-m'),
                ]);

                $batch->update(['tiss_batch_id' => $tissBatch->id]);
            }

            $attachedCount = 0;
            $pendingCount  = 0;

            foreach ($schedules as $schedule) {
                $claimData = [
                    'entity_id'             => $entityId,
                    'batch_id'              => $batch->id,
                    'schedule_id'           => $schedule->id,
                    'patient_id'            => $schedule->patient_id,
                    'doctor_id'             => $schedule->doctor_id,
                    'covenant_id'           => $schedule->covenant_id,
                    'status'                => $data['status'] ?? BillingClaimStatus::Draft->value,
                    'attendance_date'       => $schedule->date_time->toDateString(),
                    'due_date'              => $data['due_date'] ?? null,
                    'amount'                => round($quantity * $unitPrice, 2),
                    'quantity'              => $quantity,
                    'unit_price'            => $unitPrice,
                    'tuss_code'             => $data['tuss_code'] ?? null,
                    'procedure_description' => $data['procedure_description'] ?? null,
                    'authorization_code'    => $data['authorization_code'] ?? null,
                    'clinical_indication'   => $data['clinical_indication'] ?? null,
                    'notes'                 => $data['notes'] ?? null,
                ];

                try {
                    $claim = BillingClaim::query()->create($claimData);
                } catch (QueryException $exception) {
                    // Índice único parcial em billing_claims(schedule_id) barrou uma
                    // guia ativa duplicada — outro request (lote ou individual) já
                    // faturou este agendamento entre o filtro de elegíveis e este
                    // insert. Não derruba o lote inteiro, só pula este agendamento.
                    if ($this->isUniqueViolation($exception)) {
                        $pendingCount++;

                        continue;
                    }

                    throw $exception;
                }

                if ($tissEligible) {
                    $guide = $this->createTissGuideForSchedule($schedule, $claimData, $entityId, $contract);
                    $claim->update(['tiss_guide_id' => $guide->id]);

                    try {
                        $this->tissWorkflow->attachGuideToBatch($tissBatch, $guide);
                        $attachedCount++;
                    } catch (InvalidArgumentException) {
                        // Guia fica com pendência registrada em tiss_guides.errors (ver PreValidateTissGuideService)
                        // e permanece fora do lote até ser corrigida — não derruba a criação do lote inteiro.
                        $pendingCount++;
                    }
                } else {
                    $attachedCount++;
                }
            }

            $totalAmount = $tissEligible
                ? (float) $tissBatch->refresh()->total_amount
                : round($attachedCount * $quantity * $unitPrice, 2);

            $batch->update([
                'total_claims' => $attachedCount + $pendingCount,
                'total_amount' => $totalAmount,
                'notes'        => $pendingCount > 0
                    ? trim(($data['notes'] ?? '') . sprintf(' [%d guia(s) com pendência não incluída(s) no lote — revise em /panel/financial/billing]', $pendingCount))
                    : ($data['notes'] ?? null),
            ]);

            return $batch->fresh();
        });
    }

    public function submitBatch(BillingBatch $batch): BillingBatch
    {
        // Guarda de idempotência: 2 cliques rápidos no botão "Enviar" (ou um
        // retry após timeout de rede) não podem reenviar o mesmo lote pra
        // operadora TISS duas vezes.
        if ($batch->status === BillingBatchStatus::Submitted) {
            return $batch;
        }

        $claimsCount = $batch->claims()->count();

        if ($claimsCount === 0) {
            throw ValidationException::withMessages([
                'batch' => 'Este lote não possui guias para envio.',
            ]);
        }

        if ($batch->tiss_batch_id) {
            $this->submitViaTissWorkflow($batch);
        }

        return DB::transaction(function () use ($batch): BillingBatch {
            // Relock + recheck dentro da transação: fecha a janela entre o guard
            // acima (fora da transação, necessário porque o envio real ao TISS é
            // I/O externo e não pode segurar lock de linha) e este update.
            $locked = BillingBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BillingBatchStatus::Submitted) {
                return $locked;
            }

            $locked->update([
                'status'       => BillingBatchStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            $locked->claims()
                ->where('status', BillingClaimStatus::Draft->value)
                ->update(['status' => BillingClaimStatus::Submitted->value]);

            return $locked->fresh();
        });
    }

    private function submitViaTissWorkflow(BillingBatch $batch): void
    {
        $tissBatch = $batch->tissBatch;

        if (! $tissBatch) {
            return;
        }

        if ((int) $tissBatch->guides_count === 0) {
            throw ValidationException::withMessages([
                'batch' => 'Este lote não possui guias sem pendência crítica — corrija as guias pendentes antes de enviar.',
            ]);
        }

        try {
            if ($tissBatch->status->value === 'open') {
                $this->tissWorkflow->closeBatch($tissBatch);
            }

            $this->tissWorkflow->generateXmlNow($tissBatch);
            $this->tissWorkflow->sendBatchNow($tissBatch->fresh());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'batch' => 'Falha no envio TISS: ' . $exception->getMessage(),
            ]);
        }

        $tissBatch->refresh()->loadMissing('xmlDocument');

        $batch->update([
            'xml_path' => $tissBatch->xmlDocument?->file_path,
        ]);
    }

    public function markClaimPaid(BillingClaim $claim, array $data = []): BillingClaim
    {
        return DB::transaction(function () use ($claim, $data): BillingClaim {
            // Relock: duplo submit do botão "marcar como paga" não pode gerar 2
            // FinancialCashEntry pra mesma guia (o alreadyLaunched abaixo só é
            // seguro com a linha da guia travada — índice único parcial em
            // financial_cash_entries é o cinto-e-suspensório).
            $claim = BillingClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $paidAmount = round((float) ($data['paid_amount'] ?? $claim->amount), 2);

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
            $glosaAmount = round((float) ($data['glosa_amount'] ?? $claim->amount), 2);

            $claim->update([
                'status'       => BillingClaimStatus::Denied->value,
                'glosa_amount' => $glosaAmount,
                'notes'        => $data['notes'] ?? $claim->notes,
            ]);

            if ($claim->tiss_guide_id) {
                $guide     = $claim->tissGuide;
                $glosaCode = (string) ($data['glosa_code'] ?? 'GLS-MANUAL');

                // Tabela 38 (ANS) dá o termo oficial quando o código bate; senão
                // cai pro texto livre da clínica — mantém o fluxo funcionando
                // pra códigos fora da tabela (ex.: convênios com tabela própria).
                $officialReason = TissGlosaReason::query()
                    ->where('code', $glosaCode)
                    ->where('active', true)
                    ->value('description');

                TissGlosa::query()->updateOrCreate(
                    [
                        'entity_id'  => $claim->entity_id,
                        'guide_id'   => $claim->tiss_guide_id,
                        'glosa_code' => $glosaCode,
                    ],
                    [
                        'operator_id'       => $guide?->operator_id,
                        'status'            => TissGlosaStatus::Open->value,
                        'glosa_description' => $officialReason ?? $data['notes'] ?? 'Glosa lançada manualmente pela clínica.',
                        'amount'            => $glosaAmount,
                        'identified_at'     => now()->toDateString(),
                        'deadline'          => now()->addDays((int) config('tiss.glosa_appeal_deadline_days', 30))->toDateString(),
                        'metadata'          => ['source' => 'billing_claim_manual', 'billing_claim_id' => (string) $claim->id],
                    ],
                );
            }

            return $claim->fresh();
        });
    }

    public function generateBatchXml(BillingBatch $batch): BillingBatch
    {
        return DB::transaction(function () use ($batch): BillingBatch {
            if ($batch->tiss_batch_id && $batch->tissBatch) {
                $this->tissWorkflow->generateXmlNow($batch->tissBatch);
                $xmlPath = $batch->tissBatch->fresh()->loadMissing('xmlDocument')->xmlDocument?->file_path;
            } else {
                // Lote legado (criado antes da consolidação com Domains\Tiss).
                $xmlPath = $this->tissXmlService->generate($batch);
            }

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

    /**
     * Postgres SQLSTATE 23505 (unique_violation) — usado em createBatch() pra
     * distinguir a corrida esperada (índice único parcial de schedule_id) de
     * qualquer outro erro de banco, que deve continuar subindo normalmente.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
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
