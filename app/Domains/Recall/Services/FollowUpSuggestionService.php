<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{ConditionStatus, IntervalUnit, RecallTaskKind};
use App\Domains\Recall\Models\{PatientCondition, RecallClinicProtocol};
use App\Enums\FeatureKey;
use App\Services\FeatureGateService;
use Carbon\CarbonImmutable;

/**
 * A sugestão do campo "Retorno" do prontuário (Fase 6): para cada condição ATIVA do paciente cujo protocolo a clínica
 * usa, o intervalo da consulta de retorno que a CLÍNICA escolheu (dentro da faixa validada), já em dias para o
 * campo `follow_up_days`. O médico decide: nada é preenchido sozinho.
 *
 * Também diz até quantos dias o retorno pedido vale na lista de retornos: o protocolo com `doctor_order.cap_to_max`
 * limita o pedido ao intervalo MÁXIMO validado da consulta (a mesma conta do motor — `RecallRuleEngine::consultationCap`),
 * e o médico merece saber antes de digitar 365 para um glaucoma.
 *
 * Só quem tem a feature no plano; a clínica sem protocolo ativo ou o paciente sem condição ativa recebem lista vazia.
 */
class FollowUpSuggestionService
{
    public function __construct(private readonly FeatureGateService $features)
    {
    }

    /**
     * @return array{suggestions: list<array<string, mixed>>, max_days: ?int}
     */
    public function forPatient(string $entityId, string $patientId, CarbonImmutable $on): array
    {
        $empty = ['suggestions' => [], 'max_days' => null];

        if (! $this->features->can($entityId, FeatureKey::HasPatientRecall)) {
            return $empty;
        }

        $on        = $on->startOfDay();
        $adoptions = RecallClinicProtocol::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deactivated_at')
            ->with(['protocol', 'version'])
            ->get()
            ->keyBy(static fn (RecallClinicProtocol $adoption): string => (string) $adoption->protocol->key);

        if ($adoptions->isEmpty()) {
            return $empty;
        }

        $conditions = PatientCondition::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('status', ConditionStatus::Active->value)
            ->get();

        $suggestions = [];
        $caps        = [];

        foreach ($conditions as $condition) {
            $adoption = $adoptions->get((string) $condition->protocol_key);
            $content  = $adoption?->usableContent();

            if ($adoption === null || $content === null) {
                continue; // protocolo que a clínica não usa, ou parado: não sugere
            }

            $task = $this->consultation($content, (array) $adoption->parameters);

            if ($task === null) {
                continue;
            }

            $unit     = IntervalUnit::from((string) $task['interval']['unit']);
            $interval = (int) $task['asked'];
            $days     = $this->days($on, $unit->addTo($on, $interval));

            if (($content['doctor_order']['cap_to_max'] ?? false) === true) {
                $caps[] = $this->days($on, $unit->addTo($on, (int) $task['interval']['max']));
            }

            $suggestions[] = [
                'protocol_key' => (string) $condition->protocol_key,
                'title'        => $adoption->protocol->title(),
                'eye'          => $condition->eye->value,
                'eye_label'    => $condition->eye->label(),
                'interval'     => $interval,
                'unit'         => $unit->value,
                'unit_label'   => $interval === 1 ? $unit->labelOne() : $unit->label(),
                'days'         => $days,
            ];
        }

        usort($suggestions, static fn (array $a, array $b): int => [$a['days'], $a['protocol_key']] <=> [$b['days'], $b['protocol_key']]);

        return ['suggestions' => $suggestions, 'max_days' => $caps === [] ? null : min($caps)];
    }

    /**
     * A tarefa de consulta ligada para a clínica e o intervalo que ela escolheu (o padrão validado sem escolha).
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    private function consultation(array $content, array $parameters): ?array
    {
        foreach ((array) $content['tasks'] as $task) {
            if ($task['kind'] !== RecallTaskKind::Consultation->value) {
                continue;
            }

            $enabled = ($task['required'] ?? false) === true || ($parameters['tasks'][$task['key']]['enabled'] ?? false) === true;

            if (! $enabled) {
                return null;
            }

            $chosen = $parameters['tasks'][$task['key']]['interval'] ?? null;

            return $task + ['asked' => is_int($chosen) ? $chosen : (int) $task['interval']['default']];
        }

        return null;
    }

    private function days(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->diffInDays($to);
    }
}
