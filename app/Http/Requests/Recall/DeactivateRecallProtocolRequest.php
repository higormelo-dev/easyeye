<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Domains\Recall\Services\ProtocolActivationService;

class DeactivateRecallProtocolRequest extends RecallSettingsRequest
{
    protected function decides(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:' . ProtocolActivationService::DEACTIVATION_REASON_MIN, 'max:500'],
        ];
    }
}
