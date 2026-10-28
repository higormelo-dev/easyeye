<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\Surgery\ChecklistPhase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\SurgeryChecklistRequest;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\{SurgeryChecklistService, SurgeryParticipants};
use App\Support\CurrentDoctor;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/** Checklist de cirurgia segura: responder e fechar cada fase (Entrada, Pausa Cirúrgica, Saída). */
class SurgeryChecklistController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgeryChecklistService $service)
    {
    }

    public function save(SurgeryChecklistRequest $request, SurgicalCase $surgicalCase, string $phase): RedirectResponse|JsonResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $this->service->save($surgicalCase, ChecklistPhase::from($phase), $request->answers());

        return $this->done($request, __('surgery_schedule.messages.checklist_saved'));
    }

    public function close(SurgeryChecklistRequest $request, SurgicalCase $surgicalCase, string $phase): RedirectResponse|JsonResponse
    {
        // Preencher o checklist é de quem está na sala (ManageSurgery); FECHAR a fase é atestar a
        // segurança cirúrgica: ato do médico da equipe, que fica gravado como `closed_by_doctor_id`.
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);
        app(SurgeryParticipants::class)->assertMember($surgicalCase, CurrentDoctor::issuer());

        $this->service->close($surgicalCase, ChecklistPhase::from($phase), $request->answers());

        return $this->done($request, __('surgery_schedule.messages.checklist_closed'));
    }

    /** Sucesso: JSON para quem pediu JSON (fetch/testes); a tela Inertia volta com a mensagem na sessão. */
    private function done(Request $request, string $message): RedirectResponse|JsonResponse
    {
        session()->flash('success', $message);

        return $request->expectsJson() ? response()->json(['message' => $message]) : back();
    }
}
