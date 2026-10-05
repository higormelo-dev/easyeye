<?php

namespace App\Http\Middleware;

use App\Exceptions\Billing\CheckoutException;
use App\Models\{Entity, Subscription};
use App\Services\Billing\SubscriptionNoticeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checkout da assinatura: só o contato de cobrança da clínica da sessão
 * (admin, financeiro ou dono, vínculo ativo — EntityUser::billingContacts),
 * a mesma regra do aviso de pagamento e do canal billing.{entityId}.
 *
 * `billing.contact:signup` — o checkout do cadastro no site (antes de
 * confirmar e-mail/WhatsApp, fora do grupo /panel): só para clínica recém-
 * criada (até SIGNUP_WINDOW_DAYS) que nunca pagou, para que esse caminho sem
 * as verificações do painel não sirva para mexer na assinatura de uma clínica
 * que já existe.
 */
class EnsureBillingContact
{
    public const SIGNUP_WINDOW_DAYS = 7;

    public function __construct(
        private readonly SubscriptionNoticeService $notices,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $entityId = (string) session('selected_entity_id', '');
        $entity   = $entityId !== '' ? Entity::query()->find($entityId) : null;

        if ($entity === null || ! $entity->is_client) {
            throw CheckoutException::make('not_client', 404);
        }

        if (! $this->notices->canSeeBilling($request->user(), (string) $entity->id)) {
            throw CheckoutException::make('forbidden', 403);
        }

        if ($mode === 'signup' && ! $this->isFreshSignup($entity)) {
            throw CheckoutException::make('signup_only', 403);
        }

        $request->attributes->set('checkout_entity', $entity);

        return $next($request);
    }

    private function isFreshSignup(Entity $entity): bool
    {
        return $entity->created_at !== null
            && $entity->created_at->greaterThanOrEqualTo(now()->subDays(self::SIGNUP_WINDOW_DAYS))
            && ! Subscription::query()->forEntity((string) $entity->id)->whereNotNull('last_payment_at')->exists();
    }
}
