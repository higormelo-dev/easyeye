<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PatientPortal\InvitationController;
use App\Services\PatientAccountLinkService;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class PatientAuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly PatientAccountLinkService $links,
    ) {
    }

    public function create(): Response|RedirectResponse
    {
        if (Auth::guard('patient')->check()) {
            return redirect()->route('patient-portal.dashboard');
        }

        return Inertia::render('PatientPortal/Auth/Login', [
            'appName' => config('app.name', 'EasyEye'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * SEMPRE via Auth::guard('patient') explícito — nunca Auth::attempt()/
     * Auth::login() sem guard, que autenticaria por engano no guard "web" de
     * staff (risco de segurança da Fase 1: guard e tabela dedicados).
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard('patient');

        if (! $guard->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        // Kill-switch de suporte: nega o login mesmo com senha correta quando
        // a conta foi desativada (ver EnsurePatientAuthenticated para a
        // revogação em tempo real de sessões já abertas).
        if (! $guard->user()->active) {
            $guard->logout();

            throw ValidationException::withMessages([
                'email' => 'Este acesso foi desativado. Entre em contato com a clínica.',
            ]);
        }

        $request->session()->regenerate();

        // Veio de um convite de outra clínica (InvitationController::
        // loginToLink): o próprio login conclui o vínculo — paciente leigo não
        // passa por outra tela. Link revalidado (assinatura e prazo) e mesmo
        // e-mail exigidos no serviço; se não der (e-mail diferente, já
        // vinculado), volta ao convite, que explica o motivo.
        $invitation = $request->session()->pull(InvitationController::INTENDED_KEY);

        if (is_string($invitation) && $this->isPendingInvitation($invitation)) {
            $linked = $this->links->linkFromInvitationUrl($guard->user(), $invitation);

            return $linked !== null
                ? redirect()->route('patient-portal.dashboard')
                    ->with('status', __('patient_portal.invitation.linked', ['clinic' => $this->links->clinicLabel($linked)]))
                : redirect()->to($invitation);
        }

        return redirect()->route('patient-portal.dashboard');
    }

    /** Link do convite do portal, ainda dentro da validade (senão: painel). */
    private function isPendingInvitation(string $url): bool
    {
        if (! str_starts_with($url, route('patient-portal.invitation.accept') . '?')) {
            return false;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['expires']) && (int) $query['expires'] > now()->getTimestamp();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('patient')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('patient-portal.login');
    }
}
