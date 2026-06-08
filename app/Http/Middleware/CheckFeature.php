<?php

namespace App\Http\Middleware;

use App\Enums\FeatureKey;
use App\Exceptions\FeatureDeniedException;
use App\Services\FeatureGateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de verificação de feature por plano.
 *
 * Uso nas rotas:
 *
 *   ->middleware('feature:max_users')
 *   ->middleware('feature:has_ai_exam_assistant')
 *   ->middleware('feature:ai_monthly_credits')
 *
 * Comportamento:
 *   - Lê o entity_id da sessão (selected_entity_id).
 *   - Verifica via FeatureGateService se a feature está disponível.
 *   - JSON/API: retorna 403 com payload padronizado.
 *   - Web: lança FeatureDeniedException (redireciona para planos ou 403).
 *
 * Pré-requisito: executar após os middlewares auth e entity.selected.
 */
class CheckFeature
{
    public function __construct(
        private readonly FeatureGateService $gate,
    ) {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $feature = FeatureKey::tryFrom($featureKey);

        if (! $feature) {
            // Chave inválida: falha aberta (permite acesso) para não bloquear
            // rotas por typo silencioso em produção. Log do erro em debug.
            logger()->warning("CheckFeature: chave de feature inválida [{$featureKey}]");

            return $next($request);
        }

        $entityId = session('selected_entity_id');

        if (! $entityId) {
            return $next($request);
        }

        $status = $this->gate->status($entityId, $feature);

        if ($status->allowed) {
            return $next($request);
        }

        throw new FeatureDeniedException($feature, $status);
    }
}
