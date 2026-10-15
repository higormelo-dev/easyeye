<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\{PostOpAdherence, PostOpFinding, PostOpMilestone, SurgeryEye};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Avaliação pós-operatória (rascunho). A regra clínica fina fica em PostOpEvaluationService. */
class PostOpEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'milestone'           => ['required', Rule::in(PostOpMilestone::evaluableValues())],
            'eye'                 => ['required', Rule::in([SurgeryEye::Right->value, SurgeryEye::Left->value])],
            'evaluated_at'        => ['nullable', 'date'],
            'va_uncorrected_id'   => ['nullable', 'uuid'],
            'va_corrected_id'     => ['nullable', 'uuid'],
            'iop_mmhg'            => ['nullable', 'numeric', 'between:0,80'],
            'findings'            => ['nullable', 'array', 'max:12'],
            'findings.*'          => ['string', Rule::in(array_column(PostOpFinding::cases(), 'value'))],
            'findings_notes'      => ['nullable', 'string', 'max:5000'],
            'refraction_sphere'   => ['nullable', 'numeric', 'between:-30,30'],
            'refraction_cylinder' => ['nullable', 'numeric', 'between:-15,15'],
            'refraction_axis'     => ['nullable', 'integer', 'between:0,180'],
            'complications'       => ['nullable', 'string', 'max:5000'],
            'adherence'           => ['nullable', Rule::in(array_column(PostOpAdherence::cases(), 'value'))],
            'plan'                => ['nullable', 'string', 'max:5000'],
            'schedule_id'         => ['nullable', 'uuid'],
            'expected_revision'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function evaluationData(): array
    {
        return collect($this->validated())->except('expected_revision')->all();
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
