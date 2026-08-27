<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WhatsApp\WhatsAppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de verificação do WhatsApp do responsável — par do middleware
 * `verified` (e-mail), aplicado ao painel logo após ele. Sequência de
 * onboarding: registro → confirma e-mail → confirma WhatsApp → painel.
 *
 * Deixa passar quando:
 *  - usuário sem telefone cadastrado (contas anteriores à captura do
 *    WhatsApp no registro — nada a verificar);
 *  - telefone já verificado (phone_verified_at);
 *  - instância GLOBAL Z-API do SaaS ausente/inoperante: sem como ENTREGAR
 *    o código, exigir verificação trancaria todo onboarding por um problema
 *    de configuração do SaaS — gate desativado com log (fail-open aqui é
 *    deliberado: WhatsApp é qualidade de contato comercial, não autenticação;
 *    e-mail continua obrigatório via `verified`).
 *
 * Caso contrário redireciona para a tela de confirmação
 * (phone.verification.notice); requests JSON recebem 403 com mensagem.
 */
class EnsurePhoneVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || blank($user->phone) || $user->phone_verified_at !== null) {
            return $next($request);
        }

        $global = WhatsAppSetting::globalSetting();

        if (! $global || ! $global->isOperational()) {
            logger()->info('EnsurePhoneVerified: instância global de WhatsApp indisponível — gate liberado.', [
                'user_id' => $user->id,
            ]);

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('auth.phone_verification.required'),
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('phone.verification.notice');
    }
}
