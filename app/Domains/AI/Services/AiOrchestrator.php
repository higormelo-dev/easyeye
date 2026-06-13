<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\{AiCircuitBreakerInterface, AiProviderInterface, AiRunProviderCallStoreInterface};
use App\Domains\AI\Exceptions\AiRunCancelledException;
use App\Domains\AI\Models\AiRun;
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData, AiWorkflowResultData};
use App\Enums\AI\{AiProviderCallRole, AiRunMode};
use RuntimeException;
use Throwable;

class AiOrchestrator
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiRunProviderCallStoreInterface $callStore,
        private readonly AiPricingService $pricingService,
        private readonly AiCircuitBreakerInterface $circuitBreaker,
    ) {
    }

    /**
     * Executa um workflow de IA seguindo o modo configurado no request.
     *
     * Topologia de execução por modo:
     * - economy:   1 chamada (Generator).
     * - validated: 2 chamadas em cadeia — Generator → Reviewer; o reviewer recebe
     *              o output do generator como contexto e propõe melhorias clínicas.
     * - consensus: 3 chamadas SEQUENCIAIS encadeadas — Generator → Reviewer → Adjudicator.
     *              O adjudicator recebe as duas respostas anteriores (gen + rev) e
     *              consolida em uma versão única. Esta é uma escolha deliberada:
     *              evita o custo de 3 chamadas paralelas + 1 comparador (=4 chamadas),
     *              e prioriza a melhoria progressiva da resposta ao invés de votação
     *              entre rascunhos independentes. Cada chamada é persistida com seu
     *              próprio role em `ai_run_provider_calls` para auditoria CFM/LGPD.
     */
    public function execute(AiRun $run, AiRequestData $request): AiWorkflowResultData
    {
        $steps           = $this->providerManager->providersForMode($request->mode);
        $responses       = [];
        $previousOutputs = [];
        $entityId        = (string) $run->entity_id;

        foreach ($steps as $step) {
            $role = $step['role'];

            // Checkpoint cooperativo de cancelamento. O controller pode marcar
            // cancelled_at a qualquer momento; aqui aplicamos a barreira: se for
            // detectado, abortamos antes da próxima chamada ao provider. A chamada
            // anterior (se houver) já consumiu seus créditos no fluxo padrão.
            $run->refresh();

            if ($run->cancelled_at !== null) {
                throw new AiRunCancelledException();
            }

            $run->update([
                'current_role'     => $role->value,
                'current_provider' => null,
            ]);

            $stepRequest = $this->stepRequest($request, $role, $previousOutputs);
            $chain       = $this->providerManager->fallbackChainForRole($role);
            $response    = $this->runWithFallback(
                run: $run,
                request: $request,
                stepRequest: $stepRequest,
                role: $role,
                chain: $chain,
                entityId: $entityId,
            );

            $responses[]       = $response;
            $previousOutputs[] = $response->content;
        }

        // Workflow concluído com sucesso — limpa marcadores de etapa em execução.
        $run->update([
            'current_role'     => null,
            'current_provider' => null,
        ]);

        return new AiWorkflowResultData(
            workflow: $request->workflow,
            mode: $request->mode,
            finalOutput: $this->composeFinalOutput($request->mode, $responses),
            providerCalls: $responses,
            safetyNotes: $this->safetyNotes($request->mode),
            metadata: [
                'run_id'               => (string) $run->id,
                'provider_calls_count' => count($responses),
            ],
        );
    }

    /**
     * Tenta os providers em ordem (chain) para um step. Para cada candidato:
     *   - Pula se o circuit breaker do provider está aberto (registra como skipped).
     *   - Tenta a chamada. Em sucesso: registra success no breaker + persiste a call e retorna.
     *   - Em falha: registra failure no breaker + persiste a call como failed e segue para o próximo.
     * Se todos falharem, re-lança a última exception (preservando comportamento atual de erro).
     *
     * @param list<AiProviderInterface> $chain
     */
    private function runWithFallback(
        AiRun $run,
        AiRequestData $request,
        AiRequestData $stepRequest,
        AiProviderCallRole $role,
        array $chain,
        string $entityId,
    ): AiProviderResponseData {
        if ($chain === []) {
            throw new RuntimeException("Nenhum provider disponível para o papel [{$role->value}].");
        }

        $lastException = null;

        foreach ($chain as $index => $provider) {
            $providerEnum = $provider->provider();
            $providerCode = $providerEnum->value;
            $isFallback   = $index > 0;

            // Circuit breaker aberto → não tenta, vai para o próximo provider.
            if ($this->circuitBreaker->isOpen($providerEnum, $entityId)) {
                $this->callStore->store(
                    aiRunId: (string) $run->id,
                    role: $role,
                    status: 'skipped',
                    response: null,
                    errorMessage: 'Circuit breaker aberto. Pulando para fallback.',
                    metadata: [
                        'workflow'    => $request->workflow,
                        'provider'    => $providerCode,
                        'is_fallback' => $isFallback,
                        'reason'      => 'circuit_open',
                    ],
                );

                continue;
            }

            // Sinaliza para o painel qual provider está respondendo agora ("Revisando
            // com Claude…"). Persistido em ai_runs para o show() devolver sem precisar
            // ler ai_run_provider_calls.
            $run->update(['current_provider' => $providerCode]);

            try {
                $response = $provider->generate($stepRequest);

                $this->circuitBreaker->recordSuccess($providerEnum, $entityId);

                $this->callStore->store(
                    aiRunId: (string) $run->id,
                    role: $role,
                    status: 'success',
                    response: $response,
                    normalizedCredits: $this->pricingService->normalizeCreditsForResponse($response),
                    metadata: [
                        'workflow'    => $request->workflow,
                        'provider'    => $providerCode,
                        'is_fallback' => $isFallback,
                    ],
                );

                return $response;
            } catch (Throwable $e) {
                $lastException = $e;

                $this->circuitBreaker->recordFailure(
                    provider: $providerEnum,
                    triggerType: $this->classifyFailure($e),
                    entityId: $entityId,
                );

                $this->callStore->store(
                    aiRunId: (string) $run->id,
                    role: $role,
                    status: 'failed',
                    response: new AiProviderResponseData(
                        provider: $providerEnum,
                        model: 'unknown',
                        content: '',
                        usage: new AiUsageData(),
                        latencyMs: 0,
                    ),
                    errorMessage: mb_substr($e->getMessage(), 0, 2000),
                    metadata: [
                        'workflow'    => $request->workflow,
                        'provider'    => $providerCode,
                        'is_fallback' => $isFallback,
                    ],
                );

                // Loop continua → próximo provider da chain.
            }
        }

        // Esgotou a chain sem sucesso. Re-lança o último erro para o execution service
        // entrar no fluxo de compensateFailedRun (libera reserva, marca Failed).
        throw $lastException ?? new RuntimeException(
            "Todos os providers da chain falharam para o papel [{$role->value}].",
        );
    }

    private function classifyFailure(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'timeout')                                       => 'timeout',
            str_contains($message, 'connect')                                       => 'connection',
            str_contains($message, '401') || str_contains($message, 'unauthorized') => 'auth',
            str_contains($message, '429') || str_contains($message, 'rate')         => 'rate_limit',
            str_contains($message, '5')                                             => 'server_error',
            default                                                                 => 'unknown',
        };
    }

    /**
     * @param list<string> $previousOutputs
     */
    private function stepRequest(AiRequestData $base, AiProviderCallRole $role, array $previousOutputs): AiRequestData
    {
        if ($role === AiProviderCallRole::Generator) {
            return $base;
        }

        if ($role === AiProviderCallRole::Reviewer) {
            $reviewPrompt = "Revise a resposta gerada e proponha melhorias clínicas prudentes.\n\n"
                . "Resposta inicial:\n"
                . ($previousOutputs[0] ?? '');

            return new AiRequestData(
                workflow: $base->workflow,
                mode: $base->mode,
                userPrompt: $reviewPrompt,
                systemPrompt: $base->systemPrompt,
                riskLevel: $base->riskLevel,
                context: $base->context,
                attachments: $base->attachments,
                expectsJson: $base->expectsJson,
                maxOutputTokens: $base->maxOutputTokens,
                metadata: array_merge($base->metadata, ['role' => $role->value]),
            );
        }

        $adjudicationPrompt = "Consolide as respostas abaixo em uma versão única, segura e objetiva.\n\n";
        $adjudicationPrompt .= "Resposta 1:\n" . ($previousOutputs[0] ?? '') . "\n\n";
        $adjudicationPrompt .= "Resposta 2:\n" . ($previousOutputs[1] ?? '');

        return new AiRequestData(
            workflow: $base->workflow,
            mode: $base->mode,
            userPrompt: $adjudicationPrompt,
            systemPrompt: $base->systemPrompt,
            riskLevel: $base->riskLevel,
            context: $base->context,
            attachments: $base->attachments,
            expectsJson: $base->expectsJson,
            maxOutputTokens: $base->maxOutputTokens,
            metadata: array_merge($base->metadata, ['role' => $role->value]),
        );
    }

    /**
     * @param list<AiProviderResponseData> $responses
     */
    private function composeFinalOutput(AiRunMode $mode, array $responses): string
    {
        if ($mode === AiRunMode::Economy) {
            return $responses[0]->content ?? '';
        }

        if ($mode === AiRunMode::Validated) {
            return $responses[1]->content ?? ($responses[0]->content ?? '');
        }

        return $responses[2]->content
            ?? ($responses[1]->content ?? ($responses[0]->content ?? ''));
    }

    /**
     * @return list<string>
     */
    private function safetyNotes(AiRunMode $mode): array
    {
        $notes = [
            'Conteúdo de apoio clínico. Requer validação e aprovação médica final.',
            'A IA não substitui julgamento clínico, diagnóstico médico ou decisão terapêutica.',
        ];

        if ($mode === AiRunMode::Consensus) {
            $notes[] = 'Revisão inteligente de consistência executada com validação adicional.';
        }

        return $notes;
    }
}
