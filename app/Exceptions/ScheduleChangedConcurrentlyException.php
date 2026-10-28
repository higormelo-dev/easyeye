<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Schedule;
use Illuminate\Http\{JsonResponse, RedirectResponse};
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A identidade do agendamento (paciente, dia) continuou mudando por outra
 * requisição a cada tentativa de travá-lo para a transição "Atendido"
 * (ScheduleService::changeSituation). Na prática só aparece se alguém fica
 * remarcando o mesmo agendamento sem parar; quem chama refaz a ação. Usada
 * também como sinal interno das tentativas de ScheduleService::lockForTransition.
 */
class ScheduleChangedConcurrentlyException extends RuntimeException
{
    public function __construct(public readonly Schedule $schedule)
    {
        parent::__construct((string) __('schedules.changed_concurrently'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->with('error', $this->getMessage());
    }
}
