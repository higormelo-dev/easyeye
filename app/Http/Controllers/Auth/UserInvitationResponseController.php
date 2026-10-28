<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RespondsToClinicInvitation;
use App\Http\Controllers\Controller;
use App\Models\{EntityUserInvitation, SystemProfile};
use App\Services\UserInvitationService;
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * O usuário responde ao convite de uma clínica — logado e pelo link assinado
 * do e-mail. Só o dono do login convidado vê/responde (404 aos demais e a
 * convites de e-mail que não tinha conta).
 */
class UserInvitationResponseController extends Controller
{
    use RespondsToClinicInvitation;

    public function __construct(
        private readonly UserInvitationService $service,
    ) {
    }

    public function show(Request $request, EntityUserInvitation $invitation): Response
    {
        $this->assertInvitee($request, $invitation);

        // O perfil oferecido fica visível antes de aceitar (pode ser admin).
        $role = SystemProfile::labelMap(SystemProfile::CONTEXT_CLIENT)[$invitation->rule] ?? $invitation->rule;

        return $this->renderInvitation(
            $invitation,
            'user-invitations.accept',
            'user-invitations.decline',
            trans('access_control.invitation.page'),
            __('access_control.invitation.page.role', ['role' => $role]),
        );
    }

    public function accept(Request $request, EntityUserInvitation $invitation): RedirectResponse|HttpResponse
    {
        $this->assertInvitee($request, $invitation);

        $clinic = (string) $invitation->entity?->name;

        return match ($this->service->accept($invitation, $request->user())) {
            UserInvitationService::ACCEPT_OK         => $this->backAfterResponse($request, 'success', __('access_control.invitation.result.accepted', ['clinic' => $clinic])),
            UserInvitationService::ACCEPT_MEMBER     => $this->backAfterResponse($request, 'success', __('access_control.invitation.result.already_member', ['clinic' => $clinic])),
            UserInvitationService::ACCEPT_PLAN_LIMIT => $this->backAfterResponse($request, 'error', __('access_control.invitation.result.plan_limit', ['clinic' => $clinic])),
            default                                  => $this->backAfterResponse($request, 'error', __('access_control.invitation.result.closed')),
        };
    }

    public function decline(Request $request, EntityUserInvitation $invitation): RedirectResponse|HttpResponse
    {
        $this->assertInvitee($request, $invitation);

        return $this->service->decline($invitation, $request->user())
            ? $this->backAfterResponse($request, 'success', __('access_control.invitation.result.declined'))
            : $this->backAfterResponse($request, 'error', __('access_control.invitation.result.closed'));
    }
}
