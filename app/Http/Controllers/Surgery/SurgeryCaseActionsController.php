<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\SurgeryReasonRequest;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\SurgicalCaseService;
use Illuminate\Http\{RedirectResponse, Request};

/** Cancelar, suspender e retomar o caso. */
class SurgeryCaseActionsController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgicalCaseService $service)
    {
    }

    public function cancel(SurgeryReasonRequest $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseOwner($surgicalCase);

        $this->service->cancel($surgicalCase, (string) $request->input('reason'), $request->expectedRevision());

        return back()->with('success', __('surgery.messages.cancelled'));
    }

    public function suspend(SurgeryReasonRequest $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseOwner($surgicalCase);

        $this->service->suspend($surgicalCase, (string) $request->input('reason'), $request->expectedRevision());

        return back()->with('success', __('surgery.messages.suspended'));
    }

    public function resume(Request $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseOwner($surgicalCase);

        $revision = $request->filled('expected_revision') ? (int) $request->input('expected_revision') : null;

        $this->service->resume($surgicalCase, $revision);

        return back()->with('success', __('surgery.messages.resumed'));
    }
}
