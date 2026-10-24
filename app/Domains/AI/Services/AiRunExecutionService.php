<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Exceptions\{AiImageNotDeidentifiedException, AiRunCancelledException};
use App\Domains\AI\Models\{AiCreditLedgerEntry, AiRun};
use App\Domains\AI\Support\AiDispatchAudit;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiRiskLevel, AiRunStatus};
use App\Models\PatientExam;
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

class AiRunExecutionService
{
    private readonly AiSystemPromptResolver $promptResolver;

    public function __construct(
        private readonly AiRunRepositoryInterface $runRepository,
        private readonly AiOrchestrator $orchestrator,
        private readonly AiPricingService $pricingService,
        private readonly AiPricingPolicyResolver $policyResolver,
        private readonly AiCreditWalletService $walletService,
        private readonly AiSafetyService $safetyService,
        private readonly EyeImageAttachmentService $eyeImageAttachments,
        ?AiSystemPromptResolver $promptResolver = null,
    ) {
        // Sem dependências próprias — default seguro mantém a assinatura
        // compatível com instanciação manual (testes) e garante que o
        // endurecimento do system prompt nunca fica "desligado".
        $this->promptResolver = $promptResolver ?? new AiSystemPromptResolver();
    }

    public function execute(AiRun $run): void
    {
        if ($this->isTerminalStatus($run)) {
            return;
        }

        $this->runRepository->markRunning($run);

        try {
            [$request, $imageNotes] = $this->buildRequestFromRun($run);

            // Auditoria do envio (LGPD): versão das instruções e categorias de
            // dados — gravada antes da chamada, sem o conteúdo.
            $this->runRepository->recordDispatch($run, AiDispatchAudit::summarize($request));

            $result = $this->orchestrator->execute($run, $request);
            // Preço real com a política CONGELADA na estimativa (câmbio/markup do momento); sem snapshot (execução legada
            // convertida), vale a política vigente.
            $estimate = $this->pricingService->calculateActualCharge(
                workflow: $run->workflow,
                mode: $run->mode,
                providerResponses: $result->providerCalls,
                policy: $run->pricingPolicy() ?? $this->policyResolver->current(),
            );

            $actualCharge   = max(0, $estimate->chargeCents);
            $reservedCharge = max(0, (int) $run->reserved_charge_cents);
            $consumedCharge = min($actualCharge, $reservedCharge);

            if ($consumedCharge > 0) {
                $this->walletService->consumeReservation(
                    entityId: (string) $run->entity_id,
                    amount: $consumedCharge,
                    aiRunId: (string) $run->id,
                    idempotencyKey: "ai-run:{$run->id}:consume",
                    metadata: ['workflow' => $run->workflow, 'mode' => $run->mode->value],
                );
            }

            if ($reservedCharge > $consumedCharge) {
                $this->walletService->releaseReservation(
                    entityId: (string) $run->entity_id,
                    amount: $reservedCharge - $consumedCharge,
                    aiRunId: (string) $run->id,
                    idempotencyKey: "ai-run:{$run->id}:release",
                    metadata: ['workflow' => $run->workflow, 'mode' => $run->mode->value],
                );
            }

            $safetyNotes = array_values(array_filter(array_merge(
                (array) ($run->safety_notes ?? []),
                $imageNotes,
                $result->safetyNotes,
            )));

            if ($actualCharge > $reservedCharge) {
                // Excedente acima da reserva não é cobrado nesta entrega (comportamento mantido); fica registrado para medir.
                $safetyNotes[] = 'Custo real acima da reserva inicial; valor extra não cobrado automaticamente.';

                // Execução interna (reserva 0, ex.: posologia do manager) não é cobrada: sem métrica.
                if ($reservedCharge > 0) {
                    Log::info('ai.run.charge_above_reservation', [
                        'run_id'          => (string) $run->id,
                        'workflow'        => (string) $run->workflow,
                        'reserved_cents'  => $reservedCharge,
                        'actual_cents'    => $actualCharge,
                        'uncharged_cents' => $actualCharge - $reservedCharge,
                    ]);
                }
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
                consumedChargeCents: $consumedCharge,
                actualCostUsd: $estimate->rawCostUsd,
            );
        } catch (AiRunCancelledException) {
            // Cancelamento cooperativo: o orchestrator detectou cancelled_at entre
            // etapas e abortou. Não é falha — só ajusta o estado e devolve o saldo
            // que ainda não foi consumido. Não relança para o job não marcar failed.
            $this->compensateCancelledRun($run);
        } catch (Throwable $e) {
            // Imagem sem tarja possível: a mensagem já é a do médico (sem provedor).
            $this->compensateFailedRun($run, $e->getMessage(), doctorFacing: $e instanceof AiImageNotDeidentifiedException);

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
     *
     * $doctorFacing: o motivo já foi escrito para o médico (sem provedor/modelo)
     * e é gravado como está; os demais viram a mensagem genérica.
     */
    public function compensateFailedRun(AiRun $run, string $reason, bool $doctorFacing = false): void
    {
        $this->releaseReservationOnFailure($run);

        $status = $run->status instanceof AiRunStatus
            ? $run->status
            : AiRunStatus::tryFrom((string) $run->status);

        if ($status !== AiRunStatus::Failed) {
            $this->runRepository->markFailed($run, $doctorFacing ? $reason : $this->doctorSafeReason($reason));
        }
    }

    /**
     * Mensagem de falha exibida ao médico — NUNCA revela o provedor/modelo de IA
     * (requisito de produto: a IA é "caixa-preta" para o usuário clínico). O
     * detalhe técnico cru (com provedor) vai apenas para o log.
     */
    private function doctorSafeReason(string $reason): string
    {
        Log::warning('AI run failure (raw)', ['reason' => $reason]);

        return __('ai.run_failed_generic');
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
        $remainder = max(0, ((int) $run->reserved_charge_cents) - ((int) $run->consumed_charge_cents));

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

    /**
     * @return array{0: AiRequestData, 1: list<string>} requisição + avisos ao médico (imagens que não saíram)
     *
     * @throws AiImageNotDeidentifiedException
     */
    private function buildRequestFromRun(AiRun $run): array
    {
        $summary = is_array($run->input_summary) ? $run->input_summary : [];

        [$attachments, $context, $notes] = $this->resolveImages($run, $summary, (array) ($summary['context'] ?? []));

        // SEGURANÇA — última linha de defesa: qualquer que seja o caminho
        // que criou o run (UI, API, escalate, fila antiga), o system prompt
        // que chega ao provedor é re-derivado/endurecido aqui
        // (AiSystemPromptResolver::harden — nunca aceita valor cru de runs
        // dos workflows que não tinham prompt forçado; garante o preâmbulo).
        $workflow = (string) $run->workflow;
        $field    = isset($summary['field']) ? (string) $summary['field'] : null;

        return [new AiRequestData(
            workflow: $workflow,
            mode: $run->mode,
            userPrompt: (string) ($summary['user_prompt'] ?? 'Gerar apoio clínico estruturado para revisão médica.'),
            systemPrompt: $this->promptResolver->harden(
                $workflow,
                isset($summary['system_prompt']) ? (string) $summary['system_prompt'] : null,
                $field,
            ),
            riskLevel: $run->risk_level instanceof AiRiskLevel ? $run->risk_level : AiRiskLevel::Low,
            context: $context,
            attachments: $attachments,
            expectsJson: (bool) ($summary['expects_json'] ?? false),
            maxOutputTokens: isset($summary['max_output_tokens']) ? (int) $summary['max_output_tokens'] : null,
            metadata: (array) ($summary['metadata'] ?? []),
        ), $notes];
    }

    /**
     * Resolve os anexos de imagem em tempo de execução. Para o módulo Eye Image,
     * o run guarda apenas `exam_ids` (não o base64): aqui buscamos os exames e
     * geramos os anexos inline, já com os dados do paciente tarjados (LGPD).
     * Para outros fluxos, usa os anexos já presentes.
     *
     * Imagem que não sai (layout não reconhecido para tarjar) fica registrada
     * no vínculo run↔exame, vira aviso ao médico e sai de
     * context.selected_exams (imagem N = exame N). Se nenhuma imagem pôde
     * sair, falha antes de chamar o provedor — nada é cobrado.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $context
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: list<string>}
     *
     * @throws AiImageNotDeidentifiedException
     */
    private function resolveImages(AiRun $run, array $summary, array $context): array
    {
        $examIds = array_values(array_filter((array) ($summary['exam_ids'] ?? [])));

        if ($examIds === []) {
            return [(array) ($summary['attachments'] ?? []), $context, []];
        }

        // Ordem da seleção do médico — a mesma de context.selected_exams
        // (AiPayloadEnricher), pra imagem N bater com o exame N.
        $exams = PatientExam::query()
            ->whereIn('id', $examIds)
            ->get()
            ->sortBy(fn (PatientExam $exam) => array_search((string) $exam->id, $examIds, true))
            ->values();

        $prepared = $this->eyeImageAttachments->prepare($exams);

        // Zera TODA a seleção antes de escrever os outcomes desta tentativa
        // — nunca um update seletivo só pelas chaves de $prepared['outcomes'].
        // RunAiWorkflowJob tenta de novo em falha transitória (config
        // ai.jobs.tries, default 3) chamando execute() outra vez no MESMO
        // $run — sem isto, um exame que teve sucesso numa tentativa ANTERIOR
        // e passa a falhar nesta (arquivo some do S3, orçamento de bytes
        // muda de ordem) nunca tinha a linha atualizada: ficava com o
        // outcome de sucesso ANTIGO, de uma tentativa que não é a que
        // de fato prosseguiu/foi aprovada, fazendo um exame nunca enviado
        // NESTA tentativa contar como laudado (achado na 6ª rodada de
        // revisão adversarial Codex). PatientExam::reportedConstraint() e
        // AiRunDocumentationService::writeRunDocumentation() já leem esta
        // coluna sem confiar em mais nada — mantê-la sempre fiel à ÚLTIMA
        // tentativa basta, sem precisar mudar nenhum dos dois.
        // As duas operações (zerar, depois escrever os outcomes) sempre
        // comitavam separadas — cada update() sua própria autocommit. A
        // migration de backfill de legado (ai_run_patient_exam_backfill)
        // trava as linhas candidatas (FOR UPDATE) antes de classificar,
        // mas isso só serializa contra OUTRA transação — nunca protege
        // contra observar um run NO MEIO desta sequência: zerado (sem
        // outcome real, sem dispatch_audit ainda — grava DEPOIS desta
        // função retornar) mas ainda sem o outcome real escrito. Se a
        // migration travar exatamente nesse intervalo, o vídeo (que nunca
        // ganha outcome no laço abaixo) fica com o sentinela de legado
        // pra sempre — o laço nunca o sobrescreve, só a foto é reescrita
        // (achado na 12ª rodada de revisão adversarial Codex). Uma única
        // transação torna as duas operações atômicas: de fora, o run ou
        // está no estado ANTERIOR a esta tentativa (zero, ou outcome de
        // uma tentativa anterior) ou no estado FINAL completo — nunca o
        // intervalo no meio.
        DB::transaction(function () use ($run, $examIds, $prepared) {
            DB::table('ai_run_patient_exam')
                ->where('ai_run_id', $run->id)
                ->whereIn('patient_exam_id', $examIds)
                ->update(['image_deidentification' => null]);

            foreach ($prepared['outcomes'] as $examId => $outcome) {
                DB::table('ai_run_patient_exam')
                    ->where('ai_run_id', $run->id)
                    ->where('patient_exam_id', $examId)
                    ->update(['image_deidentification' => $outcome]);
            }
        });

        $sent    = array_column($prepared['attachments'], 'exam_id');
        $blocked = count(array_keys($prepared['outcomes'], EyeImageAttachmentService::SKIPPED_UNRECOGNIZED_LAYOUT, true));

        // $blocked só conta o motivo "layout não reconhecido" — um exame de
        // VÍDEO (aceito na importação desde a Fase 5/6a, mas que
        // encodeExam() não decodifica com GD) nunca chega a gerar outcome
        // nenhum, então caía fora daqui com $sent === [] e $blocked === 0:
        // o run seguia pro provedor sem nenhuma imagem, cobrando créditos
        // por uma análise que não analisou nada (achado via revisão Codex).
        // Qualquer motivo de "nada pôde ser enviado" falha aqui, nunca só o
        // de layout.
        if ($sent === []) {
            throw new AiImageNotDeidentifiedException($blocked > 0
                ? trans_choice('ai.eye_image_none_sent', $blocked, ['count' => $blocked])
                : trans_choice('ai.eye_image_none_available', count($examIds), ['count' => count($examIds)]));
        }

        if (isset($context['selected_exams']) && is_array($context['selected_exams'])) {
            $kept = [];

            foreach (array_values($context['selected_exams']) as $i => $exam) {
                if (in_array((string) ($examIds[$i] ?? ''), $sent, true) && is_array($exam)) {
                    $kept[] = [...$exam, 'image' => count($kept) + 1];
                }
            }

            $context['selected_exams'] = $kept;
        }

        // Diferente de $blocked (bloqueado por layout LGPD não reconhecido,
        // JÁ avisado acima): aqui são exames selecionados que nem outcome
        // chegaram a ter — nunca tentados de verdade (vídeo é o caso real;
        // GD não decodifica, então encodeExam() sai antes de registrar
        // qualquer outcome). Sem avisar separado, o médico não tinha como
        // saber que um exame da seleção ficou de fora da análise por um
        // motivo diferente de LGPD (achado via revisão adversarial Codex).
        $neverAttempted = count(array_diff($examIds, $sent, array_keys($prepared['outcomes'])));
        $notes          = array_filter([
            $blocked > 0 ? trans_choice('ai.eye_image_some_not_sent', $blocked, ['count' => $blocked]) : null,
            $neverAttempted > 0 ? trans_choice('ai.eye_image_some_unsupported', $neverAttempted, ['count' => $neverAttempted]) : null,
        ]);

        return [$prepared['attachments'], $context, array_values($notes)];
    }

    private function releaseReservationOnFailure(AiRun $run): void
    {
        $reservedCharge = max(0, (int) $run->reserved_charge_cents);

        if ($reservedCharge <= 0) {
            return;
        }

        // A falha pode ocorrer DEPOIS da liquidação normal (consume + release
        // da sobra já commitados — ex.: markWaitingApproval estourou). Liberar
        // a reserva INTEIRA aqui devolveria créditos de chamadas de provider já
        // pagas (a idempotency key desta liberação é outra, então não protege).
        // Desconta o que o ledger deste run já resolveu.
        $settled   = $this->settledCentsFromLedger($run);
        $remaining = max(0, $reservedCharge - $settled);

        if ($remaining <= 0) {
            return;
        }

        try {
            $this->walletService->releaseReservation(
                entityId: (string) $run->entity_id,
                amount: $remaining,
                aiRunId: (string) $run->id,
                idempotencyKey: "ai-run:{$run->id}:release-on-failure",
                metadata: ['reason' => 'workflow_failed', 'settled_before_failure' => $settled],
            );
        } catch (Throwable) {
            // Evita mascarar a falha principal da execução.
        }
    }

    /**
     * Soma o que a liquidação normal deste run já resolveu da reserva:
     * consumo definitivo (metadata.consumed_from_reservation — a entry Consume
     * grava amount 0) e liberação de sobra (amount da entry Release).
     */
    private function settledCentsFromLedger(AiRun $run): int
    {
        $entries = AiCreditLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $run->entity_id)
            ->whereIn('idempotency_key', [
                "ai-run:{$run->id}:consume",
                "ai-run:{$run->id}:release",
            ])
            ->get(['idempotency_key', 'amount_cents', 'metadata']);

        $settled = 0;

        foreach ($entries as $entry) {
            $settled += $entry->idempotency_key === "ai-run:{$run->id}:consume"
                ? (int) data_get($entry->metadata, 'consumed_from_reservation_cents', 0)
                : abs((int) $entry->amount_cents);
        }

        return $settled;
    }
}
