<?php

namespace App\Exceptions;

use App\DTOs\FeatureStatus;
use App\Enums\{FeatureKey, SubscriptionAccessLevel};
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
        // Cliente em atraso com acesso limitado: IA volta com o pagamento.
        // Mesmo status e corpo do CheckSubscription (402 + access_level).
        $accessLevel = $this->status->deniedByAccessLevel;

        $message = match (true) {
            $accessLevel !== null    => $accessLevel->deniedMessage(),
            $this->status->isBoolean => __('subscriptions.feature_not_included', ['feature' => $this->feature->label()]),
            default                  => __('subscriptions.feature_limit_reached', [
                'feature' => $this->feature->label(),
                'limit'   => $this->status->limit,
            ]),
        };

        if ($request->expectsJson()) {
            return $accessLevel !== null
                ? response()->json([
                    'message'      => $message,
                    'access_level' => $accessLevel->value,
                    'feature'      => $this->status->toArray(),
                ], SubscriptionAccessLevel::DENIED_HTTP_STATUS)
                : response()->json([
                    'message' => $message,
                    'feature' => $this->status->toArray(),
                ], 403);
        }

        return back()
            ->withErrors(['feature_denied' => $message])
            ->with('feature_denied', $this->status->toArray());
    }
}
