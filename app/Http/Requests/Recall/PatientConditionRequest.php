<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Enums\{EntityGate, Eye};
use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Base dos pedidos sobre as condições crônicas do paciente: ato CLÍNICO, só do médico com cadastro ativo
 * (Gate DecideRecall). Ver também é do médico — a condição é dado clínico derivado do diagnóstico.
 */
abstract class PatientConditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = Entity::query()->find((string) session('selected_entity_id'));

        return $entity !== null && Gate::allows(EntityGate::DecideRecall->value, $entity);
    }

    /** @return array<string, mixed> */
    protected function eyeRules(): array
    {
        return [
            'eye' => ['required', 'string', Rule::in(array_map(static fn (Eye $eye): string => $eye->value, Eye::cases()))],
            // O serviço confere de novo (não futura, não antes de 1900); aqui só o formato.
            'started_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
