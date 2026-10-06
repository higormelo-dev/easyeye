<?php

namespace App\Http\Requests;

use App\Models\{Doctor, MedicalRecord, Patient};
use App\Services\ContactLensCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza valores antes da validação (espelha StoreMedicalRecordRequest).
     */
    protected function prepareForValidation(): void
    {
        $axisFields = [
            'dynamic_axis_right', 'dynamic_axis_left',
            'static_axis_right', 'static_axis_left',
        ];

        $payload = [];

        foreach ($axisFields as $field) {
            if ($this->filled($field)) {
                $payload[$field] = preg_replace('/[^\d\-]/', '', (string) $this->input($field));
            }
        }

        if (! $this->filled('doctor_id')) {
            $entityId = session('selected_entity_id');
            $doctor   = Doctor::whereHas('entityUser', fn ($q) => $q
                ->where('entity_id', $entityId)
                ->where('user_id', auth()->id()))
                ->first();

            if ($doctor) {
                $payload['doctor_id'] = $doctor->id;
            }
        }

        // diagnosis_cids chega serializado como JSON (input hidden do
        // componente Alpine cid10Search). Decode para array antes da
        // validação rodar a regra `array`.
        if ($this->has('diagnosis_cids')) {
            $cids = $this->input('diagnosis_cids');

            if (is_string($cids)) {
                $decoded                   = json_decode($cids, true);
                $payload['diagnosis_cids'] = is_array($decoded) ? $decoded : [];
            }
        }

        // Vínculo com a agenda é definido na criação (Iniciar atendimento) e
        // não tem UI no edit: schedule_id vazio no payload significa "sem
        // alteração", nunca "desvincular" — senão o 1º update apagava o
        // vínculo (normalize() → null) e quebrava Finalizar/Dilatar/Exame.
        if ($this->has('schedule_id') && blank($this->input('schedule_id'))) {
            $this->request->remove('schedule_id');
            $this->json()->remove('schedule_id');
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        return [
            // Identificacao — doctor obrigatório (auto-preenchido se user é médico).
            // Achado de segurança (auditoria panel.* IDOR, rodada 2 — ID via
            // request body) — ver mesmo comentário em StoreMedicalRecordRequest.
            'doctor_id' => ['required', 'uuid', function ($attribute, $value, $fail) {
                $exists = DB::table('doctors')
                    ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                    ->where('doctors.id', $value)
                    ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                    ->whereNull('doctors.deleted_at')
                    ->exists();

                if (! $exists) {
                    $fail(__('actions.medical_records.doctor_exists_validation'));
                }
            }],
            // Vínculo com a agenda escopado por tenant E pelo paciente da rota
            // (ver StoreMedicalRecordRequest). O vínculo que o prontuário JÁ tem
            // continua aceito: o form de edit reenvia o schedule_id hidratado e
            // um vínculo legado não pode impedir o médico de salvar.
            'schedule_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('schedules', 'id')
                ->where('entity_id', (string) session('selected_entity_id'))
                ->where(function ($query): void {
                    $query->where('patient_id', $this->routePatientId());

                    if (filled($current = $this->currentScheduleId())) {
                        $query->orWhere('id', $current);
                    }
                })
                ->whereNull('deleted_at')],
            // Fluxo do atendimento (Agenda ↔ Prontuário): o que acontece com o
            // paciente após salvar — save (mantém aberto) | finish (Atendido) |
            // dilate (Dilatando) | exam (Em exame). Não é persistido no registro.
            'flow_action' => ['nullable', 'string', 'in:save,finish,dilate,exam'],
            // Ação da barra inferior a abrir automaticamente após o 1º save
            // (create → edit?action=): emitir receita/atestado sem "salvar
            // primeiro" manual. Não é persistido no registro.
            'post_save_action' => ['nullable', 'string', 'in:medication,procedures,pterygium,cataract,test_eye,retinal_mapping,attendance_certificate,medical_certificate,exam_hub,documentations,upload'],
            // Exame fisico — selecoes
            // Achado de segurança (auditoria panel.* IDOR, rodada 2 — ID via
            // request body) — ver mesmo comentário em StoreMedicalRecordRequest.
            'visual_acuity_type_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('visual_acuity_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'near_point_convergence_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('near_point_convergences', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'cover_test_type_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('cover_test_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'color_vision_type_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('color_vision_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'visual_acuity_without_correction_right_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('visual_acuity_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'visual_acuity_without_correction_left_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('visual_acuity_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'visual_acuity_with_correction_right_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('visual_acuity_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'visual_acuity_with_correction_left_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('visual_acuity_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'addition_type_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('addition_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'lens_away_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('lenses', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'lens_near_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('lenses', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            // Multi-características de lente (Multifocal + Antirreflexo...)
            'lens_away_ids'   => ['sometimes', 'nullable', 'array', 'max:10'],
            'lens_away_ids.*' => [
                'uuid',
                Rule::exists('lenses', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'lens_near_ids'   => ['sometimes', 'nullable', 'array', 'max:10'],
            'lens_near_ids.*' => [
                'uuid',
                Rule::exists('lenses', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            // Anamnese — CBO
            'main_complaint'          => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hda'                     => ['sometimes', 'nullable', 'string', 'max:10000'],
            'diabetic'                => ['sometimes', 'nullable', 'boolean'],
            'diabetic_family'         => ['sometimes', 'nullable', 'boolean'],
            'hypertensive'            => ['sometimes', 'nullable', 'boolean'],
            'hypertensive_family'     => ['sometimes', 'nullable', 'boolean'],
            'glaucomatous'            => ['sometimes', 'nullable', 'boolean'],
            'glaucomatous_family'     => ['sometimes', 'nullable', 'boolean'],
            'others_history'          => ['sometimes', 'nullable', 'string', 'max:1000'],
            'ocular_surgical_history' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'medications_in_use'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Exame fisico — texto
            'ocular_motility'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tonometer_right'           => ['sometimes', 'nullable', 'numeric'],
            'tonometer_left'            => ['sometimes', 'nullable', 'numeric'],
            'tonometer_time'            => ['sometimes', 'nullable', 'string', 'max:10'],
            'pachymetry_right'          => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'pachymetry_left'           => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'gonioscopy_right'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            'gonioscopy_left'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'dynamic_spherical_right'   => ['sometimes', 'nullable', 'numeric'],
            'dynamic_spherical_left'    => ['sometimes', 'nullable', 'numeric'],
            'dynamic_cylindrical_right' => ['sometimes', 'nullable', 'numeric'],
            'dynamic_cylindrical_left'  => ['sometimes', 'nullable', 'numeric'],
            'dynamic_axis_right'        => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:180'],
            'dynamic_axis_left'         => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:180'],
            'static_spherical_right'    => ['sometimes', 'nullable', 'numeric'],
            'static_spherical_left'     => ['sometimes', 'nullable', 'numeric'],
            'static_cylindrical_right'  => ['sometimes', 'nullable', 'numeric'],
            'static_cylindrical_left'   => ['sometimes', 'nullable', 'numeric'],
            'static_axis_right'         => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:180'],
            'static_axis_left'          => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:180'],
            'biomicroscopy_right'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'biomicroscopy_left'        => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fundoscopy_right'          => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fundoscopy_left'           => ['sometimes', 'nullable', 'string', 'max:5000'],
            'observation_general'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'observation_of_lenses'     => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Lente de contato (v2) — só ENTRADAS; os resultados são
            // recalculados no servidor (ContactLensCalculator, que também
            // define as faixas e a versão aceita).
            'contact_lens_calculation' => ['sometimes', 'nullable', 'array'],
            ...ContactLensCalculator::validationRules(),
            // Diagnóstico — CBO obrigatório (array de {code, description})
            'diagnosis_cids'               => ['sometimes', 'nullable', 'array', 'max:20'],
            'diagnosis_cids.*.code'        => ['required_with:diagnosis_cids', 'string', 'max:10'],
            'diagnosis_cids.*.description' => ['required_with:diagnosis_cids', 'string', 'max:500'],
            // Conduta — CBO obrigatorio
            'clinical_conduct' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'follow_up_days'   => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_id.required' => __('actions.medical_records.doctor_required_validation'),
            'doctor_id.exists'   => __('actions.medical_records.doctor_exists_validation'),
            'schedule_id.exists' => __('actions.medical_records.schedule_exists_validation'),
            // Tela aberta antes da v2 da calculadora (envia version 1).
            'contact_lens_calculation.version.in' => __('actions.medical_records.contact_lens_outdated'),
        ];
    }

    /** Paciente da rota patients/{patient}/medicalrecords/{medicalrecord}: model já resolvido ou id cru. */
    private function routePatientId(): string
    {
        $patient = $this->route('patient');

        return (string) ($patient instanceof Patient ? $patient->getKey() : $patient);
    }

    /** Agendamento que o prontuário da rota já tem (vínculo existente continua aceito). */
    private function currentScheduleId(): ?string
    {
        $record = $this->route('medicalrecord');

        return $record instanceof MedicalRecord && filled($record->schedule_id) ? (string) $record->schedule_id : null;
    }
}
