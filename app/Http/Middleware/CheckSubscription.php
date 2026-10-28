<?php

namespace App\Http\Middleware;

use App\Enums\SubscriptionAccessLevel;
use App\Models\Entity;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia o painel da clínica sem assinatura com acesso: trial vencido,
 * cortesia ou plano expirado, cancelado ou contratação cujo 1º vencimento
 * passou sem pagamento. Não há período de graça — o acesso volta só com uma
 * nova assinatura (ou com o pagamento confirmado).
 *
 * Cliente pagante em atraso segue a régua de cobrança (Subscription::accessLevel):
 * com acesso limitado, só a IA e o módulo financeiro ficam bloqueados —
 * agenda, pacientes, prontuário e o resto do painel seguem.
 *
 * Continuam liberados: empresas que não são clientes (equipe do SaaS), a
 * equipe do SaaS impersonando um usuário (suporte, já auditado) e o perfil do
 * próprio usuário (dados pessoais dele — LGPD). `billing.enforce_subscription_access`
 * = false desliga o bloqueio (chave de emergência).
 */
class CheckSubscription
{
    /**
     * Rotas abertas mesmo com o acesso bloqueado: o perfil e "Minha
     * assinatura" (checkout — é por ela que a clínica bloqueada paga).
     */
    private const ALWAYS_ALLOWED = ['panel.profile.*', 'panel.my-subscription.*'];

    /**
     * Bloqueadas no acesso limitado: IA (análises, assistente, chat
     * flutuante, compra de créditos, prompts) e as telas do módulo
     * financeiro (caixa, faturamento/TISS, glosas, repasses, preços e
     * relatórios financeiros). O "Lançar no caixa" do atendimento, feito
     * pela agenda (panel.schedules.cash-entry.*), é operação clínica e segue
     * liberado: com "Atendido exige caixa", é o único caminho para marcar
     * Atendido ou finalizar pelo prontuário.
     */
    public const LIMITED_BLOCKED = [
        'panel.ai-runs.*',
        'panel.ai-credit-purchases.*',
        'panel.setting.ai-prompts.*',
        'panel.financial.*',
        'panel.my-payouts.*',
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $entityId = session('selected_entity_id');

        if (! $entityId
            || ! config('billing.enforce_subscription_access', true)
            || session()->has('impersonating')
            || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $entity = Entity::find($entityId);

        // Entidades não-cliente (equipe interna/manager) não precisam de assinatura
        if (! $entity || ! $entity->is_client) {
            return $next($request);
        }

        // Guardada no request: o aviso do painel, o plano no cabeçalho e o
        // gate de recursos reaproveitam a mesma consulta.
        $level = $this->subscriptionService->currentAccessFor($request, $entity)?->accessLevel()
            ?? SubscriptionAccessLevel::None;

        if ($level === SubscriptionAccessLevel::None
            || ($level === SubscriptionAccessLevel::Limited && $request->routeIs(...self::LIMITED_BLOCKED))) {
            return $this->deny($request, $level);
        }

        return $next($request);
    }

    /**
     * JSON (axios) recebe 402 com `access_level` — o mesmo da negação do
     * FeatureGate (FeatureDeniedException); navegação vai para a tela que
     * explica e oferece o pagamento.
     */
    private function deny(Request $request, SubscriptionAccessLevel $level): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message'      => $level->deniedMessage(),
                'access_level' => $level->value,
            ], SubscriptionAccessLevel::DENIED_HTTP_STATUS);
        }

        return redirect()->route('subscription.expired')->with('warning', $level->deniedMessage());
    }
}
