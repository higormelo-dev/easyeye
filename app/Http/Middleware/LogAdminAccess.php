<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Log};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Trilha de auditoria do painel Manager SaaS.
 *
 * Versão hardened (LGPD/CFM):
 *  1) Loga TODAS as respostas (2xx, 4xx, 5xx) — falhas de autorização são
 *     sinal de detecção, esconde-las anula o audit.
 *  2) Extrai o ID real do recurso da rota (route parameter) em vez de gerar
 *     UUID aleatório. Permite responder "quem acessou a entity X".
 *  3) Separa target_entity_id da entity_id da sessão — o admin SaaS tem
 *     entity_id="saas" na sessão mas opera sobre clínicas (target_entity_id).
 *  4) Grava status_code e route_name para queries de auditoria.
 *  5) NÃO grava sentinelas inúteis (UUID aleatório como auditable_id),
 *     porque isso polui a tabela e torna a trilha inquerível.
 */
class LogAdminAccess
{
    /**
     * Rotas de polling/widgets que geram ruído sem valor de auditoria.
     * Cards de KPI são GET puros que renderizam contadores agregados —
     * não acessam dados sensíveis individualmente.
     */
    private const SKIP_ROUTES = [
        'manager.plans.cards',
        'manager.entities.cards',
        'manager.subscriptions.cards',
        'manager.report-settings.cards',
    ];

    /**
     * Mapeia o nome do parâmetro de rota → tipo do recurso auditável.
     * Lê do primeiro match — a rota pode ter múltiplos parâmetros
     * (ex.: entities.user-integrators tem entity + userIntegrator),
     * mas auditamos o RECURSO ALVO da ação (o último na hierarquia).
     */
    private const RESOURCE_MAP = [
        'equipment'       => 'entity_integrator_equipment',
        'integrator'      => 'entity_integrator',
        'userIntegrator'  => 'entity_user_integrator',
        'user_integrator' => 'entity_user_integrator',
        'entityUser'      => 'entity_user',
        'entity_user'     => 'entity_user',
        'subscription'    => 'subscription',
        'plan'            => 'plan',
        'partner'         => 'partner',
        'commission'      => 'partner_commission',
        'lead'            => 'partner_lead',
        'gateway'         => 'gateway',
        'credential'      => 'gateway_credential',
        'report_setting'  => 'report_setting',
        'entity'          => 'entity',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response  = $next($request);
        $routeName = $request->route()?->getName();

        if ($routeName === null || \in_array($routeName, self::SKIP_ROUTES, true)) {
            return $response;
        }

        $this->log($request, $response, $routeName);

        return $response;
    }

    private function log(Request $request, Response $response, string $routeName): void
    {
        try {
            [$auditableType, $auditableId, $targetEntityId] = $this->extractTargetResource($request);

            DB::table('audit_logs')->insert([
                'id'               => Str::orderedUuid()->toString(),
                'entity_id'        => session('selected_entity_id'),
                'target_entity_id' => $targetEntityId,
                'user_id'          => Auth::id(),
                'target_user_id'   => $this->extractTargetUserId($request),
                'auditable_type'   => $auditableType,
                'auditable_id'     => $auditableId,
                'event'            => 'access',
                'status_code'      => $response->getStatusCode(),
                'route_name'       => $routeName,
                'old_values'       => null,
                'new_values'       => json_encode([
                    'url'    => $request->fullUrl(),
                    'method' => $request->method(),
                ], JSON_THROW_ON_ERROR),
                'reason'     => null,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Audit log não pode quebrar o request. Falha silenciosa
            // → vai para o canal de logs estruturados.
            Log::warning('LogAdminAccess: falha ao registrar acesso admin.', [
                'route' => $routeName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Inspeciona os parâmetros da rota e devolve o recurso alvo
     * (o último nível da hierarquia — o que a ação realmente afeta).
     *
     * @return array{0: string, 1: string, 2: ?string} [auditable_type, auditable_id, target_entity_id]
     */
    private function extractTargetResource(Request $request): array
    {
        $route      = $request->route();
        $parameters = $route?->parameters() ?? [];

        // entity_id alvo: parâmetro {entity} se presente
        $targetEntityId = null;

        if (isset($parameters['entity'])) {
            $entity         = $parameters['entity'];
            $targetEntityId = \is_object($entity) ? (string) $entity->getKey() : (string) $entity;
        }

        // Caminha a hierarquia de parâmetros em ordem inversa — o último
        // parâmetro de recurso é o alvo da ação.
        foreach (array_reverse($parameters, true) as $name => $value) {
            if (! isset(self::RESOURCE_MAP[$name])) {
                continue;
            }

            $id = \is_object($value) ? (string) $value->getKey() : (string) $value;

            return [self::RESOURCE_MAP[$name], $id, $targetEntityId];
        }

        // Rotas sem recurso identificável (ex.: GET /dashboard, GET /plans/cards)
        // — registramos como "manager_panel" mas SEM UUID aleatório.
        // auditable_id usa o UUID da entity SaaS atual da sessão, que existe.
        return ['manager_panel', (string) (session('selected_entity_id') ?? Str::orderedUuid()), $targetEntityId];
    }

    /**
     * Extrai target_user_id de rotas que afetam um usuário
     * (impersonate, ban, role change). Permite responder
     * "quais ações um admin executou sobre o usuário X".
     */
    private function extractTargetUserId(Request $request): ?string
    {
        $parameters = $request->route()?->parameters() ?? [];

        foreach (['entityUser', 'entity_user'] as $key) {
            if (! isset($parameters[$key])) {
                continue;
            }
            $value = $parameters[$key];

            // entityUser é o PIVOT — pegamos o user_id real
            if (\is_object($value) && property_exists($value, 'user_id')) {
                return (string) $value->user_id;
            }

            if (\is_object($value) && method_exists($value, 'getAttribute')) {
                $userId = $value->getAttribute('user_id');

                if ($userId !== null) {
                    return (string) $userId;
                }
            }
        }

        return null;
    }
}
