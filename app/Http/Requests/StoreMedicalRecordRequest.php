<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Identificação
            'doctor_id'                                  => ['nullable', 'uuid', 'exists:doctors,id'],
            // Exame físico — seleções
            'visual_acuity_type_id'                      => ['nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'near_point_convergence_id'                  => ['nullable', 'uuid', 'exists:near_point_convergences,id'],
            'cover_test_type_id'                         => ['nullable', 'uuid', 'exists:cover_test_types,id'],
            'color_vision_type_id'                       => ['nullable', 'uuid', 'exists:color_vision_types,id'],
            'visual_acuity_without_correction_right_id'  => ['nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'visual_acuity_without_correction_left_id'   => ['nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'visual_acuity_with_correction_right_id'     => ['nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'visual_acuity_with_correction_left_id'      => ['nullable', 'uuid', 'exists:visual_acuity_types,id'],
            'addition_type_id'                           => ['nullable', 'uuid', 'exists:addition_types,id'],
            'lens_away_id'                               => ['nullable', 'uuid', 'exists:lenses,id'],
            'lens_near_id'                               => ['nullable', 'uuid', 'exists:lenses,id'],
            // Anamnese — CBO
            'main_complaint'                             => ['nullable', 'string', 'max:5000'],
            'hda'                                        => ['nullable', 'string', 'max:10000'],
            'diabetic'                                   => ['nullable', 'boolean'],
            'diabetic_family'                            => ['nullable', 'boolean'],
            'hypertensive'                               => ['nullable', 'boolean'],
            'hypertensive_family'                        => ['nullable', 'boolean'],
            'glaucomatous'                               => ['nullable', 'boolean'],
            'glaucomatous_family'                        => ['nullable', 'boolean'],
            'ocular_surgical_history'                    => ['nullable', 'string', 'max:5000'],
            'medications_in_use'                         => ['nullable', 'string', 'max:5000'],
            // Exame físico — texto
            'ocular_motility'                            => ['nullable', 'string', 'max:2000'],
            'tonometer_right'                            => ['nullable', 'numeric'],
            'tonometer_left'                             => ['nullable', 'numeric'],
            'tonometer_time'                             => ['nullable', 'string', 'max:10'],
            'pachymetry_right'                           => ['nullable', 'integer', 'min:0', 'max:9999'],
            'pachymetry_left'                            => ['nullable', 'integer', 'min:0', 'max:9999'],
            'gonioscopy_right'                           => ['nullable', 'string', 'max:2000'],
            'gonioscopy_left'                            => ['nullable', 'string', 'max:2000'],
            'dynamic_spherical_right'                    => ['nullable', 'numeric'],
            'dynamic_spherical_left'                     => ['nullable', 'numeric'],
            'dynamic_cylindrical_right'                  => ['nullable', 'numeric'],
            'dynamic_cylindrical_left'                   => ['nullable', 'numeric'],
            'dynamic_axis_right'                         => ['nullable', 'numeric', 'min:0', 'max:180'],
            'dynamic_axis_left'                          => ['nullable', 'numeric', 'min:0', 'max:180'],
            'static_spherical_right'                     => ['nullable', 'numeric'],
            'static_spherical_left'                      => ['nullable', 'numeric'],
            'static_cylindrical_right'                   => ['nullable', 'numeric'],
            'static_cylindrical_left'                    => ['nullable', 'numeric'],
            'static_axis_right'                          => ['nullable', 'numeric', 'min:0', 'max:180'],
            'static_axis_left'                           => ['nullable', 'numeric', 'min:0', 'max:180'],
            'biomicroscopy_right'                        => ['nullable', 'string', 'max:5000'],
            'biomicroscopy_left'                         => ['nullable', 'string', 'max:5000'],
            'fundoscopy_right'                           => ['nullable', 'string', 'max:5000'],
            'fundoscopy_left'                            => ['nullable', 'string', 'max:5000'],
            'observation_general'                        => ['nullable', 'string', 'max:5000'],
            'observation_of_lenses'                      => ['nullable', 'string', 'max:5000'],
            // Diagnóstico — CBO obrigatório
            'diagnosis_cid10'                            => ['nullable', 'string', 'max:10'],
            'diagnosis_description'                      => ['nullable', 'string', 'max:5000'],
            // Conduta — CBO obrigatório
            'clinical_conduct'                           => ['nullable', 'string', 'max:10000'],
            'follow_up_days'                             => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
