<?php

declare(strict_types=1);

namespace App\Http\Requests\Lgpd;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Andamento de um pedido de titular: iniciar a análise, concluir (com a
 * resposta dada) ou recusar (com a justificativa). O texto fica registrado
 * como prova do atendimento (LGPD arts. 18, 19 e 37).
 */
class UpdateLgpdRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:start,complete,reject'],
            'text'   => ['required_if:action,complete,reject', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) __('lgpd_requests.attributes');
    }
}
