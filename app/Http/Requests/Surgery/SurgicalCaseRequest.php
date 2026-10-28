<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\{AnesthesiaType, SurgeryEye, SurgeryRegime, SurgeryTeamRole, SurgeryUrgency};
use App\Models\Surgery\{SurgicalCaseExam, SurgicalCaseProcedure};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pedido cirúrgico (criar/editar). Autorização por papel fica no controller
 * (Gate ManageSurgery; os campos de ato médico exigem PerformSurgery).
 */
class SurgicalCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $store = $this->isMethod('POST');
        $req   = $store ? 'required' : 'sometimes';

        return [
            'patient_id'                 => [$store ? 'required' : 'prohibited', 'uuid'],
            'surgeon_doctor_id'          => [$req, 'uuid'],
            'title'                      => [$req, 'string', 'max:200'],
            'eye'                        => [$req, Rule::enum(SurgeryEye::class)],
            'surgery_type_id'            => ['nullable', 'uuid'],
            'covenant_id'                => ['nullable', 'uuid'],
            'covenant_card_number'       => ['nullable', 'string', 'max:40'],
            'technique'                  => ['nullable', 'string', 'max:5000'],
            'anesthesia'                 => ['nullable', Rule::enum(AnesthesiaType::class)],
            'regime'                     => ['sometimes', Rule::enum(SurgeryRegime::class)],
            'urgency'                    => ['sometimes', Rule::enum(SurgeryUrgency::class)],
            'estimated_duration_minutes' => ['nullable', 'integer', 'between:5,720'],
            'surgery_facility_id'        => ['nullable', 'uuid'],
            'cid_codes'                  => ['sometimes', 'array', 'max:4'],
            'cid_codes.*.code'           => ['required', 'string', 'max:10'],
            'cid_codes.*.description'    => ['nullable', 'string', 'max:255'],
            'clinical_indication'        => ['nullable', 'string', 'max:5000'],
            'days_requested'             => ['sometimes', 'integer', 'between:1,60'],
            'needs_opme'                 => ['sometimes', 'boolean'],
            'notes'                      => ['nullable', 'string', 'max:5000'],
            'expected_revision'          => ['nullable', 'integer', 'min:1'],

            'procedures'                => ['sometimes', 'array', 'max:20'],
            'procedures.*.procedure_id' => ['nullable', 'uuid'],
            'procedures.*.tuss_code'    => ['nullable', 'string', 'max:10'],
            'procedures.*.description'  => ['required', 'string', 'max:255'],
            'procedures.*.quantity'     => ['nullable', 'integer', 'between:1,99'],
            'procedures.*.access_route' => ['nullable', Rule::in(SurgicalCaseProcedure::ACCESS_ROUTES)],

            'materials'                           => ['sometimes', 'array', 'max:40'],
            'materials.*.entity_product_id'       => ['nullable', 'uuid'],
            'materials.*.description'             => ['required', 'string', 'max:255'],
            'materials.*.quantity'                => ['nullable', 'integer', 'between:1,99'],
            'materials.*.manufacturer'            => ['nullable', 'string', 'max:120'],
            'materials.*.anvisa_registration'     => ['nullable', 'string', 'max:15'],
            'materials.*.manufacturer_ref'        => ['nullable', 'string', 'max:60'],
            'materials.*.operating_authorization' => ['nullable', 'string', 'max:40'],
            'materials.*.manufacturer_option'     => ['nullable', 'string', 'max:2'],
            'materials.*.unit_value'              => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'materials.*.technical_justification' => ['nullable', 'string', 'max:3000'],

            'iol_plans'                        => ['sometimes', 'array', 'max:2'],
            'iol_plans.*.eye'                  => ['required', 'distinct', Rule::in([SurgeryEye::Right->value, SurgeryEye::Left->value])],
            'iol_plans.*.entity_product_id'    => ['nullable', 'uuid'],
            'iol_plans.*.model_label'          => ['required', 'string', 'max:150'],
            'iol_plans.*.power'                => ['required', 'numeric', 'between:-10,60'],
            'iol_plans.*.cylinder'             => ['nullable', 'numeric', 'between:0,10'],
            'iol_plans.*.axis'                 => ['nullable', 'integer', 'between:0,180'],
            'iol_plans.*.formula'              => ['nullable', 'string', 'max:30'],
            'iol_plans.*.a_constant'           => ['nullable', 'numeric', 'between:100,125'],
            'iol_plans.*.target_refraction'    => ['nullable', 'numeric', 'between:-20,20'],
            'iol_plans.*.predicted_refraction' => ['nullable', 'numeric', 'between:-20,20'],
            'iol_plans.*.notes'                => ['nullable', 'string', 'max:2000'],

            'exams'                   => ['sometimes', 'array', 'max:30'],
            'exams.*.patient_exam_id' => ['required', 'uuid'],
            'exams.*.role'            => ['nullable', Rule::in(SurgicalCaseExam::ROLES)],
            'exams.*.notes'           => ['nullable', 'string', 'max:255'],

            'team'             => ['sometimes', 'array', 'max:15'],
            'team.*.role'      => ['required', Rule::enum(SurgeryTeamRole::class)],
            'team.*.doctor_id' => ['nullable', 'uuid'],
            'team.*.name'      => ['nullable', 'required_without:team.*.doctor_id', 'string', 'max:150'],
            'team.*.registry'  => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['iol_plans.*.eye.distinct' => (string) __('surgery.errors.iol_plan_duplicate_eye')];
    }

    /** Campos que são ATO MÉDICO: quem só tem ManageSurgery não os grava. */
    public const CLINICAL_KEYS = ['technique', 'anesthesia', 'iol_plans'];

    /** @return array<string, mixed> */
    public function caseData(bool $canPerform): array
    {
        $data = collect($this->validated())->except(['expected_revision'])->all();

        return $canPerform ? $data : array_diff_key($data, array_flip(self::CLINICAL_KEYS));
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
