<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Domains\Recall\Services\ProtocolGovernanceService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * O motivo de suspender, aposentar ou retirar uma versão da revisão — vai para o log de governança.
 * Suspender e aposentar tiram a regra de uso (mínimo de 20 caracteres); retirar da revisão é antes de
 * qualquer uso (10).
 */
class ProtocolGovernanceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $minimum = $this->routeIs('manager.recall-protocols.versions.withdraw')
            ? ProtocolGovernanceService::MIN_WITHDRAW_REASON_LENGTH
            : ProtocolGovernanceService::MIN_REASON_LENGTH;

        return ['reason' => ['required', 'string', "min:{$minimum}", 'max:1000']];
    }
}
