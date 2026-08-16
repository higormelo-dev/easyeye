<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Payload do "Diagnóstico do exame" (Gerenciador de Imagens):
 * PatientExam::diagnosis_cids, uma lista de {code|custom_diagnosis_id,
 * description, is_primary}. Autorização é feita no controller via
 * Gate::authorize(EntityGate::IssueReport) — mesmo padrão de
 * AiRunsController::approve().
 */
class UpdateExamDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'diagnoses'        => ['required', 'array', 'min:1', 'max:20'],
            'diagnoses.*.code' => ['nullable', 'string', 'max:10'],
            // Escopado por entity_id (mesmo padrão de patient_id/exam_id/doctor_id
            // em ImportExternalExamRequest) — sem isso, um custom_diagnosis_id de
            // outra entidade passaria só pela checagem de formato `uuid` e vazaria
            // dado proprietário de outra clínica via DiagnosisCatalogService::mostUsed().
            'diagnoses.*.custom_diagnosis_id' => [
                'nullable',
                'uuid',
                Rule::exists('entity_custom_diagnoses', 'id')->where(
                    fn ($query) => $query->where('entity_id', $entityId)->whereNull('deleted_at'),
                ),
            ],
            'diagnoses.*.description' => ['required', 'string', 'max:500'],
            'diagnoses.*.is_primary'  => ['boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('diagnoses', []) as $index => $item) {
                $code   = is_array($item) ? ($item['code'] ?? null) : null;
                $custom = is_array($item) ? ($item['custom_diagnosis_id'] ?? null) : null;

                if (blank($code) && blank($custom)) {
                    $validator->errors()->add(
                        "diagnoses.{$index}",
                        'Informe o código CID-10 ou um diagnóstico customizado.',
                    );
                }
            }
        });
    }
}
