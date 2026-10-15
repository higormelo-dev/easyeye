<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Engine\RecallState;
use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus, RecallLifecycle};
use App\Domains\Recall\Models\{PatientCondition, PatientRecall, RecallClinicProtocol, RecallProtocol};
use App\Domains\Recall\Support\RecallTaskLabel;
use App\Enums\Eye;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * O que a gaveta do paciente mostra ao MÉDICO sobre as condições crônicas dele: o que o sistema detectou (com a
 * evidência em uma frase, sem o texto do prontuário), o que o médico decidiu, os retornos que cada condição ativa
 * gera e quais protocolos a clínica pode usar para registrar uma condição à mão.
 *
 * Só o perfil médico chega aqui (o controller confere o Gate): a condição é dado clínico derivado do diagnóstico.
 */
class PatientConditionsPresenter
{
    /** Quantas condições já decididas (descartadas/com alta) a gaveta lembra. */
    private const HISTORY_LIMIT = 20;

    /**
     * @return array<string, mixed>
     */
    public function present(string $entityId, string $patientId, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        $conditions = PatientCondition::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->orderByDesc('updated_at')
            ->get();

        $adopted = $this->adoptedKeys($entityId);
        $titles  = $this->titles($conditions->pluck('protocol_key')->merge(array_keys($adopted))->unique()->all());
        $recalls = $this->openRecalls($entityId, $patientId);

        $open    = $conditions->filter(static fn (PatientCondition $row): bool => $row->status->isOpen())->sortBy(static fn (PatientCondition $row): int => $row->status === ConditionStatus::Suggested ? 0 : 1);
        $history = $conditions->reject(static fn (PatientCondition $row): bool => $row->status->isOpen())->take(self::HISTORY_LIMIT);

        $row = fn (PatientCondition $condition): array => $this->condition($condition, $titles, isset($adopted[(string) $condition->protocol_key]), $recalls->get((string) $condition->id, []));

        return [
            'patient_id' => $patientId,
            'today'      => $today->toDateString(),
            'conditions' => $open->map($row)->values()->all(),
            'history'    => $history->map($row)->values()->all(),
            'protocols'  => array_map(static fn (string $key): array => ['key' => $key, 'title' => $titles[$key] ?? $key], array_keys($adopted)),
            'eyes'       => array_map(static fn (Eye $eye): array => ['value' => $eye->value, 'label' => __('recall_conditions.eye.' . $eye->value)], [Eye::Both, Eye::Right, Eye::Left]),
            't'          => trans('recall_conditions'),
        ];
    }

    /**
     * @param array<string, string>                                                                                                $titles
     * @param list<array{label: string, due_at: ?string, state: ?string, state_label: ?string, lifecycle: string, missing: mixed}> $next
     *
     * @return array<string, mixed>
     */
    private function condition(PatientCondition $condition, array $titles, bool $protocolActive, array $next): array
    {
        $key = (string) $condition->protocol_key;

        return [
            'id'               => (string) $condition->id,
            'protocol_key'     => $key,
            'title'            => $titles[$key] ?? $key,
            'eye'              => $condition->eye->value,
            'eye_label'        => __('recall_conditions.eye.' . $condition->eye->value),
            'status'           => $condition->status->value,
            'status_label'     => $condition->status->label(),
            'origin'           => $condition->origin->value,
            'origin_label'     => $condition->origin->label(),
            'started_on'       => $condition->started_on?->toDateString(),
            'evidence_text'    => $this->evidenceText($condition),
            'decided_at'       => ($condition->discharged_at ?? $condition->rejected_at ?? $condition->confirmed_at)?->toIso8601String(),
            'discharge_reason' => $condition->discharge_reason,
            'protocol_active'  => $protocolActive,
            'next'             => $condition->status === ConditionStatus::Active && $protocolActive ? $next : [],
            'can'              => [
                'confirm'   => $condition->status === ConditionStatus::Suggested,
                'reject'    => $condition->status === ConditionStatus::Suggested,
                'discharge' => $condition->status === ConditionStatus::Active,
                'edit'      => $condition->status->isOpen(),
            ],
        ];
    }

