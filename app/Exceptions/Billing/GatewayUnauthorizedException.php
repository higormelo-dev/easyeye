<?php

namespace App\Exceptions\Billing;

use Illuminate\Http\JsonResponse;

/**
 * Webhook com assinatura/credencial inválida (ou sem segredo configurado).
 * Responde 401 — não 500: o gateway entende como recusa, e o erro não vira
 * falha do servidor nos alertas.
 */
class GatewayUnauthorizedException extends GatewayIntegrationException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => 'Unauthorized webhook.',
        ], 401);
    }
}
