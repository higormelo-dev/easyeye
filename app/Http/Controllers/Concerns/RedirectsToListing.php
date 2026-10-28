<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * Depois de criar/editar/ativar/excluir a partir de uma listagem, volta para
 * ela mantendo busca, filtros, ordenação e página — antes o redirect ia para
 * a rota index "limpa" e a tela mostrava a lista sem filtro enquanto os
 * campos ainda exibiam os valores digitados.
 *
 * Segurança: a URL é reconstruída a partir da NOSSA rota (nunca devolve o
 * Referer cru — evita open redirect) e só com os parâmetros permitidos.
 */
trait RedirectsToListing
{
    /**
     * @param string       $indexRoute nome da rota da listagem (ex.: 'panel.doctors.index')
     * @param list<string> $params     parâmetros de query preservados (busca, filtros, sort, page…)
     */
    protected function redirectToListing(string $indexRoute, array $params): RedirectResponse
    {
        $index    = route($indexRoute);
        $previous = url()->previous();

        if (parse_url($previous, PHP_URL_PATH) !== parse_url($index, PHP_URL_PATH)) {
            return redirect()->to($index);
        }

        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        $query = array_filter(
            Arr::only($query, $params),
            fn ($value) => is_string($value) && $value !== '',
        );

        return redirect()->to($query ? $index . '?' . http_build_query($query) : $index);
    }
}
