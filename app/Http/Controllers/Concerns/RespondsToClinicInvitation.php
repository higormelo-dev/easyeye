<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\URL;
use Inertia\{Inertia, Response};
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Resposta a convite de clínica (médico ou usuário) pelo PRÓPRIO convidado:
 * link assinado do e-mail + login. Qualquer outro login — ou convite sem dono
 * (e-mail que não tinha conta) — recebe 404: não revela que o convite existe.
 */
trait RespondsToClinicInvitation
{
    protected function assertInvitee(Request $request, Model $invitation): void
    {
        abort_unless(
            $invitation->user_id !== null && (string) $request->user()?->id === (string) $invitation->user_id,
            404,
        );
    }

    /**
     * Tela única de convite: só o nome da clínica e os links assinados
     * (aceitar/recusar, com a validade do convite).
     *
     * @param array<string, string> $t      textos (lang …invitation.page)
     * @param string|null           $detail linha extra já traduzida (ex.: o perfil oferecido)
     */
    protected function renderInvitation(Model $invitation, string $acceptRoute, string $declineRoute, array $t, ?string $detail = null): Response
    {
        return Inertia::render('Auth/ClinicInvitation', [
            'appName'    => config('app.name', 'EasyEye'),
            'clinicName' => $invitation->entity?->name,
            'detail'     => $detail,
            'open'       => $invitation->isOpen(),
            'acceptUrl'  => URL::temporarySignedRoute($acceptRoute, $invitation->expires_at, ['invitation' => $invitation->id]),
            'declineUrl' => URL::temporarySignedRoute($declineRoute, $invitation->expires_at, ['invitation' => $invitation->id]),
            't'          => $t,
        ])->rootView('guest-app');
    }

    /**
     * Várias clínicas → escolher a clínica (a nova aparece na lista); uma só
     * → painel. Página de origem é guest-app: painel exige recarga completa
     * (Inertia::location), como no login.
     */
    protected function backAfterResponse(Request $request, string $type, string $message): RedirectResponse|HttpResponse
    {
        session()->flash($type, $message);

        $hasManyClinics = $request->user()->entityUsers()->where('active', true)->count() > 1;
        $url            = route($hasManyClinics ? 'selectentity.create' : 'panel.dashboard');

        return $request->header('X-Inertia') && ! $hasManyClinics
            ? Inertia::location($url)
            : redirect()->to($url);
    }
}
