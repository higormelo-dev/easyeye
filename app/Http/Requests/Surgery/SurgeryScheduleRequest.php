<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** Janela da cirurgia (agendar, reagendar e conferir conflitos antes de salvar). */
class SurgeryScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at'           => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes'    => ['nullable', 'integer', 'between:5,720'],
            'surgery_facility_id' => [$this->routeIs('*.schedule.check') ? 'nullable' : 'required', 'uuid'],
            'surgery_room_id'     => ['nullable', 'uuid'],
            'expected_revision'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{starts_at: string, duration_minutes?: int|null, surgery_facility_id?: string|null, surgery_room_id?: string|null} */
    public function windowData(): array
    {
        return $this->safe()->only(['starts_at', 'duration_minutes', 'surgery_facility_id', 'surgery_room_id']);
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
