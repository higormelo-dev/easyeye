<?php

declare(strict_types=1);

namespace App\Events\Surgery;

use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Um evento entrou no log de autorização do caso (pedido, resposta da
 * operadora, dispensa, vencimento...). Despachado DEPOIS do commit da
 * transação que o gravou — ouvintes (aviso ao paciente, indicadores) nunca
 * seguram a trava do caso.
 */
final class AuthorizationEventRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly SurgicalCase $case,
        public readonly SurgicalAuthorizationEvent $event,
    ) {
    }
}
