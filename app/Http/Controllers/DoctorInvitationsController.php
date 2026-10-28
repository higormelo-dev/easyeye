<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Http\Requests\DoctorInvitationRequest;
use App\Models\{DoctorInvitation, Entity};
use App\Services\DoctorInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Clínica convida médico que já tem login no EasyEye (ver
 * DoctorInvitationService). Mesmo nível de acesso do cadastro de médico
 * (ManageSettings), checado ANTES da validação (DoctorInvitationRequest::
 * authorize) — quem não pode cadastrar médico não usa isto para descobrir
 * quem tem login.
 */
class DoctorInvitationsController extends Controller
{
    public function __construct(
        private readonly DoctorInvitationService $service,
    ) {
    }

    public function store(DoctorInvitationRequest $request): RedirectResponse
    {
        $this->service->invite(
            (string) session('selected_entity_id'),
            $request->invitedUser,
            $request->invitationPayload(),
            (string) $request->user()->id,
        );

        // Mesma mensagem sempre: nada sobre o médico (nome/e-mail) volta.
        return back()->with('success', __('doctors.invitation.sent'));
    }

    public function destroy(string $invitation): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail($entityId));

        // Convite de outra clínica: 404 (não revela que existe).
        $record = DoctorInvitation::query()->where('entity_id', $entityId)->findOrFail($invitation);

        $this->service->cancel($record, $entityId);

        return back()->with('success', __('doctors.invitation.cancelled'));
    }
}
