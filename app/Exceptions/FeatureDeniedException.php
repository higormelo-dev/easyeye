<?php

namespace App\Exceptions;

use App\DTOs\FeatureStatus;
use App\Enums\FeatureKey;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lançada quando a empresa não tem acesso a uma feature específica.
 *
 * É renderável — o Laravel a converte automaticamente em resposta
 * JSON ou redirect dependendo do tipo de requisição.
 *
 * Uso:
 *   throw new FeatureDeniedException(FeatureKey::HasAiExamAssistant, $status);
 */
class FeatureDeniedException extends RuntimeException
{
    public function __construct(
        public readonly FeatureKey $feature,
        public readonly FeatureStatus $status,
    ) {
        parent::__construct(
            "Acesso à feature [{$feature->value}] negado para esta empresa.",
        );
    }

    /**
     * Renderiza a exception como resposta HTTP.
     * Chamado automaticamente pelo Laravel quando a exception não é capturada.
     */
    public function render(Request $request): Response
    {
        $message = $this->status->isBoolean
            ? __('subscriptions.feature_not_included', ['feature' => $this->feature->label()])
            : __('subscriptions.feature_limit_reached', [
                'feature' => $this->feature->label(),
                'limit'   => $this->status->limit,
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'feature' => $this->status->toArray(),
            ], 403);
        }

        return back()
            ->withErrors(['feature_denied' => $message])
            ->with('feature_denied', $this->status->toArray());
    }
}
