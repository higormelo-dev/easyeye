<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            $doctor   = \App\Models\Doctor::whereHas('entityUser', fn ($q) => $q
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

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        return [
            // Identificacao — doctor obrigatório (auto-preenchido se user é médico).
            'doctor_id'   => ['required', 'uuid', 'exists:doctors,id'],
            'schedule_id' => ['sometimes', 'nullable', 'uuid', 'exists:schedules,id'],
            // Exame fisico — selecoes
            'visual_acuity_type_id'                     => ['sometimes', 'nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'near_point_convergence_id'                 => ['sometimes', 'nullable', 'uuid', 'exists:near_point_convergences,id'],
            'cover_test_type_id'                        => ['sometimes', 'nullable', 'uuid', 'exists:cover_test_types,id'],
            'color_vision_type_id'                      => ['sometimes', 'nullable', 'uuid', 'exists:color_vision_types,id'],
            'visual_acuity_without_correction_right_id' => ['sometimes', 'nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'visual_acuity_without_correction_left_id'  => ['sometimes', 'nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'visual_acuity_with_correction_right_id'    => ['sometimes', 'nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'visual_acuity_with_correction_left_id'     => ['sometimes', 'nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'addition_type_id'                          => ['sometimes', 'nullable', 'uuid', 'exists:addition_types,id'],
            'lens_away_id'                              => ['sometimes', 'nullable', 'uuid', 'exists:lenses,id'],
            'lens_near_id'                              => ['sometimes', 'nullable', 'uuid', 'exists:lenses,id'],
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
            'doctor_id.required' => 'Selecione o médico responsável antes de salvar o prontuário.',
            'doctor_id.exists'   => 'Médico selecionado não pertence à entidade ativa.',
        ];
    }
}
