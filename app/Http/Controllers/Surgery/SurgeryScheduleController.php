<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\SurgeryScheduleRequest;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\SurgeryScheduleService;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/**
 * Agendar, reagendar e liberar a vaga da cirurgia (SurgeryScheduleService).
 * Conflito (sala, cirurgião, paciente, escala, bloqueio, consulta) volta como
 * 409 com a lista em `context.conflicts`; o formulário mostra cada um.
 */
class SurgeryScheduleController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgeryScheduleService $service)
    {
    }

    public function store(SurgeryScheduleRequest $request, SurgicalCase $surgicalCase): RedirectResponse|JsonResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $this->service->schedule($surgicalCase, $request->windowData(), $request->expectedRevision());

        return $this->done($request, __('surgery_schedule.messages.scheduled'));
    }

    public function update(SurgeryScheduleRequest $request, SurgicalCase $surgicalCase): RedirectResponse|JsonResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $this->service->reschedule($surgicalCase, $request->windowData(), $request->expectedRevision());

        return $this->done($request, __('surgery_schedule.messages.rescheduled'));
    }

    public function destroy(Request $request, SurgicalCase $surgicalCase): RedirectResponse|JsonResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $request->validate([
            'reason'            => ['nullable', 'string', 'max:500'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->service->release(
            $surgicalCase,
            $request->filled('reason') ? (string) $request->input('reason') : null,
            $request->filled('expected_revision') ? (int) $request->input('expected_revision') : null,
        );

        return $this->done($request, __('surgery_schedule.messages.released'));
    }

    /** Resposta antecipada do formulário: conflitos da janela, sem gravar nem travar. */
    public function check(SurgeryScheduleRequest $request, SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $conflicts = $this->service->check($surgicalCase, $request->windowData());

        return response()->json([
            'ok'        => $conflicts === [],
            'conflicts' => array_map(fn (array $c) => [...$c, 'text' => $this->service->describeConflict($c)], $conflicts),
        ]);
    }

    /**
     * Sucesso: o formulário da tela envia por fetch (JSON) e precisa da
     * confirmação sem redirecionar; a mensagem também vai para a sessão, para a
     * recarga da página seguinte exibi-la. Pedido comum (Inertia) volta.
     */
    private function done(Request $request, string $message): RedirectResponse|JsonResponse
    {
        session()->flash('success', $message);

        return $request->expectsJson() ? response()->json(['message' => $message]) : back();
    }
}
