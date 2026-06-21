<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vincula a entity ativa da sessão (selected_entity_id) ao TenantContext, para
 * que o EntityScope global filtre as leituras no painel-cliente.
 *
 * APLICAR APENAS no grupo de rotas painel-cliente (panel.*). NÃO aplicar no
 * manager SaaS (panel/manager) nem em api/webhook/portal — esses precisam de
 * acesso cross-entidade e devem manter o scope inerte.
 */
class BindTenantContext
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $entityId = $request->session()->get('selected_entity_id');

        if ($entityId) {
            $this->tenant->set((string) $entityId);
        }

        return $next($request);
    }

    /**
     * Desvincula o tenant ao fim da requisição. Em produção o container é fresh
     * por request (inócuo); em testes evita que o vínculo "vaze" para asserções
     * diretas a models feitas após a chamada HTTP.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenant->clear();
    }
}
