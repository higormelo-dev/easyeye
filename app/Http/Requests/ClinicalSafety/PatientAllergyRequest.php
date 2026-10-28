<?php

declare(strict_types=1);

namespace App\Http\Requests\ClinicalSafety;

use App\Enums\ClinicalSafety\{AllergyCategory, AllergySeverity, AllergyStatus};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alergia do paciente (Fase 2 / A1). `status` só vale para o médico com
 * cadastro ativo (o service ignora para os demais perfis — a recepção sempre
 * registra "informada pelo paciente").
 */
class PatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /**
     * Mesmas regras no cadastro do paciente (lista de alergias informadas
     * junto com o paciente novo).
     *
     * @return array<string, list<mixed>>
     */
    public static function fieldRules(string $prefix = ''): array
    {
        return [
            "{$prefix}category"          => ['required', 'string', Rule::in(AllergyCategory::values())],
            "{$prefix}substance"         => ['required', 'string', 'min:2', 'max:160'],
            "{$prefix}medicine_id"       => ['nullable', 'uuid'],
            "{$prefix}active_ingredient" => ['nullable', 'string', 'max:1000'],
            "{$prefix}reaction"          => ['nullable', 'string', 'max:500'],
            "{$prefix}severity"          => ['nullable', 'string', Rule::in(AllergySeverity::values())],
            "{$prefix}notes"             => ['nullable', 'string', 'max:2000'],
            "{$prefix}status"            => ['nullable', 'string', Rule::in([AllergyStatus::Reported->value, AllergyStatus::Confirmed->value])],
        ];
    }

    public function attributes(): array
    {
        return self::fieldAttributes();
    }

    /** @return array<string, string> */
    public static function fieldAttributes(string $prefix = ''): array
    {
        return [
            "{$prefix}category"  => __('clinical_safety.allergies.category'),
            "{$prefix}substance" => __('clinical_safety.allergies.substance'),
            "{$prefix}reaction"  => __('clinical_safety.allergies.reaction'),
            "{$prefix}severity"  => __('clinical_safety.allergies.severity'),
            "{$prefix}notes"     => __('clinical_safety.allergies.notes'),
        ];
    }
}
