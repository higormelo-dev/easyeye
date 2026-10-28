<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

class ActivateRecallProtocolRequest extends RecallSettingsRequest
{
    protected function decides(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_id' => ['required', 'uuid'],
            'tasks'      => ['nullable', 'array'],
            'tasks.*'    => ['array'],
            // O número é conferido contra a faixa validada no serviço (inteiro, mínimo, máximo).
            'tasks.*.interval' => ['nullable'],
            'tasks.*.enabled'  => ['nullable', 'boolean'],
            'auto_contact'     => ['nullable', 'boolean'],
            'acknowledged'     => ['required', 'accepted'],
        ];
    }
}
