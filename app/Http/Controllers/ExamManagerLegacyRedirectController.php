<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\{RedirectResponse, Request};

/**
 * Compatibilidade: o módulo Eye Images virou Gerenciador de exames. Favoritos, links de e-mail de rascunho
 * (`draft_mail_action`) e o `review_url` que integradores já gravaram apontam para /panel/eye-images[/...];
 * aqui eles ganham um 301 para o mesmo caminho em /panel/exam-manager, com a query string preservada.
 *
 * Sem open redirect: o destino começa SEMPRE no prefixo fixo do módulo novo e só aproveita do caminho
 * legado segmentos simples ([A-Za-z0-9_-]). Barra dupla, barra invertida, `%`, ponto (`..`), esquema
 * (`https:`) ou qualquer outro caractere caem no índice do módulo. A rota vive dentro do grupo `panel`
 * (auth, verified, entity.selected, assinatura...): nenhuma URL legada fica pública.
 */
class ExamManagerLegacyRedirectController extends Controller
{
    private const SEGMENTS = '#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/?$#';

    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $base   = route('panel.exam-manager.index', [], false);
        $path   = (string) $path;
        $target = preg_match(self::SEGMENTS, $path) === 1 ? $base . '/' . trim($path, '/') : $base;
        // Query bruta (não `getQueryString()`, que reordena as chaves): preserva ordem e encoding do link original.
        // Vai depois do `?`, então não influencia host nem caminho do destino.
        $query = (string) ($request->server->get('QUERY_STRING') ?: $request->getQueryString());

        return redirect()->to($query === '' ? $target : $target . '?' . $query, 301);
    }
}
