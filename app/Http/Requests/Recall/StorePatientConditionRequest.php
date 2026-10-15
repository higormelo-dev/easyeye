<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

class StorePatientConditionRequest extends PatientConditionRequest
{
    public function rules(): array
    {
        return ['protocol_key' => ['required', 'string', 'max:60']] + $this->eyeRules();
    }
}
