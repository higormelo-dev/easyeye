<?php

namespace App\Http\Requests;

use App\Models\{Doctor, Patient};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza valores antes da validação:
     *   - Eixo: remove sufixo º (ex: "180º" → 180)
     *   - Esférico/Cilíndrico: aceita formato com sinal (+1.25, -2.50)
     *   - doctor_id: se usuário logado é médico e nada foi enviado, auto-preenche
     *     com o id do próprio Doctor — admin precisa enviar explícito (rule required).
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
        $cids = $this->input('diagnosis_cids');

        if (is_string($cids)) {
            $decoded                   = json_decode($cids, true);
            $payload['diagnosis_cids'] = is_array($decoded) ? $decoded : [];
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        return [
            // Identificação — doctor obrigatório (auto-preenchido se user é médico).
            // Achado de segurança (auditoria panel.* IDOR, rodada 2 — ID via
            // request body): Doctor não tem entity_id direto (só via
            // entity_user_id -> entity_users.entity_id), então 'exists:doctors,id'
            // sozinho aceitava médico de OUTRA clínica. Mesmo padrão de closure
            // já usado em ScheduleRequest::rules() para doctor_id.
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
            // Vínculo com a agenda escopado por tenant E pelo paciente da rota:
            // um schedule_id de outra clínica — ou de OUTRO paciente da mesma
            // clínica — não pode ser gravado no prontuário (Finalizar/Dilatar/
            // Exame mexeria na agenda do outro paciente e o "Atender" dele
            // passaria a abrir este prontuário).
            'schedule_id' => ['nullable', 'uuid', Rule::exists('schedules', 'id')
                ->where('entity_id', (string) session('selected_entity_id'))
                ->where('patient_id', $this->routePatientId())
                ->whereNull('deleted_at')],
            // Fluxo do atendimento (Agenda ↔ Prontuário): o que acontece com o
            // paciente após salvar — save (mantém aberto) | finish (Atendido) |
            // dilate (Dilatando) | exam (Em exame). Não é persistido no registro.
            'flow_action' => ['nullable', 'string', 'in:save,finish,dilate,exam'],
            // Ação da barra inferior a abrir automaticamente após o 1º save
            // (create → edit?action=): emitir receita/atestado sem "salvar
            // primeiro" manual. Não é persistido no registro.
            'post_save_action' => ['nullable', 'string', 'in:medication,procedures,pterygium,cataract,test_eye,retinal_mapping,attendance_certificate,medical_certificate,exam_hub,documentations,upload'],
            // Exame físico — seleções
            // Achado de segurança (auditoria panel.* IDOR, rodada 2 — ID via
            // request body): estes catálogos clínicos têm entity_id NULLABLE
            // (registro global OU customizado por clínica) e EntityScope
            // global no model, mas o scope só protege leitura via Eloquent —
            // não a regra de validação 'exists:' crua. Sem escopar aqui, um
            // ID de OUTRA clínica passava na validação. Mesmo padrão já
            // usado para covenant_id/skin_id (PatientRequest) e
            // patient_id/covenant_id/visit_id (ScheduleRequest).
            'visual_acuity_type_id' => [
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
            'lens_away_ids'   => ['nullable', 'array', 'max:10'],
            'lens_away_ids.*' => [
                'uuid',
                Rule::exists('lenses', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'lens_near_ids'   => ['nullable', 'array', 'max:10'],
            'lens_near_ids.*' => [
                'uuid',
                Rule::exists('lenses', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            // Anamnese — CBO. Obrigatória, EXCETO no modo texto livre (a
            // evolução vai inteira em observation_general e a tela não mostra
            // o campo de queixa — ticket "simplificar texto livre").
            'main_complaint'          => ['required_without:observation_general', 'nullable', 'string', 'max:5000'],
            'hda'                     => ['nullable', 'string', 'max:10000'],
            'diabetic'                => ['nullable', 'boolean'],
            'diabetic_family'         => ['nullable', 'boolean'],
            'hypertensive'            => ['nullable', 'boolean'],
            'hypertensive_family'     => ['nullable', 'boolean'],
            'glaucomatous'            => ['nullable', 'boolean'],
            'glaucomatous_family'     => ['nullable', 'boolean'],
            'others_history'          => ['nullable', 'string', 'max:1000'],
            'ocular_surgical_history' => ['nullable', 'string', 'max:5000'],
            'medications_in_use'      => ['nullable', 'string', 'max:5000'],
            // Exame físico — texto
            'ocular_motility'           => ['nullable', 'string', 'max:2000'],
            'tonometer_right'           => ['nullable', 'numeric'],
            'tonometer_left'            => ['nullable', 'numeric'],
            'tonometer_time'            => ['nullable', 'string', 'max:10'],
            'pachymetry_right'          => ['nullable', 'integer', 'min:0', 'max:9999'],
            'pachymetry_left'           => ['nullable', 'integer', 'min:0', 'max:9999'],
            'gonioscopy_right'          => ['nullable', 'string', 'max:2000'],
            'gonioscopy_left'           => ['nullable', 'string', 'max:2000'],
            'dynamic_spherical_right'   => ['nullable', 'numeric'],
            'dynamic_spherical_left'    => ['nullable', 'numeric'],
            'dynamic_cylindrical_right' => ['nullable', 'numeric'],
            'dynamic_cylindrical_left'  => ['nullable', 'numeric'],
            'dynamic_axis_right'        => ['nullable', 'numeric', 'min:0', 'max:180'],
            'dynamic_axis_left'         => ['nullable', 'numeric', 'min:0', 'max:180'],
            'static_spherical_right'    => ['nullable', 'numeric'],
            'static_spherical_left'     => ['nullable', 'numeric'],
            'static_cylindrical_right'  => ['nullable', 'numeric'],
            'static_cylindrical_left'   => ['nullable', 'numeric'],
            'static_axis_right'         => ['nullable', 'numeric', 'min:0', 'max:180'],
            'static_axis_left'          => ['nullable', 'numeric', 'min:0', 'max:180'],
            'biomicroscopy_right'       => ['nullable', 'string', 'max:5000'],
            'biomicroscopy_left'        => ['nullable', 'string', 'max:5000'],
            'fundoscopy_right'          => ['nullable', 'string', 'max:5000'],
            'fundoscopy_left'           => ['nullable', 'string', 'max:5000'],
            'observation_general'       => ['nullable', 'string', 'max:5000'],
            'observation_of_lenses'     => ['nullable', 'string', 'max:5000'],
            // Cálculo de lentes de contato — só ENTRADAS; os resultados são
            // recalculados no servidor (ContactLensCalculator). Faixas
            // clínicas plausíveis: barram valor digitado errado (ex.: -90).
            'contact_lens_calculation'                    => ['nullable', 'array'],
            'contact_lens_calculation.vertex_distance_mm' => ['nullable', 'numeric', 'min:5', 'max:25'],
            'contact_lens_calculation.vertex_od'          => ['nullable', 'numeric', 'min:-40', 'max:40'],
            'contact_lens_calculation.vertex_oe'          => ['nullable', 'numeric', 'min:-40', 'max:40'],
            'contact_lens_calculation.se_od_sphere'       => ['nullable', 'numeric', 'min:-40', 'max:40'],
            'contact_lens_calculation.se_od_cylinder'     => ['nullable', 'numeric', 'min:-15', 'max:15'],
            'contact_lens_calculation.se_oe_sphere'       => ['nullable', 'numeric', 'min:-40', 'max:40'],
            'contact_lens_calculation.se_oe_cylinder'     => ['nullable', 'numeric', 'min:-15', 'max:15'],
            // Diagnóstico — CBO obrigatório (array de {code, description})
            'diagnosis_cids'               => ['nullable', 'array', 'max:20'],
            'diagnosis_cids.*.code'        => ['required_with:diagnosis_cids', 'string', 'max:10'],
            'diagnosis_cids.*.description' => ['required_with:diagnosis_cids', 'string', 'max:500'],
            // Conduta — CBO obrigatório
            'clinical_conduct' => ['nullable', 'string', 'max:10000'],
            'follow_up_days'   => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_id.required' => __('actions.medical_records.doctor_required_validation'),
            'doctor_id.exists'   => __('actions.medical_records.doctor_exists_validation'),
            'schedule_id.exists' => __('actions.medical_records.schedule_exists_validation'),
        ];
    }

    /** Paciente da rota patients/{patient}/medicalrecords: model já resolvido ou id cru. */
    private function routePatientId(): string
    {
        $patient = $this->route('patient');

        return (string) ($patient instanceof Patient ? $patient->getKey() : $patient);
    }
}
