<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Exceptions\AiRunCancelledException;
use App\Domains\AI\Models\AiRun;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiRiskLevel, AiRunStatus};
use App\Models\PatientExam;
use Throwable;

class AiRunExecutionService
{
    public function __construct(
        private readonly AiRunRepositoryInterface $runRepository,
        private readonly AiOrchestrator $orchestrator,
        private readonly AiPricingService $pricingService,
        private readonly AiCreditWalletService $walletService,
        private readonly AiSafetyService $safetyService,
        private readonly EyeImageAttachmentService $eyeImageAttachments,
    ) {
    }

    public function execute(AiRun $run): void
    {
        if ($this->isTerminalStatus($run)) {
            return;
        }

        $this->runRepository->markRunning($run);

        try {
            $request  = $this->buildRequestFromRun($run);
            $result   = $this->orchestrator->execute($run, $request);
            $estimate = $this->pricingService->calculateActualCredits(
                workflow: $run->workflow,
                mode: $run->mode,
                providerResponses: $result->providerCalls,
            );

            $actualCredits   = max(0, (int) $estimate->normalizedCredits);
            $reservedCredits = max(0, (int) $run->reserved_credits);
            $consumedCredits = min($actualCredits, $reservedCredits);

            if ($consumedCredits > 0) {
                $this->walletService->consumeReservation(
                    entityId: (string) $run->entity_id,
                    amount: $consumedCredits,
                    aiRunId: (string) $run->id,
                    idempotencyKey: "ai-run:{$run->id}:consume",
                    metadata: ['workflow' => $run->workflow, 'mode' => $run->mode->value],
                );
            }

            if ($reservedCredits > $consumedCredits) {
                $this->walletService->releaseReservation(
                    entityId: (string) $run->entity_id,
                    amount: $reservedCredits - $consumedCredits,
                    aiRunId: (string) $run->id,
                    idempotencyKey: "ai-run:{$run->id}:release",
                    metadata: ['workflow' => $run->workflow, 'mode' => $run->mode->value],
                );
            }

            $safetyNotes = array_values(array_filter(array_merge(
                (array) ($run->safety_notes ?? []),
                $result->safetyNotes,
            )));

            if ($actualCredits > $reservedCredits) {
                $safetyNotes[] = 'Custo real acima da reserva inicial; crédito extra não cobrado automaticamente.';
            }

            // Inspeciona o conteúdo final através do AiSafetyService. Adiciona notas
            // detectadas (linguagem taxativa, prescrição direta, comunicação ao paciente).
            // Política atual: detectar e anotar, não bloquear — o médico decide.
            $riskLevel = $run->risk_level instanceof AiRiskLevel
                ? $run->risk_level
                : AiRiskLevel::Low;
            $safetyNotes = array_merge(
                $safetyNotes,
                $this->safetyService->inspect((string) $result->finalOutput, $riskLevel),
            );

            $this->runRepository->markWaitingApproval(
                run: $run,
                finalOutput: $result->finalOutput,
                safetyNotes: $safetyNotes,
                consumedCredits: $consumedCredits,
            );
        } catch (AiRunCancelledException) {
            // Cancelamento cooperativo: o orchestrator detectou cancelled_at entre
            // etapas e abortou. Não é falha — só ajusta o estado e devolve o saldo
            // que ainda não foi consumido. Não relança para o job não marcar failed.
            $this->compensateCancelledRun($run);
        } catch (Throwable $e) {
            $this->compensateFailedRun($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Marca o run como Failed e libera qualquer reserva pendente de forma idempotente.
     *
     * Seguro de ser chamado mais de uma vez (idempotency_key fixa). Usado:
     * - no catch() de execute(): falhas dentro do worker que ainda rodam código nosso.
     * - no failed() do RunAiWorkflowJob: falhas catastróficas após esgotar retries
     *   (timeout do worker, OOM, kill -9), onde execute() pode nem ter rodado.
     *
     * Sem este método, a reserva ficaria órfã em falha catastrófica — o que viola
     * o critério de aceite "Nenhum crédito pode ser perdido em falha".
     */
    public function compensateFailedRun(AiRun $run, string $reason): void
    {
        $this->releaseReservationOnFailure($run);

        $status = $run->status instanceof AiRunStatus
            ? $run->status
            : AiRunStatus::tryFrom((string) $run->status);

        if ($status !== AiRunStatus::Failed) {
            $this->runRepository->markFailed($run, $reason);
        }
    }

    /**
     * Aplica a compensação de um cancelamento cooperativo: marca como Cancelled
     * de forma idempotente e devolve à carteira apenas o saldo da reserva que
     * NÃO foi consumido pelas chamadas que já haviam succeeded antes do checkpoint
     * de cancel. O que foi consumido continua cobrado (é custo real do provider).
     */
    public function compensateCancelledRun(AiRun $run): void
    {
        $run->refresh();
        $status = $run->status instanceof AiRunStatus
            ? $run->status
            : AiRunStatus::tryFrom((string) $run->status);

        if ($status === AiRunStatus::Cancelled) {
            return;
        }

        $this->releaseRemainderOnCancel($run);
        $this->runRepository->markCancelled($run);
    }

    private function releaseRemainderOnCancel(AiRun $run): void
    {
        $remainder = max(0, ((int) $run->reserved_credits) - ((int) $run->consumed_credits));

        if ($remainder <= 0) {
            return;
        }

        try {
            $this->walletService->releaseReservation(
                entityId: (string) $run->entity_id,
                amount: $remainder,
                aiRunId: (string) $run->id,
                idempotencyKey: "ai-run:{$run->id}:release-on-cancel",
                metadata: ['reason' => 'workflow_cancelled'],
            );
        } catch (Throwable) {
            // Evita mascarar o cancelamento se a carteira já tiver liberado tudo
            // (idempotency_key duplicada, por exemplo). O run continua marcado
            // como Cancelled mesmo assim.
        }
    }

    private function isTerminalStatus(AiRun $run): bool
    {
        $status = $run->status instanceof AiRunStatus
            ? $run->status
            : AiRunStatus::tryFrom((string) $run->status);

        if (! $status) {
            return false;
        }

        return in_array($status, [
            AiRunStatus::WaitingApproval,
            AiRunStatus::Approved,
            AiRunStatus::Rejected,
            AiRunStatus::Cancelled,
        ], true);
    }

    private function buildRequestFromRun(AiRun $run): AiRequestData
    {
        $summary = is_array($run->input_summary) ? $run->input_summary : [];

        return new AiRequestData(
            workflow: (string) $run->workflow,
            mode: $run->mode,
            userPrompt: (string) ($summary['user_prompt'] ?? 'Gerar apoio clínico estruturado para revisão médica.'),
            systemPrompt: isset($summary['system_prompt']) ? (string) $summary['system_prompt'] : null,
            riskLevel: $run->risk_level instanceof AiRiskLevel ? $run->risk_level : AiRiskLevel::Low,
            context: (array) ($summary['context'] ?? []),
            attachments: $this->resolveAttachments($run, $summary),
            expectsJson: (bool) ($summary['expects_json'] ?? false),
            maxOutputTokens: isset($summary['max_output_tokens']) ? (int) $summary['max_output_tokens'] : null,
            metadata: (array) ($summary['metadata'] ?? []),
        );
    }

    /**
     * Resolve os anexos de imagem em tempo de execução. Para o módulo Eye Image,
     * o run guarda apenas `exam_ids` (não o base64): aqui buscamos os exames e
     * geramos os anexos inline. Para outros fluxos, usa os anexos já presentes.
     *
     * @param array<string, mixed> $summary
     *
     * @return list<array<string, mixed>>
     */
    private function resolveAttachments(AiRun $run, array $summary): array
    {
        $examIds = array_values(array_filter((array) ($summary['exam_ids'] ?? [])));

        if ($examIds !== []) {
            $exams = PatientExam::query()
                ->whereIn('id', $examIds)
                ->get();

            return $this->eyeImageAttachments->build($exams);
        }

        return (array) ($summary['attachments'] ?? []);
    }

    private function releaseReservationOnFailure(AiRun $run): void
    {
        $reservedCredits = max(0, (int) $run->reserved_credits);

        if ($reservedCredits <= 0) {
            return;
        }

        try {
            $this->walletService->releaseReservation(
                entityId: (string) $run->entity_id,
                amount: $reservedCredits,
                aiRunId: (string) $run->id,
                idempotencyKey: "ai-run:{$run->id}:release-on-failure",
                metadata: ['reason' => 'workflow_failed'],
            );
        } catch (Throwable) {
            // Evita mascarar a falha principal da execução.
        }
    }
}
