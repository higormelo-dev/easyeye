<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Services\Surgery\SurgeryAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Evento do log de autorização registrado pela secretária (resposta da operadora, dispensa, anotação). */
class SurgeryAuthorizationEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'                        => ['required', Rule::in(array_map(fn ($t) => $t->value, SurgeryAuthorizationService::RECORDABLE))],
            'occurred_at'                 => ['nullable', 'date'],
            'authorization_number'        => ['nullable', 'string', 'max:40'],
            'authorization_password'      => ['nullable', 'string', 'max:40'],
            'valid_until'                 => ['nullable', 'date'],
            'authorized_items'            => ['nullable', 'array', 'max:80'],
            'authorized_items.*.kind'     => ['required', Rule::in(['procedure', 'material'])],
            'authorized_items.*.id'       => ['required', 'uuid'],
            'authorized_items.*.quantity' => ['required', 'integer', 'between:0,99'],
            'denial_reason'               => ['nullable', 'string', 'max:2000'],
            'protocol_number'             => ['nullable', 'string', 'max:40'],
            'channel'                     => ['nullable', Rule::in(['phone', 'email', 'portal', 'tiss', 'in_person', 'other'])],
            'note'                        => ['nullable', 'string', 'max:2000'],
            'expected_revision'           => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
