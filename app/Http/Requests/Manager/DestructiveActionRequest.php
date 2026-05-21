<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida ações destrutivas / de alto impacto no Manager SaaS:
 * cancel subscription, block-access, destroy entity, revoke credential, etc.
 *
 * Por LGPD/CFM: toda ação destrutiva exige justificativa textual livre,
 * para que a auditoria responda "por que isso foi feito".
 *
 * Política:
 *  - reason   : obrigatório, mínimo 20 caracteres (evita "ok"/"teste")
 *  - máximo 1000 chars (cabe em audit_logs.reason TEXT sem inflar)
 *
 * Rotas devem injetar este request quando precisarem capturar reason.
 * Não é universal — listagem, leitura, alteração de campo único de baixo
 * impacto não exigem reason.
 */
class DestructiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