    /** A evidência em UMA frase: ids e textos livres do prontuário nunca saem daqui. */
    private function evidenceText(PatientCondition $condition): ?string
    {
        $evidence = (array) $condition->evidence;
        $date     = isset($evidence['first_on']) && is_string($evidence['first_on']) ? CarbonImmutable::parse($evidence['first_on'])->locale(app()->getLocale())->isoFormat('L') : __('recall_conditions.evidence.unknown_date');

        if (isset($evidence['code']) && is_string($evidence['code'])) {
            $code = strlen($evidence['code']) > 3 ? substr($evidence['code'], 0, 3) . '.' . substr($evidence['code'], 3) : $evidence['code'];

            return __(($evidence['signed'] ?? false) === true ? 'recall_conditions.evidence.cid_signed' : 'recall_conditions.evidence.cid_unsigned', ['code' => $code, 'date' => $date]);
        }

        if (isset($evidence['medication']) && is_string($evidence['medication'])) {
            $name = __('recall_conditions.medications.' . $evidence['medication']);

            if (str_starts_with($name, 'recall_conditions.')) {
                $name = $evidence['medication']; // chave de medicação sem rótulo: mostra a própria chave, nunca o caminho da tradução
            }

            return __(($evidence['confirmed'] ?? false) === true ? 'recall_conditions.evidence.medication' : 'recall_conditions.evidence.medication_unconfirmed', ['name' => $name, 'date' => $date]);
        }

        return $condition->origin === ConditionOrigin::Manual ? __('recall_conditions.evidence.manual') : null;
    }

    /**
     * Os protocolos que a clínica usa AGORA (adoção ativa com versão que vale): só estes geram retorno.
     *
     * @return array<string, true>
     */
    private function adoptedKeys(string $entityId): array
    {
        $keys = [];

        foreach (RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereNull('deactivated_at')->with(['protocol', 'version'])->get() as $adoption) {
            if ($adoption->usableContent() !== null) {
                $keys[(string) $adoption->protocol->key] = true;
            }
        }

        ksort($keys);

        return $keys;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function titles(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return RecallProtocol::query()->whereIn('key', $keys)->get()->mapWithKeys(static fn (RecallProtocol $protocol): array => [(string) $protocol->key => $protocol->title()])->all();
    }

    /**
     * Os retornos em aberto de cada condição (a próxima consulta/exame), para o médico ver o que a confirmação gera.
     *
     * @return Collection<string, list<array{label: string, due_at: ?string, state: ?string, state_label: ?string, lifecycle: string, missing: mixed}>>
     */
    private function openRecalls(string $entityId, string $patientId): Collection
    {
        $today = CarbonImmutable::now()->startOfDay();

        return PatientRecall::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereNotNull('patient_condition_id')
            ->whereIn('lifecycle', [RecallLifecycle::Open->value, RecallLifecycle::Snoozed->value, RecallLifecycle::NeedsInfo->value])
            ->orderBy('due_at')
            ->get()
            ->groupBy('patient_condition_id')
            ->map(static function (Collection $rows) use ($today): array {
                return $rows->map(static function (PatientRecall $recall) use ($today): array {
                    $state = $recall->due_at !== null && $recall->invite_from !== null && $recall->overdue_from !== null && $recall->lost_from !== null
                        ? RecallState::at($today, CarbonImmutable::parse($recall->invite_from), CarbonImmutable::parse($recall->due_at), CarbonImmutable::parse($recall->overdue_from), CarbonImmutable::parse($recall->lost_from))
                        : null;

                    return [
                        'label'       => RecallTaskLabel::of((string) $recall->source, (string) $recall->kind, $recall->exam_key),
                        'due_at'      => $recall->due_at !== null ? CarbonImmutable::parse($recall->due_at)->toDateString() : null,
                        'state'       => $state?->value,
                        'state_label' => $state?->label(),
                        'lifecycle'   => $recall->lifecycle->value,
                        'missing'     => ((array) $recall->meta)['missing'] ?? null,
                    ];
                })->values()->all();
            });
    }
}
