<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivationStep;
use App\Models\{Entity, EntityActivation};
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rastreia os marcos de ativação de cada clínica durante o trial.
 *
 * Ativação = profundidade de uso do produto.
 * Alta ativação → maior probabilidade de conversão → menor CAC efetivo.
 *
 * Cada etapa é idempotente: chamar complete() para uma etapa já completada é seguro.
 */
class ActivationService
{
    /**
     * Marca uma etapa como concluída para uma clínica.
     * Operação idempotente: inserção silenciosa se já existir.
     */
    public function complete(string $entityId, ActivationStep $step): void
    {
        try {
            EntityActivation::firstOrCreate(
                ['entity_id' => $entityId, 'step' => $step->value],
                ['completed_at' => now()],
            );
        } catch (Throwable $e) {
            // Não deve bloquear a operação principal que gerou o evento
            Log::warning('ActivationService: falha ao registrar etapa.', [
                'entity_id' => $entityId,
                'step'      => $step->value,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retorna o score de ativação de uma clínica (0–100).
     * Calculado como soma dos pesos das etapas concluídas.
     */
    public function getScore(string $entityId): int
    {
        $completed = EntityActivation::query()
            ->where('entity_id', $entityId)
            ->pluck('step')
            ->toArray();

        return array_sum(array_map(
            fn (ActivationStep $step) => $step->weight(),
            $completed,
        ));
    }

    /**
     * Retorna o progresso completo de ativação de uma clínica.
     *
     * @return array{step: ActivationStep, label: string, weight: int, completed: bool, completed_at: ?string}[]
     */
    public function getProgress(string $entityId): array
    {
        $completedSteps = EntityActivation::query()
            ->where('entity_id', $entityId)
            ->get()
            ->keyBy('step');

        return array_map(function (ActivationStep $step) use ($completedSteps): array {
            $activation = $completedSteps->get($step->value);

            return [
                'step'         => $step->value,
                'label'        => $step->label(),
                'weight'       => $step->weight(),
                'completed'    => $activation !== null,
                'completed_at' => $activation?->completed_at?->toIso8601String(),
            ];
        }, ActivationStep::ordered());
    }

    /**
     * Retorna as etapas pendentes para exibição como nudges no trial.
     *
     * @return ActivationStep[]
     */
    public function getPendingSteps(string $entityId): array
    {
        $completedValues = EntityActivation::query()
            ->where('entity_id', $entityId)
            ->pluck('step')
            ->toArray();

        return array_values(array_filter(
            ActivationStep::ordered(),
            fn (ActivationStep $step) => ! in_array($step->value, $completedValues, true),
        ));
    }

    /**
     * Retorna clínicas em trial com score de ativação abaixo do threshold.
     * Usado para disparo de campanhas de reengajamento.
     *
     * @return array{entity_id: string, name: string, score: int, days_in_trial: int}[]
     */
    public function lowActivationTrials(int $scoreThreshold = 30): array
    {
        $trialsInProgress = Entity::query()
            ->where('is_client', true)
            ->whereHas('subscription', fn ($q) => $q->where('status', 'trial'))
            ->with('subscription')
            ->get();

        return $trialsInProgress
            ->map(fn (Entity $entity) => [
                'entity_id'     => $entity->id,
                'name'          => $entity->name,
                'score'         => $this->getScore($entity->id),
                'days_in_trial' => (int) $entity->subscription?->created_at?->diffInDays(now()),
            ])
            ->filter(fn ($item) => $item['score'] < $scoreThreshold)
            ->sortBy('score')
            ->values()
            ->toArray();
    }
}
