<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClinicalReview;

use App\Domains\ClinicalReview\Models\ClinicalReviewInvitation;
use App\Domains\ClinicalReview\Support\ReviewToken;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureClinicalReviewSession;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{DB, Hash};
use Inertia\{Inertia, Response};

/**
 * Código de acesso (PIN) opcional do convite: a 2ª barreira, entregue por OUTRO canal.
 * Limitado por tentativas no próprio convite (5 erros travam por 15 minutos), além do
 * limiter por IP. A mensagem de erro é a mesma para código errado e para travado.
 */
class ClinicalReviewAccessCodeController extends Controller
{
    public function form(Request $request): Response|RedirectResponse
    {
        $invitation = $this->invitation($request);

        if (! $invitation->requiresAccessCode() || $this->passed($request, $invitation)) {
            return $this->landing($invitation);
        }

        return Inertia::render('ClinicalReview/AccessCode', [
            'reviewerName' => $invitation->reviewer_name,
            't'            => [
                'layout' => trans('clinical_review.public.layout'),
                'code'   => trans('clinical_review.public.code'),
                'show'   => ['greeting' => trans('clinical_review.public.show.greeting')],
            ],
            'urls' => ['verify' => route('clinical-review.code.verify'), 'locale' => route('clinical-review.locale')],
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);

        $code = ReviewToken::normalizeAccessCode((string) $request->input('code'));

        $granted = DB::transaction(function () use ($request, $code): bool {
            $locked = ClinicalReviewInvitation::query()->whereKey($this->invitation($request)->id)->lockForUpdate()->firstOrFail();

            if (! $locked->requiresAccessCode()) {
                return true;
            }

            if ($locked->isAccessLocked()) {
                return false;
            }

            if (Hash::check($code, (string) $locked->access_code_hash)) {
                $locked->forceFill(['access_attempts' => 0, 'access_locked_until' => null])->save();

                return true;
            }

            $attempts = $locked->access_attempts + 1;

            $locked->forceFill([
                'access_attempts'     => $attempts >= ClinicalReviewInvitation::MAX_ACCESS_ATTEMPTS ? 0 : $attempts,
                'access_locked_until' => $attempts >= ClinicalReviewInvitation::MAX_ACCESS_ATTEMPTS ? now()->addMinutes(ClinicalReviewInvitation::ACCESS_LOCK_MINUTES) : null,
            ])->save();

            return false;
        });

        if (! $granted) {
            return back()->withErrors(['code' => __('clinical_review.public.code.invalid')]);
        }

        $invitation = $this->invitation($request);

        $request->session()->put(EnsureClinicalReviewSession::SESSION_KEY . '.pin_ok', $invitation->id);

        return $this->landing($invitation);
    }

    /** Depois do código: a revisão, ou a conclusão se o parecer já foi enviado. */
    private function landing(ClinicalReviewInvitation $invitation): RedirectResponse
    {
        return redirect()->route($invitation->isAnswered() ? 'clinical-review.done' : 'clinical-review.show');
    }

    private function invitation(Request $request): ClinicalReviewInvitation
    {
        $invitation = $request->attributes->get(EnsureClinicalReviewSession::ATTRIBUTE);

        abort_unless($invitation instanceof ClinicalReviewInvitation, 404);

        return $invitation;
    }

    private function passed(Request $request, ClinicalReviewInvitation $invitation): bool
    {
        return hash_equals((string) $invitation->id, (string) $request->session()->get(EnsureClinicalReviewSession::SESSION_KEY . '.pin_ok', ''));
    }
}
