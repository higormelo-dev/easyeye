<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Domains\Recall\Services\RecallActionService;

class RecallDismissRequest extends RecallActionRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:' . RecallActionService::REASON_MIN, 'max:' . RecallActionService::NOTE_MAX],
        ];
    }
}
