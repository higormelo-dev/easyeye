<?php

declare(strict_types=1);

namespace App\Http\Requests\ClinicalSafety;

use App\Enums\ClinicalSafety\MedicationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Medicação em uso do paciente (Fase 2 / A2): item do catálogo CMED
 * (medicine_id) ou nome livre. `source` "da clínica" só vale para o médico.
 */
class PatientMedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = self::fieldRules();

        if ($this->filled('started_on')) {
            $rules['ended_on'][] = 'after_or_equal:started_on';
        }

        return $rules;
    }

    /** @return array<string, list<mixed>> */
    public static function fieldRules(string $prefix = ''): array
    {
        return [
            "{$prefix}medicine_id"       => ['nullable', 'uuid'],
            "{$prefix}name"              => ["required_without:{$prefix}medicine_id", 'nullable', 'string', 'min:2', 'max:255'],
            "{$prefix}active_ingredient" => ['nullable', 'string', 'max:1000'],
            "{$prefix}posology"          => ['nullable', 'string', 'max:1000'],
            "{$prefix}started_on"        => ['nullable', 'date', 'before_or_equal:today'],
            "{$prefix}ended_on"          => ['nullable', 'date'],
            "{$prefix}source"            => ['nullable', 'string', Rule::in(MedicationSource::values())],
            "{$prefix}notes"             => ['nullable', 'string', 'max:2000'],
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
            "{$prefix}name"       => __('clinical_safety.medications.name'),
            "{$prefix}posology"   => __('clinical_safety.medications.posology'),
            "{$prefix}started_on" => __('clinical_safety.medications.started_on'),
            "{$prefix}ended_on"   => __('clinical_safety.medications.ended_on'),
        ];
    }
}
