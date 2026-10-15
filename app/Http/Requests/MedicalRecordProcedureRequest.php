<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Recall\Intravitreal\Enums\CourseRegimen;
use App\Services\{ProcedureSolicitationService, SurgerySchedulingDocService};
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solicitação estruturada de procedimento — App\Models\MedicalRecordProcedure
 * (status nasce sempre `requested`; execução/cancelamento têm requests
 * próprios). `eye`/`solicitation_type` reaproveitam o MESMO vocabulário já
 * usado em SurgerySchedulingDocService/ProcedureSolicitationService — evita
 * um terceiro conjunto de valores pro mesmo conceito.
 */
class MedicalRecordProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entityId = session('selected_entity_id');

        return [
            // entity_id OU null (Procedure tem catálogo GLOBAL compartilhado
            // — ver ProcedureSearchController, mesmo autocomplete usado pelo
            // texto livre) — restringir só a `where('entity_id', $entityId)`
            // rejeitaria toda solicitação de um procedimento global.
            'procedure_id' => [
                'required',
                'uuid',
                Rule::exists('procedures', 'id')
                    ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at'),
            ],
            'eye'               => ['nullable', Rule::in(SurgerySchedulingDocService::eyeKeys())],
            'solicitation_type' => ['nullable', Rule::in(ProcedureSolicitationService::typeKeys())],
            'notes'             => ['nullable', 'string', 'max:2000'],
            // Injeção intravítrea (anti-VEGF): os dados do CURSO do olho. Só valem quando o procedimento escolhido é do
            // tipo intravítreo; o serviço do curso decide se precisa (curso já aberto não precisa de droga).
            'intravitreal'            => ['nullable', 'array'],
            'intravitreal.drug_key'   => ['nullable', 'string', 'max:40'],
            'intravitreal.drug_label' => ['nullable', 'string', 'max:120'],
            'intravitreal.regimen'    => ['nullable', Rule::in(array_map(static fn (CourseRegimen $regimen): string => $regimen->value, CourseRegimen::cases()))],
            'intravitreal.indication' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Os dados do curso intravítreo enviados com a solicitação (vazio quando não vieram).
     *
     * @return array{drug_key?: ?string, drug_label?: ?string, regimen?: ?string, indication?: ?string}
     */
    public function intravitreal(): array
    {
        return (array) ($this->validated('intravitreal') ?? []);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'procedure_id.required' => trans('validation.custom.generic.required'),
        ];
    }
}
