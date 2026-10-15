<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\SurgeryEye;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Colírios estruturados + orientações pós-operatórias (texto editável). */
class PostOpGuidanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'guidance_html'           => ['nullable', 'string', 'max:30000'],
            'eyedrops'                => ['nullable', 'array', 'max:12'],
            'eyedrops.*.name'         => ['required_with:eyedrops.*.posology', 'nullable', 'string', 'max:150'],
            'eyedrops.*.medicine_id'  => ['nullable', 'uuid'],
            'eyedrops.*.posology'     => ['required_with:eyedrops.*.name', 'nullable', 'string', 'max:255'],
            'eyedrops.*.duration'     => ['nullable', 'string', 'max:120'],
            'eyedrops.*.taper'        => ['nullable', 'string', 'max:255'],
            'eyedrops.*.eye'          => ['nullable', Rule::in(array_column(SurgeryEye::cases(), 'value'))],
            'allergy_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function guidanceData(): array
    {
        return $this->validated();
    }
}
