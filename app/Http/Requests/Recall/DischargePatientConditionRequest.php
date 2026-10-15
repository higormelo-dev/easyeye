<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Domains\Recall\Services\PatientConditionService;

class DischargePatientConditionRequest extends PatientConditionRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:' . PatientConditionService::REASON_MIN, 'max:' . PatientConditionService::REASON_MAX]];
    }
}
