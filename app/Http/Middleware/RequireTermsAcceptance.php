<?php

declare(strict_types = 1);

namespace App\Http\Middleware;

use App\Services\TermsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuário aceitou os documentos legais obrigatórios (versão vigente).
 * LGPD Art. 8 — o consentimento deve ser obtido antes do início do tratamento de dados.
 *
 * Redireciona para a página de aceite caso haja pendências.
 * Rotas isentas: auth, logout, locale, termos, e session-ping.
 */
class RequireTermsAcceptance
{
    public function __construct(
        private readonly TermsService $termsService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return $next($request);
        }

        if ($this->termsService->hasAcceptedAll($request->user())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'É necessário aceitar os termos de uso e política de privacidade para continuar.',
            ], 403);
        }

        return redirect()->route('terms.accept');
    }
}
