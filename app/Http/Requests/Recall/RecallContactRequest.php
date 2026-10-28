<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Domains\Recall\Enums\RecallContactOutcome;
use App\Domains\Recall\Services\RecallActionService;
use Illuminate\Validation\Rule;

class RecallContactRequest extends RecallActionRequest
{
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(RecallContactOutcome::values())],
            'note'    => ['nullable', 'string', 'max:' . RecallActionService::NOTE_MAX],
        ];
    }
}
