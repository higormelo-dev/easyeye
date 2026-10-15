<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O aceite da EasyEye sobre um parecer: conferiu (ou não) o CRM/UF e o RQE no portal do CFM. A forma é
 * validada aqui; as regras ("aceitar exige as duas conferências", "recusar exige o motivo") são do
 * ReviewAcceptanceService, que as aplica sob trava.
 */
class RecordAcceptanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision'    => ['required', Rule::enum(AcceptanceDecision::class)],
            'crm_checked' => ['required', 'boolean'],
            'rqe_checked' => ['required', 'boolean'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
