<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** "Registrar cirurgia realizada" + as justificativas de exceção que o cirurgião assume. */
class OperationPerformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'team_confirmed'                => ['required', 'boolean'],
            'performed_at'                  => ['nullable', 'date'],
            'complications'                 => ['nullable', 'string', 'max:5000'],
            'no_implant_reason'             => ['nullable', 'string', 'max:2000'],
            'authorization_override_reason' => ['nullable', 'string', 'max:2000'],
            'plan_deviation_reason'         => ['nullable', 'string', 'max:2000'],
            'time_out_override_reason'      => ['nullable', 'string', 'max:2000'],
            'consent_override_reason'       => ['nullable', 'string', 'max:2000'],
            'expected_revision'             => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
