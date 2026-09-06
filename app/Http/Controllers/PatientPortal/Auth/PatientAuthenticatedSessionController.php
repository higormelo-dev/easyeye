<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class PatientAuthenticatedSessionController extends Controller
{
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

        return redirect()->route('patient-portal.dashboard');
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
