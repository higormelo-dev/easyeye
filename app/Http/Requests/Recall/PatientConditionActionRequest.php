<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

/** Confirmar, descartar e a leitura: sem corpo, só o Gate do médico. */
class PatientConditionActionRequest extends PatientConditionRequest
{
    public function rules(): array
    {
        return [];
    }
}
