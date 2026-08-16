<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Payload de "Importar exame externo" (Gerenciador de Imagens): upload
 * manual de N arquivos de exame para um paciente, sem passar pelo
 * integrador/equipamento. Autorização (Gate EntityGate::ImportExternalExam)
 * é feita no controller — mesmo padrão de ExamDiagnosisController.
 *
 * Todo id relacional (patient_id, exam_id, doctor_id,
 * entity_integrator_equipment_id) é validado com escopo de
 * session('selected_entity_id') — nunca um `exists` cru — para não confiar
 * em id vindo do form sem escopo de entidade (multi-tenant).
 */
class ImportExternalExamRequest extends FormRequest
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
        $entityId = (string) session('selected_entity_id');

        return [
            'patient_id' => [
                'required',
                'uuid',
                Rule::exists('patients', 'id')->where(
                    fn ($query) => $query->where('entity_id', $entityId)->whereNull('deleted_at'),
                ),
            ],
            // Tipo de exame: catálogo da entity OU template global (entity_id
            // null) — mesmo critério de PatientExamRequest::exam_identifier /
            // PatientExamService::examFindByIdOrCode.
            'exam_id' => [
                'required',
                'uuid',
                Rule::exists('exam_types', 'id')->where(
                    fn ($query) => $query->where(fn ($w) => $w->where('entity_id', $entityId)->orWhereNull('entity_id'))
                        ->whereNull('deleted_at'),
                ),
            ],
            'exam_performed_at' => ['required', 'date', 'before_or_equal:today'],
            // doctor_id referencia a tabela `doctors` (PatientExam::doctor()),
            // não `users` — escopado via doctors.entity_user_id -> entity_users.entity_id.
            'doctor_id' => [
                'nullable',
                'uuid',
                Rule::exists('doctors', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                        ->whereIn('entity_user_id', function ($sub) use ($entityId) {
                            $sub->select('id')->from('entity_users')->where('entity_id', $entityId);
                        }),
                ),
            ],
            // Equipamento real (opcional) — só preenchido quando o usuário souber
            // qual equipamento cadastrado gerou o arquivo. Escopo via
            // integrator_id -> entity_integrators.entity_user_integrator_id ->
            // entity_user_integrators.entity_id (mesma cadeia percorrida por
            // EyeImagesController::availableEquipments() via integrator.user).
            // Note: entity_user_integrators é a tabela de "usuário de API do
            // integrador", distinta de entity_users (usuários do painel).
            'entity_integrator_equipment_id' => [
                'nullable',
                'uuid',
                Rule::exists('entity_integrator_equipments', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                        ->whereIn('integrator_id', function ($sub) use ($entityId) {
                            $sub->select('entity_integrators.id')
                                ->from('entity_integrators')
                                ->whereIn('entity_user_integrator_id', function ($sub2) use ($entityId) {
                                    $sub2->select('id')->from('entity_user_integrators')->where('entity_id', $entityId);
                                });
                        }),
                ),
            ],
            'external_origin' => ['nullable', 'string', 'max:255'],
            'files'           => ['required', 'array', 'min:1', 'max:10'],
            'files.*'         => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:20480'],
            // Mesmo shape/regra de UpdateExamDiagnosisRequest — mantém consistência
            // entre "Diagnóstico do exame" e "Importar exame externo".
            'diagnoses'        => ['nullable', 'array', 'max:20'],
            'diagnoses.*.code' => ['nullable', 'string', 'max:10'],
            // Escopado por entity_id — mesmo motivo/padrão de UpdateExamDiagnosisRequest.
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
