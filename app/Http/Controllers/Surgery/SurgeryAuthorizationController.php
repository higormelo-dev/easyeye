<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\{SurgeryAuthorizationEventRequest, SurgeryReasonRequest};
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\SurgeryAuthorizationService;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Gate;

/** Autorização do pedido: enviar, retirar e registrar a resposta da operadora. */
class SurgeryAuthorizationController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgeryAuthorizationService $service)
    {
    }

    public function request(Request $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $revision = $request->filled('expected_revision') ? (int) $request->input('expected_revision') : null;

        $this->service->request($surgicalCase, $revision);

        return back()->with('success', __('surgery.messages.authorization_requested'));
    }

    public function withdraw(SurgeryReasonRequest $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $this->service->withdraw($surgicalCase, (string) $request->input('reason'), $request->expectedRevision());

        return back()->with('success', __('surgery.messages.authorization_withdrawn'));
    }

    public function record(SurgeryAuthorizationEventRequest $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        // Dispensa em convênio real (sem passar pela operadora) é decisão de admin ou médico, não da recepção.
        if ($request->input('type') === 'waived' && $surgicalCase->loadMissing('covenant')->requiresInsurer()) {
            abort_unless(
                $this->surgeryAbilities()['perform'] || Gate::allows(EntityGate::ManageSettings->value, $this->surgeryEntity()),
                403,
            );
        }

        $this->service->record($surgicalCase, $request->validated(), $request->expectedRevision());

        return back()->with('success', __('surgery.messages.authorization_recorded'));
    }
}
