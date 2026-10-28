<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UserInvitationRequest;
use App\Models\EntityUserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;

/**
 * Admin da clínica convida usuário que já tem login no EasyEye (rota
 * entity.role:admin). A resposta é SEMPRE a mesma — exista conta ou não —
 * e o convite entra na lista de pendentes de qualquer forma.
 */
class UserInvitationsController extends Controller
{
    public function __construct(
        private readonly UserInvitationService $service,
    ) {
    }

    public function store(UserInvitationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->service->invite((string) session('selected_entity_id'), $data['email'], $data['rule'], (string) $request->user()->id);

        // A listagem de usuários exibe `message` (não `success`).
        return back()->with('message', __('access_control.invitation.sent'));
    }

    public function destroy(string $invitation): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        // Convite de outra clínica: 404 (não revela que existe).
        $record = EntityUserInvitation::query()->where('entity_id', $entityId)->findOrFail($invitation);

        $this->service->cancel($record, $entityId);

        return back()->with('message', __('access_control.invitation.cancelled'));
    }
}
