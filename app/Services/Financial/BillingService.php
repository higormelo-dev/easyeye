<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissGlosa, TissGlosaReason, TissGuide};
use App\Domains\Tiss\Services\TissWorkflowService;
use App\Enums\{BillingBatchStatus, BillingClaimStatus, CashEntryNature, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType, PaymentMethod, ScheduleSituation};
use App\Models\{BillingBatch, BillingClaim, CashClose, Covenant, FinancialCashEntry, FinancialCategory, Schedule};
use App\Support\Database\UniqueViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\{Builder as QueryBuilder, JoinClause};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Carbon, Collection};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ordem GLOBAL de locks do faturamento — createIndividual() e createBatch()
 * da mesma clínica pedem os locks sempre nesta ordem, e nenhum passo volta a
 * pedir lock de um passo anterior:
 *  1. linhas de `schedules` a faturar (lockScheduleRows: FOR NO KEY UPDATE, por
 *     id crescente) — serializa quem fatura o MESMO agendamento;
 *  2. provisionamento TISS do convênio (operadora/credencial/contrato e
 *     covenants.tiss_operator_id — inserts em índice único);
 *  3. numeração, advisory locks de transação mantidos até o commit: LOT (lote)
 *     → LOT-AAAAMM (lote TISS) → GUI (guia) → GUI-AAAAMM (guia TISS).
 *
 * Antes: o individual travava o agendamento e só depois pedia a numeração GUI;
 * o lote pegava a numeração GUI no 1º agendamento e, nos seguintes, o INSERT
 * da guia pedia FOR KEY SHARE (FK schedule_id) na linha que o individual
 * segurava => ciclo => deadlock (40P01) => HTTP 500 e lote inteiro revertido.
 * O provisionamento também invertia (lote antes da numeração, individual depois).
 *
 * FOR NO KEY UPDATE (e não FOR UPDATE): conflita com ele mesmo (lote x
 * individual) e com UPDATE/DELETE do agendamento, mas não com o FOR KEY SHARE
 * das FKs — INSERTs de outras telas que referenciam o agendamento (laudo,
 * caixa, guia) não entram na fila nem formam ciclo com o faturamento.
 *
 * Ordem de locks do CANCELAMENTO (cancelClaim/cancelBatch) e da CORREÇÃO DE
 * PENDÊNCIA (fixPendingGuide) — BillingAdjustmentService, com os helpers de
 * BillingEditLocks —, sempre nesta sequência e sem voltar atrás:
 *  0. advisory do lote (pg_try_advisory_xact_lock, chave billing_batch_edit|id)
 *     — só TENTA (nunca espera): se o envio do lote (submitBatch) está com ele,
 *     recusa na hora com "lote em envio"; senão fica com ele até o commit e o
 *     envio que começar depois é que recusa;
 *  1. linha do lote de faturamento (billing_batches FOR UPDATE), se houver;
 *  2. linhas das guias de faturamento (billing_claims FOR UPDATE, por id
 *     crescente);
 *  3. lote(s) TISS (tiss_batches FOR UPDATE, por id) e, por último, guia(s)
 *     TISS (tiss_guides FOR UPDATE, por id).
 * Nenhuma numeração (advisory LOT/GUI) e nenhum lock em `schedules`. Não fecha
 * ciclo com:
 *  - submitBatch: mesma ordem (lote → guias na transação final); a fase TISS
 *    do envio (fechar, gerar XML, enviar — I/O fora de transação) fica sob o
 *    advisory 0 em nível de SESSÃO, então cancelar/corrigir não intercala com
 *    ela (senão uma guia cancelada aqui podia ir para a operadora);
 *  - markClaimPaid/markClaimDenied: travam só a guia (e o caixa/glosa); nunca
 *    pedem o lote — quem cancela espera a guia, nunca o contrário;
 *  - createIndividual/createBatch: travam schedules + numeração, que o
 *    cancelamento não pede; o INSERT da guia nova de um atendimento cuja guia
 *    está sendo cancelada só espera o commit dela (índice único parcial);
 *  - generateBatchXml (download do XML): também trava o lote primeiro.
 *
 * ANEXAR GUIAS AO LOTE ("Adicionar guias", "Incluir em lote", "Reprocessar
 * pendentes" — BillingBatchAttachService) segue a mesma sequência 0 → 3; o
 * passo 0/1 cobre o lote de destino E os lotes de origem das guias pendentes
 * (advisory de cada um e as linhas numa consulta só, por id), então dois
 * pedidos que mexem nos mesmos lotes nunca se cruzam.
 *
 * RECEBIMENTO (markClaimPaid e o em massa — BillingBulkReceiptService): guias
 * FOR UPDATE por id crescente e só então o CashPeriodLock compartilhado (em
 * payLockedClaim); nunca pedem o lote. O fechamento de caixa só pede o
 * CashPeriodLock (exclusivo) e nenhum lock de guia — sem ciclo.
 */
class BillingService
{
    /** Ações de guia expostas à tela (allowed_actions). */
    public const CLAIM_ACTION_PAY = 'pay';

    public const CLAIM_ACTION_DENY = 'deny';

    /** Cancelar guia em rascunho (motivo obrigatório) — BillingAdjustmentService::cancelClaim(). */
    public const CLAIM_ACTION_CANCEL = 'cancel';

    /** Corrigir CID/carteirinha/autorização da guia TISS com pendência — BillingAdjustmentService::fixPendingGuide(). */
    public const CLAIM_ACTION_FIX_PENDING = 'fix_pending';

    /** "Incluir em lote": guia TISS fora de lote TISS → lote em rascunho do convênio (BillingBatchAttachService). */
    public const CLAIM_ACTION_ATTACH = 'attach';

    /** Ações de lote expostas à tela (allowed_actions). */
    public const BATCH_ACTION_SUBMIT = 'submit';

    public const BATCH_ACTION_DOWNLOAD_XML = 'download_xml';

    /** Cancelar lote em rascunho (motivo obrigatório) — BillingAdjustmentService::cancelBatch(). */
    public const BATCH_ACTION_CANCEL = 'cancel';

    /** "Adicionar guias" e "Reprocessar pendentes" — BillingBatchAttachService. */
    public const BATCH_ACTION_ADD_CLAIMS = 'add_claims';

    public const BATCH_ACTION_REPROCESS = 'reprocess_pending';

    /** "Registrar recebimento do lote" — BillingBulkReceiptService::payBatch(). */
    public const BATCH_ACTION_RECEIVE = 'receive';

    /**
     * Filtro da aba Guias que não é status da guia: guia TISS em aberto fora
     * de um lote TISS (pendência na pré-validação ou individual sem lote).
     */
    public const CLAIM_FILTER_TISS_PENDING = 'tiss_pending';

    /** Índice único parcial: uma guia ativa por agendamento (migration 2026_09_20_192029). */
    private const ACTIVE_SCHEDULE_CLAIM_UNIQUE_INDEX = 'billing_claims_active_schedule_unique';

    /**
     * Formas aceitas ao registrar o recebimento de uma guia. Cortesia (R$ 0) e
     * pagamentos combinados (exigem o detalhamento do caixa) ficam de fora.
     */
    public const RECEIPT_PAYMENT_METHODS = [
        PaymentMethod::Transfer,
        PaymentMethod::Cash,
        PaymentMethod::Credit,
    ];

    public function __construct(
        private readonly TissXmlService $tissXmlService,
        private readonly ProcedurePriceService $procedurePrices,
        private readonly ResolveTissOperatorForCovenantAction $resolveOperator,
        private readonly TissWorkflowService $tissWorkflow,
        private readonly BillingAdjustmentService $adjustments,
        private readonly BillingEditLocks $locks,
        private readonly BillingBatchAttachService $attach,
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

            // 1º lock (ordem global, ver classe): serializa 2 requests faturando o
            // mesmo agendamento (duplo clique/2 abas) e o individual x lote. O
            // índice único parcial em billing_claims(schedule_id) é o
            // cinto-e-suspensório caso o lock seja contornado (job, artisan).
            $schedule = $this->lockScheduleRows(
                Schedule::query()
                    ->with(['patient.person', 'patient.covenantPlan', 'doctor', 'covenant'])
                    ->where('entity_id', $entityId)
                    ->where('id', $data['schedule_id'])
                    ->where('situation', ScheduleSituation::Attended->value)
                    ->whereNull('deleted_at'),
            )->firstOrFail();

            $this->ensureScheduleNotBilled($schedule->id);

            // 2º: provisionamento TISS ANTES da numeração (antes vinha depois do
            // INSERT da guia, já segurando a numeração GUI — ordem inversa à do lote).
            // Convênios sem registro ANS (ex.: "Particular") são cobrança direta ao
            // beneficiário e não seguem o protocolo TISS — nesse caso o claim fica
            // sem guia TISS vinculada, exatamente como antes da consolidação.
            $contract = $schedule->covenant && $this->resolveOperator->isEligible($schedule->covenant)
                ? $this->resolveOperator->__invoke($schedule->covenant, $entityId)
                : null;

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

            // 3º: numeração GUI (claim) e, se TISS, GUI-AAAAMM (guia TISS).
            try {
                $claim = BillingClaim::query()->create($claimData);
            } catch (UniqueConstraintViolationException $exception) {
                // Guia ativa gravada por um caminho que não passou pelo lock do
                // agendamento: mesma resposta do "já faturado" acima, não HTTP 500.
                if ($this->isActiveScheduleClaimViolation($exception)) {
                    throw ValidationException::withMessages([
                        'schedule_id' => __('financial_billing.errors.schedule_already_billed'),
                    ]);
                }

                throw $exception;
            }

            if ($contract !== null) {
                $guide = $this->createTissGuideForSchedule($schedule, $claimData, $entityId, $contract, $data['eye_side'] ?? null);

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
            'beneficiary_plan'        => $this->beneficiaryPlan($schedule),
            // People só tem full_name (antes lia ->name: nulo em TODA guia TISS
            // criada pelo faturamento — XML sem nomeBeneficiario).
            'beneficiary_name'    => $schedule->patient?->person?->full_name ?? null,
            'clinical_indication' => $claimData['clinical_indication'] ?? null,
            'items'               => [[
                'tuss_code'            => $claimData['tuss_code'] ?: '10101012',
                'description'          => $claimData['procedure_description'] ?: 'CONSULTA OFTALMOLOGICA',
                'quantity'             => $claimData['quantity'],
                'unit_amount'          => $claimData['unit_price'],
                'authorization_number' => $claimData['authorization_code'] ?? null,
                'metadata'             => filled($eyeSide) ? ['eye_side' => $eyeSide] : null,
            ]],
        ]);
    }

    /**
     * Plano do paciente na guia — só quando é do convênio faturado (o do
     * agendamento pode ser outro). Coluna de 64 posições; a XML TISS não tem
     * campo de plano (é informativo na guia e na exportação LGPD).
     */
    private function beneficiaryPlan(Schedule $schedule): ?string
    {
        $plan = $schedule->patient?->covenantPlan;

        if (! $plan || (string) $plan->covenant_id !== (string) $schedule->covenant_id) {
            return null;
        }

        return mb_substr((string) $plan->name, 0, 64);
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

            // 1º lock (ordem global, ver classe): as linhas dos agendamentos, antes
            // de qualquer numeração. Sem elegíveis, nada é travado nem numerado.
            $schedules = $this->lockEligibleSchedulesForBatch(
                entityId: $entityId,
                covenantId: $covenant->id,
                dateFrom: $data['date_from'],
                dateUntil: $data['date_until'],
                selectedScheduleIds: $data['schedule_ids'] ?? [],
            );

            if ($schedules->isEmpty()) {
                throw ValidationException::withMessages([
                    'schedule_ids' => __('financial_billing.errors.no_eligible_schedules'),
                ]);
            }

            // 2º: provisionamento TISS do convênio.
            // Convênios sem registro ANS (ex.: "Particular") são cobrança direta ao
            // beneficiário e não seguem o protocolo TISS — o lote inteiro fica fora
            // do domínio Domains\Tiss, exatamente como antes da consolidação.
            $tissEligible = $this->resolveOperator->isEligible($covenant);
            $contract     = $tissEligible ? $this->resolveOperator->__invoke($covenant, $entityId) : null;
            $tissBatch    = null;

            // 3º: numeração — LOT, LOT-AAAAMM (TISS) e, no loop, GUI e GUI-AAAAMM.
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

            $quantity  = (int) ($data['quantity'] ?? 1);
            $unitPrice = $this->resolveUnitPrice($data, $covenant->id, $entityId);

            if ($contract !== null) {
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
                    // SAVEPOINT explícito: no PostgreSQL o INSERT que falha aborta a
                    // transação inteira (25P02) — sem ele, o `continue` abaixo seguiria
                    // numa transação morta e o lote todo daria 500.
                    $claim = DB::transaction(fn (): BillingClaim => BillingClaim::query()->create($claimData));
                } catch (UniqueConstraintViolationException $exception) {
                    // Índice único parcial em billing_claims(schedule_id) barrou uma
                    // guia ativa duplicada — só acontece se um caminho que não passa
                    // pelo lock do agendamento (job, artisan) faturou depois da
                    // releitura sob lock. Não derruba o lote inteiro, só pula este agendamento.
                    if ($this->isActiveScheduleClaimViolation($exception)) {
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
                    ? trim(($data['notes'] ?? '') . ' ' . __('financial_billing.batch_pending_note', ['count' => $pendingCount]))
                    : ($data['notes'] ?? null),
            ]);

            return $batch->fresh();
        });
    }

    public function submitBatch(BillingBatch $batch): BillingBatch
    {
        // Cancelar/corrigir guia deste lote (BillingAdjustmentService) não pode
        // intercalar com o envio: a fase TISS abaixo (fechar, gerar XML,
        // enviar) roda fora de transação e sem lock de linha (I/O externo).
        // Eles TENTAM o advisory deste lote, que o envio segura em sessão até o
        // fim (BillingEditLocks::whileSubmitting); o lote é relido já com o lock
        // (quem cancelou antes já commitou — sem isso, o modelo da rota, lido
        // antes, mandaria à operadora um lote recém-cancelado).
        return $this->locks->whileSubmitting($batch, fn (): BillingBatch => $this->submitLockedBatch($batch->refresh()));
    }

    /** Envio do lote (corpo original de submitBatch), sob o advisory de envio. */
    private function submitLockedBatch(BillingBatch $batch): BillingBatch
    {
        // Guarda de idempotência: 2 cliques rápidos no botão "Enviar" (ou um
        // retry após timeout de rede) não podem reenviar o mesmo lote pra
        // operadora TISS duas vezes.
        if ($batch->status === BillingBatchStatus::Submitted) {
            return $batch;
        }

        // Só rascunho vai para a operadora: lote processado/pago/rejeitado/
        // cancelado não pode ser reenviado (antes voltava para "Enviado").
        $this->assertBatchIsDraft($batch);

        // Guia cancelada continua ligada ao lote (histórico), mas não vai: um
        // lote com todas as guias canceladas não é "enviado" nem "cobrado".
        $claimsCount = $batch->claims()->where('status', '!=', BillingClaimStatus::Cancelled->value)->count();

        if ($claimsCount === 0) {
            throw ValidationException::withMessages([
                'batch' => __('financial_billing.errors.batch_empty'),
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

            $this->assertBatchIsDraft($locked);

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
                'batch' => __('financial_billing.errors.batch_all_pending'),
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
                'batch' => __('financial_billing.errors.tiss_submit_failed', ['reason' => $exception->getMessage()]),
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
            // FinancialCashEntry pra mesma guia (a checagem de receita já
            // lançada em assertClaimPayable só é segura com a linha da guia
            // travada — índice único parcial em financial_cash_entries é o
            // cinto-e-suspensório).
            $claim = BillingClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            // Idempotência: duplo submit/retry de uma guia já paga não altera
            // valor/data (antes regravava paid_amount sem mexer no lançamento
            // de caixa já criado, deixando os dois divergentes).
            if ($claim->status === BillingClaimStatus::Paid) {
                return $claim;
            }

            return $this->payLockedClaim($claim, $data);
        });
    }

    /**
     * Miolo de markClaimPaid: a guia JÁ travada (FOR UPDATE) na transação de
     * quem chama e ainda não paga (guia paga é no-op de quem chama). Mesmas
     * regras, o mesmo caixa (CashPeriodLock + período fechado) e o mesmo
     * lançamento de caixa do recebimento individual — reaproveitado pelo
     * recebimento em massa (BillingBulkReceiptService). $payBlockers:
     * claimPayBlockers() da lista inteira; null consulta só esta guia.
     *
     * @param array{paid_amount?: float|int|string|null, paid_at?: string|null, payment_method?: string|null, notes?: string|null} $data
     * @param array{rebilled: array<string, true>, launched: array<string, true>}|null                                             $payBlockers
     */
    public function payLockedClaim(BillingClaim $claim, array $data = [], ?array $payBlockers = null): BillingClaim
    {
        // Máquina de estados + atendimento refaturado + receita já lançada
        // (mesma regra que allowedClaimActions usa para oferecer a ação).
        $this->assertClaimPayable($claim, $payBlockers);

        // Padrão: valor da guia menos a glosa (antes: valor cheio, o que
        // superestimava o caixa numa glosa parcial seguida de "Pagar").
        $receivable = $this->receivableAmount($claim);
        $paidAmount = round((float) ($data['paid_amount'] ?? $receivable), 2);

        if ($paidAmount <= 0) {
            throw ValidationException::withMessages([
                'paid_amount' => __('financial_billing.errors.paid_amount_required'),
            ]);
        }

        $paidAt    = filled($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at']) : now();
        $entryDate = $paidAt->toDateString();

        // O lançamento nasce na data do recebimento: não pode cair num
        // caixa já fechado (mesma regra de CashFlowService).
        $this->assertCashPeriodOpen((string) $claim->entity_id, $entryDate);

        $changes = [
            'status'      => BillingClaimStatus::Paid->value,
            'paid_at'     => $paidAt,
            'paid_amount' => $paidAmount,
        ];

        // Receber acima do restante = glosa revertida (ex.: recurso aceito).
        // A parte recebida sai da glosa; senão Recebido + Glosado passaria
        // do Faturado no BI e no relatório de convênios.
        if ((float) $claim->glosa_amount > 0 && $paidAmount > $receivable) {
            $changes['glosa_amount'] = max(0.0, round((float) $claim->amount - $paidAmount, 2));
        }

        $claim->update($changes);

        FinancialCashEntry::query()->create([
            'entity_id'        => $claim->entity_id,
            'category_id'      => $this->defaultIncomeCategory((string) $claim->entity_id)?->id,
            'covenant_id'      => $claim->covenant_id,
            'billing_claim_id' => $claim->id,
            'entry_date'       => $entryDate,
            'description'      => __('financial_billing.cash_entry_description', ['code' => $claim->code]),
            'type'             => FinancialEntryType::Income->value,
            'status'           => FinancialEntryStatus::Paid->value,
            'amount'           => $paidAmount,
            'payment_method'   => $data['payment_method'] ?? PaymentMethod::Transfer->value,
            'nature'           => CashEntryNature::Covenant->value,
            'reference_type'   => CashEntryReferenceType::BillingClaim->value,
            'reference_id'     => $claim->id,
            'notes'            => $data['notes'] ?? null,
            'active'           => true,
        ]);

        return $claim->fresh();
    }

    public function markClaimDenied(BillingClaim $claim, array $data = []): BillingClaim
    {
        return DB::transaction(function () use ($claim, $data): BillingClaim {
            // Relock: glosa concorrente com "Registrar recebimento" da mesma guia
            // não pode deixar receita + glosa na mesma guia paga.
            $claim = BillingClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->assertClaimTransition($claim, BillingClaimStatus::Denied);

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
                        'glosa_description' => $officialReason ?? $data['notes'] ?? __('financial_billing.manual_glosa_description'),
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
            // Mesmo 1º lock de quem cancela/corrige guia do lote (ordem da
            // classe): o XML não sai com uma guia que está deixando o lote, nem
            // de um lote cancelado enquanto o download esperava.
            $batch = BillingBatch::query()
                ->where('entity_id', $batch->entity_id)
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($batch->status === BillingBatchStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'batch' => __('financial_billing.errors.batch_xml_cancelled', ['code' => $batch->code]),
                ]);
            }

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
     * Atendimentos (situação Atendido) da clínica no período, sem guia ativa.
     *
     * Mesmo resultado da versão anterior (pluck de TODOS os schedule_id de
     * guias ativas + whereNotIn e DATE(date_time) BETWEEN), mas com NOT EXISTS
     * correlacionado — o nº de bindings não cresce com o histórico (o
     * PostgreSQL recusa acima de 65.535) — e intervalo sargável
     * [from 00:00, until+1 00:00), equivalente ao DATE() para timestamp sem fuso.
     *
     * @return Builder<Schedule>
     */
    public function eligibleSchedulesQuery(string $entityId, string $dateFrom, string $dateUntil, ?string $covenantId = null): Builder
    {
        $start = Carbon::parse($dateFrom)->startOfDay()->toDateTimeString();
        $end   = Carbon::parse($dateUntil)->startOfDay()->addDay()->toDateTimeString();

        return Schedule::query()
            ->where('schedules.entity_id', $entityId)
            ->where('schedules.situation', ScheduleSituation::Attended->value)
            ->where('schedules.date_time', '>=', $start)
            ->where('schedules.date_time', '<', $end)
            ->whereNull('schedules.deleted_at')
            ->when($covenantId !== null, fn (Builder $query) => $query->where('schedules.covenant_id', $covenantId))
            ->whereNotExists(function (QueryBuilder $sub) use ($entityId): void {
                $sub->selectRaw('1')
                    ->from('billing_claims')
                    ->whereColumn('billing_claims.schedule_id', 'schedules.id')
                    ->where('billing_claims.entity_id', $entityId)
                    ->whereNotIn('billing_claims.status', [BillingClaimStatus::Cancelled->value, BillingClaimStatus::Denied->value])
                    ->whereNull('billing_claims.deleted_at');
            });
    }

    /**
     * Resumo de "A faturar" (somente leitura): atendimentos elegíveis no
     * período/convênio e o valor estimado pela tabela de preços (procedimento
     * do tipo de atendimento × convênio, mesmo mapa da agenda: a linha da
     * clínica sobrepõe a global). Agrupa no banco por (convênio,
     * procedimento): o nº de linhas lidas não cresce com o período.
     *
     * @param array<string, float>|null $priceMap "covenant_id:procedure_id" => preço (ProcedurePriceService::priceMap)
     *
     * @return array{count: int, priced_count: int, estimated_amount: float|null}
     */
    public function eligibleSummary(string $entityId, string $dateFrom, string $dateUntil, ?string $covenantId = null, ?array $priceMap = null): array
    {
        $groups = $this->eligibleSchedulesQuery($entityId, $dateFrom, $dateUntil, $covenantId)
            ->leftJoin('visit_types', function (JoinClause $join): void {
                $join->on('visit_types.id', '=', 'schedules.visit_id')->whereNull('visit_types.deleted_at');
            })
            ->toBase()
            ->selectRaw('schedules.covenant_id as covenant_id, visit_types.procedure_id as procedure_id, count(*) as total')
            ->groupBy('schedules.covenant_id', 'visit_types.procedure_id')
            ->get();

        $priceMap ??= $this->procedurePrices->priceMap($entityId);

        $count  = 0;
        $priced = 0;
        $amount = 0.0;

        foreach ($groups as $group) {
            $rows = (int) $group->total;
            $count += $rows;
            $price = filled($group->covenant_id) && filled($group->procedure_id)
                ? ($priceMap["{$group->covenant_id}:{$group->procedure_id}"] ?? null)
                : null;

            if ($price !== null) {
                $priced += $rows;
                $amount += $rows * (float) $price;
            }
        }

        return [
            'count'            => $count,
            'priced_count'     => $priced,
            'estimated_amount' => $priced > 0 ? round($amount, 2) : null,
        ];
    }

    /**
     * KPIs do Faturamento (somente leitura), sempre da clínica e no período
     * (data do atendimento) e convênio filtrados — os mesmos critérios das abas:
     *  - to_bill: eligibleSummary() (atendimentos sem guia ativa);
     *  - open: guias enviadas ainda sem recebimento nem glosa (status Enviado);
     *  - received: valor recebido das guias pagas;
     *  - denied: valor glosado das guias FATURADAS — sem rascunho nem cancelada,
     *    a regra única de BillingReportService (NOT_BILLED_STATUSES) que o
     *    Dashboard e o relatório de convênios usam: mesmo período/convênio,
     *    mesmo "Glosado" nas três telas;
     *  - tiss_pending: guias TISS em aberto fora de um lote TISS.
     *
     * @param array<string, float>|null $priceMap ver eligibleSummary()
     *
     * @return array{to_bill: array{count: int, priced_count: int, estimated_amount: float|null}, open: array{count: int, amount: float}, received: array{count: int, amount: float}, denied: array{count: int, amount: float}, tiss_pending: array{count: int}}
     */
    public function billingKpis(string $entityId, string $dateFrom, string $dateUntil, ?string $covenantId = null, ?array $priceMap = null): array
    {
        $submitted = BillingClaimStatus::Submitted->value;
        $paid      = BillingClaimStatus::Paid->value;
        $notBilled = BillingReportService::NOT_BILLED_STATUSES;
        $holders   = implode(', ', array_fill(0, count($notBilled), '?'));

        $totals = $this->claimsInPeriodQuery($entityId, $dateFrom, $dateUntil, $covenantId)
            ->toBase()
            ->selectRaw(implode(', ', [
                'COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN 1 ELSE 0 END), 0) AS open_count',
                'COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN billing_claims.amount ELSE 0 END), 0) AS open_amount',
                'COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN 1 ELSE 0 END), 0) AS received_count',
                'COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN billing_claims.paid_amount ELSE 0 END), 0) AS received_amount',
                "COALESCE(SUM(CASE WHEN billing_claims.status NOT IN ({$holders}) AND billing_claims.glosa_amount > 0 THEN 1 ELSE 0 END), 0) AS denied_count",
                "COALESCE(SUM(CASE WHEN billing_claims.status NOT IN ({$holders}) THEN billing_claims.glosa_amount ELSE 0 END), 0) AS denied_amount",
            ]), [$submitted, $submitted, $paid, $paid, ...$notBilled, ...$notBilled])
            ->first();

        $tissPending = $this->whereTissPending($this->claimsInPeriodQuery($entityId, $dateFrom, $dateUntil, $covenantId))->count();

        return [
            'to_bill'      => $this->eligibleSummary($entityId, $dateFrom, $dateUntil, $covenantId, $priceMap),
            'open'         => ['count' => (int) ($totals->open_count ?? 0), 'amount' => round((float) ($totals->open_amount ?? 0), 2)],
            'received'     => ['count' => (int) ($totals->received_count ?? 0), 'amount' => round((float) ($totals->received_amount ?? 0), 2)],
            'denied'       => ['count' => (int) ($totals->denied_count ?? 0), 'amount' => round((float) ($totals->denied_amount ?? 0), 2)],
            'tiss_pending' => ['count' => $tissPending],
        ];
    }

    /**
     * Guias da aba Guias (somente leitura): período pela data do atendimento,
     * convênio, status (ou CLAIM_FILTER_TISS_PENDING) e lote. Com lote
     * filtrado o período não se aplica — o lote já delimita as guias. Traz a
     * coluna `tiss_attached` (1/null): guia TISS anexada a um lote TISS.
     *
     * @return Builder<BillingClaim>
     */
    public function claimsListQuery(
        string $entityId,
        string $dateFrom,
        string $dateUntil,
        ?string $covenantId = null,
        ?string $status = null,
        ?string $batchId = null,
    ): Builder {
        $query = $batchId !== null
            ? BillingClaim::query()
                ->where('billing_claims.entity_id', $entityId)
                ->where('billing_claims.batch_id', $batchId)
                ->when($covenantId !== null, fn (Builder $q) => $q->where('billing_claims.covenant_id', $covenantId))
            : $this->claimsInPeriodQuery($entityId, $dateFrom, $dateUntil, $covenantId);

        $query->select('billing_claims.*')->selectSub($this->tissAttachedSubquery(), 'tiss_attached');

        if ($status === self::CLAIM_FILTER_TISS_PENDING) {
            return $this->whereTissPending($query);
        }

        return $query->when($status !== null, fn (Builder $q) => $q->where('billing_claims.status', $status));
    }

    /**
     * Lotes da aba Lotes (somente leitura): período do lote (atendimentos) que
     * cruza o filtrado — lote legado sem período entra pela data de criação —
     * e convênio. Com lote filtrado, só ele (independe do período).
     *
     * @return Builder<BillingBatch>
     */
    public function batchesListQuery(string $entityId, string $dateFrom, string $dateUntil, ?string $covenantId = null, ?string $batchId = null): Builder
    {
        $query = BillingBatch::query()
            ->where('billing_batches.entity_id', $entityId)
            ->when($covenantId !== null, fn (Builder $q) => $q->where('billing_batches.covenant_id', $covenantId));

        if ($batchId !== null) {
            return $query->whereKey($batchId);
        }

        $start = Carbon::parse($dateFrom)->startOfDay()->toDateTimeString();
        $end   = Carbon::parse($dateUntil)->startOfDay()->addDay()->toDateTimeString();

        return $query->where(function (Builder $q) use ($dateFrom, $dateUntil, $start, $end): void {
            $q->where(function (Builder $period) use ($dateFrom, $dateUntil): void {
                $period->whereNotNull('billing_batches.period_start')
                    ->where('billing_batches.period_start', '<=', $dateUntil)
                    ->where(fn (Builder $e) => $e->whereNull('billing_batches.period_end')->orWhere('billing_batches.period_end', '>=', $dateFrom));
            })->orWhere(function (Builder $legacy) use ($start, $end): void {
                $legacy->whereNull('billing_batches.period_start')
                    ->where('billing_batches.created_at', '>=', $start)
                    ->where('billing_batches.created_at', '<', $end);
            });
        });
    }

    /**
     * Guias da clínica com atendimento no período e (opcional) convênio.
     *
     * @return Builder<BillingClaim>
     */
    private function claimsInPeriodQuery(string $entityId, string $dateFrom, string $dateUntil, ?string $covenantId): Builder
    {
        return BillingClaim::query()
            ->where('billing_claims.entity_id', $entityId)
            ->whereBetween('billing_claims.attendance_date', [$dateFrom, $dateUntil])
            ->when($covenantId !== null, fn (Builder $q) => $q->where('billing_claims.covenant_id', $covenantId));
    }

    /**
     * Guia TISS em aberto (rascunho/enviada) sem vínculo ativo com lote TISS:
     * pendência na pré-validação (a guia não entra no lote) ou guia individual.
     *
     * @param Builder<BillingClaim> $query
     *
     * @return Builder<BillingClaim>
     */
    private function whereTissPending(Builder $query): Builder
    {
        return $query
            ->whereNotNull('billing_claims.tiss_guide_id')
            ->whereIn('billing_claims.status', [BillingClaimStatus::Draft->value, BillingClaimStatus::Submitted->value])
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('tiss_batch_guides')
                    ->whereColumn('tiss_batch_guides.guide_id', 'billing_claims.tiss_guide_id')
                    ->whereColumn('tiss_batch_guides.entity_id', 'billing_claims.entity_id')
                    ->whereNull('tiss_batch_guides.deleted_at');
            });
    }

    /** Subconsulta 1/null: a guia TISS da linha de billing_claims está num lote TISS. */
    private function tissAttachedSubquery(): QueryBuilder
    {
        return DB::table('tiss_batch_guides')
            ->selectRaw('1')
            ->whereColumn('tiss_batch_guides.guide_id', 'billing_claims.tiss_guide_id')
            ->whereColumn('tiss_batch_guides.entity_id', 'billing_claims.entity_id')
            ->whereNull('tiss_batch_guides.deleted_at')
            ->limit(1);
    }

    /**
     * Ações que a tela pode oferecer para a guia (mesma regra dos guards de
     * markClaimPaid/markClaimDenied — a UI não decide transição sozinha).
     * $payBlockers vem de claimPayBlockers() e $attachTargets de
     * BillingBatchAttachService::covenantsWithAttachTarget() para a lista
     * inteira; null consulta só esta guia.
     *
     * @param array{rebilled: array<string, true>, launched: array<string, true>}|null $payBlockers
     * @param array<string, true>|null                                                 $attachTargets
     *
     * @return list<string>
     */
    public function allowedClaimActions(BillingClaim $claim, ?array $payBlockers = null, ?array $attachTargets = null): array
    {
        $actions = [];

        if ($this->claimPayError($claim, $payBlockers) === null) {
            $actions[] = self::CLAIM_ACTION_PAY;
        }

        if ($this->claimTransitionError($claim, BillingClaimStatus::Denied) === null) {
            $actions[] = self::CLAIM_ACTION_DENY;
        }

        // Mesma regra dos guards de cancelClaim/fixPendingGuide
        // (BillingAdjustmentService), com o que a lista já carregou (lote,
        // lote TISS, guia TISS e `tiss_attached`).
        if ($this->adjustments->claimCancelError($claim) === null) {
            $actions[] = self::CLAIM_ACTION_CANCEL;
        }

        if ($this->adjustments->claimFixPendingError($claim, (bool) $claim->getAttribute('tiss_attached')) === null) {
            $actions[] = self::CLAIM_ACTION_FIX_PENDING;
        }

        return [...$actions, ...$this->attach->allowedClaimActions($claim, $attachTargets)];
    }

    /**
     * Ações que a tela pode oferecer para o lote (mesmas regras dos guards).
     * A lista traz `claims_count`/`open_claims_count` (withCount) e o lote TISS.
     *
     * @return list<string>
     */
    public function allowedBatchActions(BillingBatch $batch): array
    {
        $actions = [];

        if ($batch->status === BillingBatchStatus::Draft) {
            $actions[] = self::BATCH_ACTION_SUBMIT;
        }

        // XML TISS não faz sentido para cobrança particular (cairia no gerador
        // legado) nem para lote cancelado (nada vai para a operadora).
        if (! $this->isParticularBatch($batch) && $batch->status !== BillingBatchStatus::Cancelled) {
            $actions[] = self::BATCH_ACTION_DOWNLOAD_XML;
        }

        $tissBatch = $batch->tiss_batch_id ? $batch->tissBatch : null;

        if ($this->adjustments->batchCancelError($batch, $tissBatch) === null) {
            $actions[] = self::BATCH_ACTION_CANCEL;
        }

        if (BillingBulkReceiptService::batchReceiveError($batch) === null) {
            $actions[] = self::BATCH_ACTION_RECEIVE;
        }

        return [...$actions, ...$this->attach->allowedBatchActions($batch, $tissBatch)];
    }

    /**
     * Lote de cobrança particular (convênio sem registro ANS). Lote com
     * tiss_batch é TISS; lote legado sem tiss_batch é TISS se o convênio for.
     */
    public function isParticularBatch(BillingBatch $batch): bool
    {
        if ($batch->tiss_batch_id) {
            return false;
        }

        return ! $batch->covenant || ! $this->resolveOperator->isEligible($batch->covenant);
    }

    /** Valor esperado no recebimento: valor da guia menos a glosa (nunca negativo). */
    public function receivableAmount(BillingClaim $claim): float
    {
        return max(0.0, round((float) $claim->amount - (float) $claim->glosa_amount, 2));
    }

    /**
     * Máquina de estados da guia: rascunho → enviada → paga | glosada, e
     * glosada → paga (recebimento do restante / recurso aceito). Paga não
     * pode ser glosada; cancelada não aceita nada.
     *
     * Rascunho dentro de um lote ainda não enviado é bloqueado (nada chegou à
     * operadora). Rascunho SEM lote (guia individual) continua aceito: não
     * existe etapa de envio para guia individual — bloqueá-la deixaria essas
     * guias sem nenhum caminho até o recebimento.
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    private function claimTransitionError(BillingClaim $claim, BillingClaimStatus $target): ?string
    {
        $status = $claim->status;

        if ($status === BillingClaimStatus::Cancelled) {
            return 'claim_cancelled';
        }

        if ($status === BillingClaimStatus::Draft) {
            return $claim->batch_id ? 'claim_batch_not_submitted' : null;
        }

        return match ($target) {
            BillingClaimStatus::Paid   => $status === BillingClaimStatus::Paid ? 'claim_already_paid' : null,
            BillingClaimStatus::Denied => match ($status) {
                BillingClaimStatus::Paid   => 'claim_paid_cannot_be_denied',
                BillingClaimStatus::Denied => 'claim_already_denied',
                default                    => null,
            },
            default => null,
        };
    }

    private function assertClaimTransition(BillingClaim $claim, BillingClaimStatus $target): void
    {
        $error = $this->claimTransitionError($claim, $target);

        if ($error !== null) {
            throw ValidationException::withMessages([
                'status' => __("financial_billing.errors.{$error}", ['code' => $claim->code]),
            ]);
        }
    }

    /**
     * Bloqueios de "Registrar recebimento" que dependem de outras linhas,
     * calculados em até 2 consultas para a lista inteira (a tela chama uma vez;
     * sem N+1). Chaveados pelo id da guia:
     *  - rebilled: guia glosada cujo atendimento já tem outra guia ativa
     *    (glosada libera o atendimento para refaturar; pagar a antiga violaria
     *    o índice único parcial → erro 500);
     *  - launched: guia ainda não paga que já tem receita no caixa (legado: paga
     *    e depois glosada pela tela antiga). Pagar de novo gravaria um
     *    paid_amount diferente do lançamento, que não pode ser editado no caixa.
     *
     * @param iterable<BillingClaim> $claims
     *
     * @return array{rebilled: array<string, true>, launched: array<string, true>}
     */
    public function claimPayBlockers(iterable $claims): array
    {
        $claims = collect($claims);

        $open = $claims->filter(fn (BillingClaim $c) => ! in_array($c->status, [BillingClaimStatus::Paid, BillingClaimStatus::Cancelled], true));

        $launched = $open->isEmpty() ? [] : FinancialCashEntry::query()
            ->whereIn('entity_id', $open->pluck('entity_id')->unique()->values()->all())
            ->whereIn('billing_claim_id', $open->pluck('id')->all())
            ->where('type', FinancialEntryType::Income->value)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('billing_claim_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();

        $denied = $claims->filter(fn (BillingClaim $c) => $c->status === BillingClaimStatus::Denied && filled($c->schedule_id));

        $activeSchedules = $denied->isEmpty() ? [] : BillingClaim::query()
            ->whereIn('entity_id', $denied->pluck('entity_id')->unique()->values()->all())
            ->whereIn('schedule_id', $denied->pluck('schedule_id')->unique()->values()->all())
            ->whereNotIn('status', [BillingClaimStatus::Cancelled->value, BillingClaimStatus::Denied->value])
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('schedule_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();

        // A guia ativa nunca é a própria glosada (status diferente), então
        // "atendimento com guia ativa" basta para marcar a glosada.
        $rebilled = $denied
            ->filter(fn (BillingClaim $c) => isset($activeSchedules[(string) $c->schedule_id]))
            ->mapWithKeys(fn (BillingClaim $c) => [(string) $c->id => true])
            ->all();

        return ['rebilled' => $rebilled, 'launched' => $launched];
    }

    /**
     * Regra única de "Registrar recebimento": máquina de estados + bloqueios de
     * claimPayBlockers. Usada pelo guard (markClaimPaid/payLockedClaim), por
     * allowedClaimActions e pelo recebimento em massa (BillingBulkReceiptService).
     *
     * @param array{rebilled: array<string, true>, launched: array<string, true>}|null $payBlockers
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public function claimPayError(BillingClaim $claim, ?array $payBlockers = null): ?string
    {
        $error = $this->claimTransitionError($claim, BillingClaimStatus::Paid);

        if ($error !== null) {
            return $error;
        }

        $payBlockers ??= $this->claimPayBlockers([$claim]);
        $id = (string) $claim->id;

        return match (true) {
            isset($payBlockers['launched'][$id]) => 'claim_has_cash_entry',
            isset($payBlockers['rebilled'][$id]) => 'claim_schedule_rebilled',
            default                              => null,
        };
    }

    /** @param array{rebilled: array<string, true>, launched: array<string, true>}|null $payBlockers */
    private function assertClaimPayable(BillingClaim $claim, ?array $payBlockers = null): void
    {
        $error = $this->claimPayError($claim, $payBlockers);

        if ($error !== null) {
            throw ValidationException::withMessages([
                'status' => __("financial_billing.errors.{$error}", ['code' => $claim->code]),
            ]);
        }
    }

    private function assertBatchIsDraft(BillingBatch $batch): void
    {
        if ($batch->status === BillingBatchStatus::Draft) {
            return;
        }

        throw ValidationException::withMessages([
            'batch' => __('financial_billing.errors.batch_not_draft', [
                'code'   => $batch->code,
                'status' => mb_strtolower($batch->status->label()),
            ]),
        ]);
    }

    /**
     * Mesma regra de CashFlowService::assertDateNotClosed para o lançamento
     * automático, sob o mesmo lock de escrita do caixa (CashPeriodLock): o
     * recebimento não cai num período que está sendo fechado.
     */
    private function assertCashPeriodOpen(string $entityId, string $date): void
    {
        CashPeriodLock::forWriting($entityId);

        $closed = CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                'paid_at' => __('financial_billing.errors.paid_at_closed_period', [
                    'date' => Carbon::parse($date)->isoFormat('L'),
                ]),
            ]);
        }
    }

    /**
     * Agendamentos do lote, travados (1º passo da ordem global) e relidos.
     *
     *  1. trava as linhas elegíveis por id crescente: dois lotes com
     *     agendamentos em comum pegam os locks na mesma ordem (sem ciclo);
     *  2. relê a elegibilidade num NOVO comando: no READ COMMITTED o NOT EXISTS
     *     do comando que travou usa o snapshot de ANTES da espera, então não vê
     *     a guia que quem segurava a linha acabou de commitar; o novo comando vê;
     *  3. só fatura o que foi travado E continua elegível (o que ficou elegível
     *     entre os dois comandos fica para o próximo lote, sem lock nenhum).
     *
     * Interseção feita em PHP (e não whereIn com os ids travados): o nº de
     * bindings não cresce com o tamanho do lote.
     *
     * @param string[] $selectedScheduleIds
     *
     * @return Collection<int, Schedule>
     */
    private function lockEligibleSchedulesForBatch(
        string $entityId,
        string $covenantId,
        string $dateFrom,
        string $dateUntil,
        array $selectedScheduleIds = [],
    ): Collection {
        $eligible = fn (): Builder => $this->eligibleSchedulesQuery($entityId, $dateFrom, $dateUntil, $covenantId)
            ->when(! empty($selectedScheduleIds), fn (Builder $query) => $query->whereIn('schedules.id', $selectedScheduleIds))
            ->orderBy('schedules.id');

        $locked = $this->lockScheduleRows($eligible())
            ->pluck('schedules.id')
            ->mapWithKeys(fn ($id): array => [(string) $id => true])
            ->all();

        if ($locked === []) {
            return collect();
        }

        // Releitura sem lock; paciente/pessoa já carregados para o nome do
        // beneficiário de cada guia TISS do lote (sem 1 consulta por guia).
        return $eligible()->with(['patient.person', 'patient.covenantPlan'])->get()
            ->filter(fn (Schedule $schedule): bool => isset($locked[(string) $schedule->id]))
            ->values()
            ->toBase();
    }

    /**
     * Lock de faturamento nas linhas de `schedules` (ver ordem global na classe).
     * PostgreSQL: FOR NO KEY UPDATE. Demais drivers: FOR UPDATE (MySQL não tem
     * NO KEY UPDATE; SQLite ignora locks de linha).
     *
     * @param Builder<Schedule> $query
     *
     * @return Builder<Schedule>
     */
    private function lockScheduleRows(Builder $query): Builder
    {
        return $query->getConnection()->getDriverName() === 'pgsql'
            ? $query->lock('for no key update')
            : $query->lockForUpdate();
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
                'schedule_id' => __('financial_billing.errors.schedule_already_billed'),
            ]);
        }
    }

    /**
     * A violação é do índice único parcial de guia ativa por agendamento? Só
     * essa corrida é tratada (pendência no lote / "já faturado" no individual);
     * qualquer outra violação única (ex.: código esgotado após as novas
     * tentativas) sobe normalmente. Pelo nome do índice na mensagem do driver,
     * em qualquer idioma do servidor — nunca pelo SQL/bindings (UniqueViolation).
     */
    private function isActiveScheduleClaimViolation(UniqueConstraintViolationException $exception): bool
    {
        return UniqueViolation::violates($exception, self::ACTIVE_SCHEDULE_CLAIM_UNIQUE_INDEX);
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
