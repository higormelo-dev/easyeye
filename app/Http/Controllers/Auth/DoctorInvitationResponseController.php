<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RespondsToClinicInvitation;
use App\Http\Controllers\Controller;
use App\Models\DoctorInvitation;
use App\Services\DoctorInvitationService;
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * O médico responde ao convite de uma clínica — logado (middleware auth) e
 * pelo link assinado do e-mail. Só o PRÓPRIO convidado vê/responde (404 aos
 * demais). A tela mostra apenas o nome da clínica — nada dos dados que ela
 * digitou.
 */
class DoctorInvitationResponseController extends Controller
{
    use RespondsToClinicInvitation;

    public function __construct(
        private readonly DoctorInvitationService $service,
    ) {
    }

    public function show(Request $request, DoctorInvitation $invitation): Response
    {
        $this->assertInvitee($request, $invitation);

        return $this->renderInvitation($invitation, 'doctor-invitations.accept', 'doctor-invitations.decline', trans('doctors.invitation.page'));
    }

    public function accept(Request $request, DoctorInvitation $invitation): RedirectResponse|HttpResponse
    {
        $this->assertInvitee($request, $invitation);

        $clinic = (string) $invitation->entity?->name;

        return match ($this->service->accept($invitation, $request->user())) {
            DoctorInvitationService::ACCEPT_OK         => $this->backAfterResponse($request, 'success', __('doctors.invitation.result.accepted', ['clinic' => $clinic])),
            DoctorInvitationService::ACCEPT_MEMBER     => $this->backAfterResponse($request, 'success', __('doctors.invitation.result.already_member', ['clinic' => $clinic])),
            DoctorInvitationService::ACCEPT_PLAN_LIMIT => $this->backAfterResponse($request, 'error', __('doctors.invitation.result.plan_limit', ['clinic' => $clinic])),
            DoctorInvitationService::ACCEPT_CONFLICT   => $this->backAfterResponse($request, 'error', __('doctors.invitation.result.conflict', ['clinic' => $clinic])),
            default                                    => $this->backAfterResponse($request, 'error', __('doctors.invitation.result.closed')),
        };
    }

    public function decline(Request $request, DoctorInvitation $invitation): RedirectResponse|HttpResponse
    {
        $this->assertInvitee($request, $invitation);

        return $this->service->decline($invitation, $request->user())
            ? $this->backAfterResponse($request, 'success', __('doctors.invitation.result.declined'))
            : $this->backAfterResponse($request, 'error', __('doctors.invitation.result.closed'));
    }
}
