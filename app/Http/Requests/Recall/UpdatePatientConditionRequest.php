<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

class UpdatePatientConditionRequest extends PatientConditionRequest
{
    public function rules(): array
    {
        return $this->eyeRules();
    }
}
