<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Domains\Recall\Services\RecallActionService;

class RecallSnoozeRequest extends RecallActionRequest
{
    public function rules(): array
    {
        return [
            'until'  => ['required', 'date_format:Y-m-d', 'after:today', 'before_or_equal:' . now()->addDays(RecallActionService::MAX_SNOOZE_DAYS)->toDateString()],
            'reason' => ['nullable', 'string', 'max:' . RecallActionService::NOTE_MAX],
        ];
    }
}
