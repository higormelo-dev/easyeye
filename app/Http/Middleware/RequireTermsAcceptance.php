<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TermsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuário da clínica aceitou a versão VIGENTE dos documentos legais obrigatórios (Termos de Uso e
 * Política de Privacidade) — inclusive depois que uma versão nova é publicada (reaceite, LGPD art. 9º; lacuna G21).
 * Pendente → tela de aceite (`terms.accept`), guardando a URL pedida para voltar depois.
 *
 * Deixa passar:
 *  - sem usuário (o `auth` do grupo trata);
 *  - impersonação ("usar como"): o operador do SaaS não pode aceitar em nome do usuário — o aceite fica para quando
 *    o próprio usuário entrar;
 *  - contexto do SaaS (entity da sessão não é clínica): os Termos de Uso são o contrato com a clínica;
 *  - nenhum documento obrigatório vigente (TermsService::currentRequired vazio: nada a aceitar).
 */
class RequireTermsAcceptance
{
    public function __construct(
        private readonly TermsService $termsService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user
            || session()->has('impersonating.entity_user_id')
            || ! session('selected_entity_is_client', true)
            || $this->termsService->hasAcceptedAll($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.terms_reaccept.required_json')], Response::HTTP_FORBIDDEN);
        }

        return redirect()->guest(route('terms.accept'));
    }
}
