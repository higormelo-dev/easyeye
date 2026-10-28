<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuário autenticado possui um dos roles SaaS exigidos
 * (SaasRule) na entity SAAS selecionada na sessão — ou é owner dela.
 *
 * COMPLIANCE — por que não reusar `entity.role` (EnsureEntityRole) aqui:
 * aquele middleware resolve a entity PRIMEIRO pelo route model binding, e as
 * rotas do manager carregam `{entity}` apontando para a CLÍNICA gerenciada
 * (ex.: entities/{entity}/users). O role seria checado na entity errada —
 * o admin SaaS não tem vínculo na clínica e levaria 403; pior, um admin DA
 * CLÍNICA autenticado com a entity SaaS na sessão poderia confundir a
 * resolução. Este middleware resolve EXCLUSIVAMENTE pela sessão
 * (`selected_entity_id`) e exige entity não-cliente, fechando o gap do
 * `saas.admin` (EnsureSaasAdmin), que valida apenas o TIPO da entity e
 * nunca o papel do usuário nela.
 *
 * O owner da entity SaaS (is_owner = true no vínculo) passa sempre,
 * independente do rule — mesma semântica do Gate SaasOwnerFinancial: o dono
 * do SaaS não pode se trancar para fora do próprio painel.
 *
 * Uso (roles = valores de SaasRule, separados por vírgula):
 *
 *   ->middleware('saas.role:admin')
 *   ->middleware('saas.role:admin,financial')
 *   ->middleware('saas.role:admin,support')
 *
 * Pré-requisito: rodar DEPOIS de auth + saas.admin (que já rejeita entity
 * cliente e sessão sem entity). As checagens abaixo repetem esses guards de
 * forma defensiva para o middleware ser seguro mesmo usado isolado.
 */
class EnsureSaasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (empty($roles)) {
            // Nenhum role exigido → NEGA (diferente de entity.role, que libera
            // com warning): aqui protegemos o painel do dono do SaaS — uma
            // misconfiguration não pode abrir acesso administrativo.
            logger()->error('EnsureSaasRole: nenhum role foi definido na rota — acesso negado.', [
                'route' => $request->path(),
            ]);

            return $this->deny($request);
        }

        $user = $request->user();

        if (! $user) {
            return $this->deny($request, Response::HTTP_UNAUTHORIZED);
        }

        $entityId = session('selected_entity_id');

        if (! $entityId) {
            return $this->deny($request);
        }

        $entity = Entity::find($entityId);

        // Entity inexistente ou de clínica → nunca é contexto SaaS válido.
        if (! $entity || $entity->isClient()) {
            return $this->deny($request);
        }

        if ($user->isOwnerOfEntity($entity) || $user->hasAnyRoleInEntity($entity, $roles)) {
            return $next($request);
        }

        return $this->deny($request);
    }

    private function deny(Request $request, int $status = Response::HTTP_FORBIDDEN): Response
    {
        $message = $status === Response::HTTP_UNAUTHORIZED
            ? __('http-statuses.401')
            : __('http-statuses.403');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        // Inertia → redirect back com flash, mesmo padrão de EnsureEntityRole
        // (evita HTML de erro renderizado como modal overlay).
        if ($request->hasHeader('X-Inertia')) {
            return redirect()->back()->with('error', $message);
        }

        abort($status, $message);
    }
}
