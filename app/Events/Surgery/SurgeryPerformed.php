<?php

declare(strict_types=1);

namespace App\Events\Surgery;

use App\Models\Surgery\SurgicalCase;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cirurgia foi registrada como REALIZADA. Despachado DEPOIS do commit (e
 * fora de qualquer trava do caso): o ouvinte do pós-operatório cria os
 * retornos D1/D7/D30 na Agenda, que tem a própria ordem de locks
 * (cota → paciente/dia → linha) e não pode rodar dentro da trava do caso.
 */
final class SurgeryPerformed
{
    use Dispatchable;

    public function __construct(public readonly string $caseId)
    {
    }

    /**
     * Só o id viaja (fila/serialização): o model inteiro levaria dados do paciente e
     * credenciais da operadora para o payload do job. Quem escuta recarrega aqui.
     */
    public function case(): SurgicalCase
    {
        return SurgicalCase::query()->withoutGlobalScopes()->findOrFail($this->caseId);
    }
}
