<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente a auth:patient, mas com resposta amigável para chamadas
 * Inertia/fetch quando não há sessão válida no guard "patient" (em vez do
 * redirect para /login de staff que auth:patient dispararia por padrão).
 *
 * Também funciona como kill-switch de suporte EM TEMPO REAL: uma conta
 * desativada (patient_accounts.active = false) é deslogada imediatamente na
 * próxima requisição, mesmo com sessão ainda válida — não espera o próximo
 * login (ver risco de segurança "Dashboard do paciente" no plano da Fase 1).
 */
class EnsurePatientAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('patient');

        if (! $guard->check()) {
            return $this->deny($request);
        }

        $account = $guard->user();

        if (! $account->active) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Sua sessão expirou. Faça login novamente para continuar.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->route('patient-portal.login');
    }
}
