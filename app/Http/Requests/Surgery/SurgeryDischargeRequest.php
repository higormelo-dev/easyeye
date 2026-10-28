<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** Decisão do cirurgião na alta: texto da alta + plano de seguimento. */
class SurgeryDischargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'discharge_text'    => ['required', 'string', 'min:10', 'max:5000'],
            'follow_up_plan'    => ['required', 'string', 'min:3', 'max:3000'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
