<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege a documentação Swagger da API de integradores.
 *
 * Regras:
 *  1. Sem DOCS_API_PASSWORD configurada → 404 (a doc nunca fica aberta por
 *     omissão; 404 em vez de 403 para não revelar a existência da rota).
 *  2. Sessão sem autorização → redireciona para o formulário de senha.
 *  3. Toda resposta sai com X-Robots-Tag: noindex (não indexar a doc).
 */
class EnsureApiDocsAccess
{
    public const SESSION_KEY = 'docs_api_authorized';

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(blank(config('docs.api.password')), 404);

        if (! $request->session()->get(self::SESSION_KEY, false)) {
            return redirect()->guest(route('docs.api.auth'));
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
