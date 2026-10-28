<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos da página PÚBLICA do Revisor Clínico (ADR 0002). O link carrega um token
 * pessoal; estes cabeçalhos garantem que ele não vaze por Referer, cache, buscador ou
 * moldura:
 *
 *  - Referrer-Policy: no-referrer — a URL (inclusive a do 1º salto, com o token) nunca
 *    sai como Referer para nenhum destino;
 *  - Cache-Control: no-store — nem o navegador, nem proxy guardam a página (que tem o
 *    rascunho do parecer);
 *  - X-Robots-Tag e X-Frame-Options — fora de índices e de iframes.
 */
class ClinicalReviewHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
