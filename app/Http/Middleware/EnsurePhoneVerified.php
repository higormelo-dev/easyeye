<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\PhoneVerificationService;
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
 *  - canal indisponível para o código (PhoneVerificationService::
 *    channelAvailable: app global ausente/inativo, driver gupshup sem
 *    credenciais do parceiro ou com o token universal vencido, alerta
 *    recente de canal fora do ar — token recusado, limite de UATs, saldo —,
 *    driver mock fora de dev local/testes automatizados) OU o último envio
 *    do código a este usuário falhou por configuração (lista branca:
 *    template reprovado/pausado, app/credencial recusada) ou esgotou as
 *    tentativas com o canal fora do ar — com alerta ao time: sem como
 *    ENTREGAR o código, exigir verificação trancaria todo onboarding por um
 *    problema do SaaS — gate desativado com log (fail-open aqui é
 *    deliberado: WhatsApp é qualidade de contato comercial, não autenticação;
 *    e-mail continua obrigatório via `verified`). Falha do lado do número do
 *    usuário (sem WhatsApp, descadastrado) NÃO libera: ele corrige o número.
 *
 * Caso contrário redireciona para a tela de confirmação
 * (phone.verification.notice); requests JSON recebem 403 com mensagem.
 */
class EnsurePhoneVerified
{
    public function __construct(private readonly PhoneVerificationService $verification)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || blank($user->phone) || $user->phone_verified_at !== null) {
            return $next($request);
        }

        if (! PhoneVerificationService::channelAvailable() || $this->verification->lastDeliveryFailedPermanently($user)) {
            logger()->info('EnsurePhoneVerified: WhatsApp do EasyEye não entrega o código — gate liberado.', [
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
