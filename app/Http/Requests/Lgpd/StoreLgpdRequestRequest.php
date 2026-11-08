<?php

declare(strict_types=1);

namespace App\Http\Requests\Lgpd;

use App\Enums\LgpdRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pedido de titular recebido fora do sistema (e-mail, telefone, balcão) e
 * registrado pela clínica controladora (LGPD art. 18). Pede só o necessário
 * para responder e localizar os dados (art. 6º, III).
 */
class StoreLgpdRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requester_name'     => ['required', 'string', 'max:255'],
            'requester_email'    => ['required', 'email:rfc', 'max:255'],
            'requester_document' => ['nullable', 'digits:11'],
            'request_type'       => ['required', Rule::enum(LgpdRequestType::class)],
            'requested_at'       => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'patient_id'         => [
                'nullable',
                'uuid',
                Rule::exists('patients', 'id')->where('entity_id', (string) session('selected_entity_id')),
            ],
            'description' => ['required', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) __('lgpd_requests.attributes');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('requester_document')) {
            $this->merge(['requester_document' => preg_replace('/\D/', '', (string) $this->input('requester_document'))]);
        }
    }
}
