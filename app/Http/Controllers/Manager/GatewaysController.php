<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Billing\CredentialScope;
use App\Http\Controllers\Controller;
use App\Models\Billing\{Gateway, GatewayCredential};
use App\Services\Audit\AuditLogger;
use App\Services\Billing\{GatewayCredentialResolver, GatewayDefaultService, GatewayRegistry};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;
use Inertia\{Inertia, Response};
use InvalidArgumentException;

/**
 * Manager → Gateways: gateways de pagamento do DONO do SaaS, usados para
 * cobrar as clínicas (assinatura e pacotes de créditos de IA). Clínica não
 * tem gateway próprio nem recebe pagamento por eles — as credenciais são
 * sempre globais (entity_id NULL, scope global).
 */
class GatewaysController extends Controller
{
    /** Gateway cuja credencial é a InfiniteTag (handle), não um token. */
    private const HANDLE_GATEWAY = 'infinitepay';

    public function __construct(
        private readonly GatewayDefaultService $defaultService,
        private readonly AuditLogger $audit,
        private readonly GatewayRegistry $registry,
    ) {
    }

    public function index(): Response
    {
        $gateways = Gateway::query()
            ->withCount([
                'credentials as active_credentials_count' => fn ($q) => $q
                    ->whereNull('entity_id')
                    ->where('scope', CredentialScope::Global->value)
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ])
            ->orderBy('priority')
            ->get();

        $defaultGateway = $this->defaultService->getDefault();

        return Inertia::render('Panel/Manager/Gateways/Index', [
            'gateways'       => $gateways->map(fn ($g) => $this->toRow($g))->values(),
            'defaultGateway' => $defaultGateway ? $this->toRow($defaultGateway) : null,
            't'              => trans('gateways'),
        ]);
    }

    public function setDefault(Gateway $gateway): JsonResponse
    {
        try {
            $this->defaultService->setDefault($gateway);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'    => __('gateways.set_default_success', ['name' => $gateway->name]),
            'gateway_id' => $gateway->id,
        ]);
    }

    public function credentials(Gateway $gateway): JsonResponse
    {
        $credentials = $gateway->credentials()
            ->whereNull('entity_id')
            ->where('scope', CredentialScope::Global->value)
            ->orderByDesc('active')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GatewayCredential $c) => [
                'id'         => $c->id,
                'label'      => $c->label,
                'active'     => $c->active,
                'valid_from' => $c->valid_from?->format('d/m/Y'),
                'valid_to'   => $c->valid_to?->format('d/m/Y'),
                'created_at' => $c->created_at->format('d/m/Y H:i'),
                'has_secret' => ! empty($c->credentials),
                // Pública por definição (vai ao navegador no checkout).
                'public_key' => is_array($c->credentials) ? ($c->credentials['public_key'] ?? null) : null,
                'revoke_url' => route('manager.gateways.credentials.revoke', [$gateway, $c]),
            ]);

        return response()->json(['data' => $credentials]);
    }

    public function toggleActive(Gateway $gateway): JsonResponse
    {
        $gateway->update(['active' => ! $gateway->active]);

        if (! $gateway->active && $gateway->is_default) {
            $gateway->update(['is_default' => false]);
            $this->defaultService->forgetCache();
        }

        return response()->json([
            'message' => $gateway->active ? __('gateways.gateway_activated') : __('gateways.gateway_deactivated'),
            'active'  => $gateway->active,
        ]);
    }

    public function updatePriority(Request $request, Gateway $gateway): JsonResponse
    {
        $request->validate([
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $gateway->update(['priority' => $request->priority]);
        $this->defaultService->forgetCache();

        return response()->json(['message' => __('gateways.priority_updated')]);
    }

    /**
     * Formato da chave PÚBLICA do SDK JS por gateway (vai ao navegador):
     *  - Mercado Pago: APP_USR-/TEST- + UUID ("Credenciais" → Public key;
     *    https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials).
     *    O access token também começa com APP_USR-, mas não é um UUID;
     *  - Pagar.me: pk_… / pk_test_… (https://docs.pagar.me/docs/chaves-de-acesso);
     *  - Stripe: pk_live_… / pk_test_… (https://docs.stripe.com/keys);
     *  - PagBank: chave pública RSA (PEM ou só o corpo base64 "MII…")
     *    (https://developer.pagbank.com.br/reference/criar-chave-publica).
     * Asaas e InfinitePay não têm cartão transparente: chave pública recusada.
     */
    private const PUBLIC_KEY_FORMATS = [
        'mercadopago' => '/^(APP_USR|TEST)-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'pagarme'     => '/^pk_(test_)?[A-Za-z0-9]{8,}$/',
        'stripe_br'   => '/^pk_(live|test)_[A-Za-z0-9]{10,}$/',
        'pagbank'     => '/^(-----BEGIN PUBLIC KEY-----[A-Za-z0-9+\/=\s]+-----END PUBLIC KEY-----|MII[A-Za-z0-9+\/=]{100,})$/',
    ];

    public function storeCredential(Request $request, Gateway $gateway): JsonResponse
    {
        // InfinitePay (Checkout Integrado): sem token nem segredo de webhook —
        // a credencial é a InfiniteTag (handle) e o webhook é conferido no
        // payment_check da própria InfinitePay.
        $usesHandle = $gateway->code === self::HANDLE_GATEWAY;

        $request->validate([
            'label'  => ['nullable', 'string', 'max:120'],
            'secret' => $usesHandle ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'min:8'],
            'handle' => $usesHandle ? ['required', 'string', 'regex:/^\$?[A-Za-z0-9._-]{1,64}$/'] : ['prohibited'],
            // Chave PÚBLICA do SDK JS do checkout transparente (Mercado Pago,
            // Stripe, Pagar.me; PagBank opcional — sem ela, vem da API). Vai
            // ao navegador; a secreta nunca.
            // Formato por gateway (self::PUBLIC_KEY_FORMATS); nunca igual ao
            // secret nem uma chave secreta (sk_/rk_/access token).
            'public_key' => isset(self::PUBLIC_KEY_FORMATS[$gateway->code])
                ? ['nullable', 'string', 'max:4096', 'not_regex:/^(sk_|rk_)/', 'regex:' . self::PUBLIC_KEY_FORMATS[$gateway->code], 'different:secret']
                : ['prohibited'],
            // BUGFIX (revisao de seguranca): gateway com supports_webhooks=true não pode
            // ser salvo sem webhook_secret — isso deixava validateWebhookSignature() sem
            // segredo para validar, forçando fail-open (webhook aceito sem autenticação).
            'webhook_secret' => $gateway->supports_webhooks && ! $usesHandle ? ['required', 'string'] : ['nullable', 'string'],
            'valid_from'     => ['nullable', 'date'],
            'valid_to'       => ['nullable', 'date', 'after:valid_from'],
            'reason'         => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required'       => __('manager_hardening.reason_required'),
            'reason.min'            => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'            => __('manager_hardening.reason_max', ['max' => 1000]),
            'handle.required'       => __('gateways.handle_required'),
            'handle.regex'          => __('gateways.handle_invalid'),
            'public_key.regex'      => __('gateways.public_key_invalid', ['format' => __("gateways.public_key_formats.{$gateway->code}")]),
            'public_key.not_regex'  => __('gateways.public_key_secret'),
            'public_key.different'  => __('gateways.public_key_secret'),
            'public_key.prohibited' => __('gateways.public_key_unsupported'),
        ]);

        $credentials = $usesHandle
            ? array_filter(['handle' => ltrim(trim((string) $request->input('handle')), '$'), 'secret' => $request->input('secret')], fn ($value) => filled($value))
            : array_filter(['secret' => $request->secret, 'public_key' => trim((string) $request->input('public_key')) ?: null], fn ($value) => filled($value));

        // Revoga credenciais globais anteriores (rotação automática).
        $oldCount = GatewayCredential::query()
            ->where('gateway_id', $gateway->id)
            ->whereNull('entity_id')
            ->where('active', true)
            ->update(['active' => false]);

        $credential = GatewayCredential::query()->create([
            'gateway_id'     => $gateway->id,
            'entity_id'      => null,
            'scope'          => CredentialScope::Global->value,
            'label'          => $request->label ?? 'Credencial ' . now()->format('d/m/Y H:i'),
            'credentials'    => $credentials,
            'webhook_secret' => $request->webhook_secret,
            'active'         => true,
            'valid_from'     => $request->valid_from,
            'valid_to'       => $request->valid_to,
        ]);

        Cache::forget(GatewayCredentialResolver::cacheKey($gateway->code));
        $this->defaultService->forgetCache();

        // Audit: rotação de credencial é evento crítico — registra qual gateway,
        // qual credential (UUID), e quantas anteriores foram revogadas em cascata.
        // NUNCA registramos o secret no audit log (defesa em profundidade).
        $this->audit->recordAdminAction(
            event: 'manager.gateway.credential.store',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'gateway_credential',
            auditableId: (string) $credential->id,
            reason: trim((string) $request->input('reason')),
            newValues: [
                'gateway_code'         => $gateway->code,
                'gateway_id'           => $gateway->id,
                'credential_label'     => $credential->label,
                'scope'                => CredentialScope::Global->value,
                'cascaded_revocations' => $oldCount,
                'has_webhook_secret'   => $request->filled('webhook_secret'),
                'has_handle'           => isset($credentials['handle']),
                'has_public_key'       => isset($credentials['public_key']),
                'valid_from'           => $request->valid_from,
                'valid_to'             => $request->valid_to,
            ],
            request: $request,
        );

        return response()->json(['message' => __('gateways.credential_saved')]);
    }

    public function revokeCredential(Request $request, Gateway $gateway, GatewayCredential $credential): JsonResponse
    {
        if ($credential->gateway_id !== $gateway->id) {
            abort(404);
        }

        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $credential->update(['active' => false]);

        Cache::forget(GatewayCredentialResolver::cacheKey($gateway->code));
        $this->defaultService->forgetCache();

        $this->audit->recordAdminAction(
            event: 'manager.gateway.credential.revoke',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'gateway_credential',
            auditableId: (string) $credential->id,
            reason: trim((string) $request->input('reason')),
            newValues: [
                'gateway_code'     => $gateway->code,
                'gateway_id'       => $gateway->id,
                'credential_label' => $credential->label,
            ],
            request: $request,
        );

        return response()->json(['message' => __('gateways.credential_revoked')]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function toRow(Gateway $g): array
    {
        $credCount = $g->active_credentials_count ?? 0;

        return [
            'id'                        => $g->id,
            'code'                      => $g->code,
            'name'                      => $g->name,
            'active'                    => (bool) $g->active,
            'is_default'                => (bool) $g->is_default,
            'priority'                  => $g->priority,
            'supports_subscriptions'    => (bool) $g->supports_subscriptions,
            'supports_one_time_charges' => (bool) $g->supports_one_time_charges,
            'supports_refunds'          => (bool) $g->supports_refunds,
            'supports_webhooks'         => (bool) $g->supports_webhooks,
            'active_credentials_count'  => $credCount,
            'credentials_label'         => $credCount > 0
                ? trans_choice('gateways.credentials_active', $credCount, ['count' => $credCount])
                : null,
            'can_be_default' => (bool) $g->active && $credCount > 0,
            'capabilities'   => $this->capabilities($g->code),
            // Último health check real (billing:gateway-health, diário).
            'health' => is_array($g->health) ? [
                'status'     => $g->health['status'] ?? null,
                'healthy'    => (bool) ($g->health['healthy'] ?? false),
                'message'    => $g->health['message'] ?? null,
                'checked_at' => $g->health_checked_at?->toIso8601String(),
            ] : null,
            // Route URLs
            'set_default_url'       => route('manager.gateways.set-default', $g),
            'toggle_active_url'     => route('manager.gateways.toggle-active', $g),
            'priority_url'          => route('manager.gateways.priority', $g),
            'credentials_url'       => route('manager.gateways.credentials', $g),
            'credentials_store_url' => route('manager.gateways.credentials.store', $g),
        ];
    }

    /**
     * O que o gateway faz hoje na EasyEye, lido dos métodos de capacidade da
     * classe (sem chamada HTTP — nada aqui busca chave pública na API):
     *  - transparent: formas cobradas sem sair do EasyEye (pix/boleto/cartão);
     *    cartão transparente ainda depende da chave pública na credencial;
     *  - card_link: cobrança sem forma definida abre a página do gateway com cartão;
     *  - max_installments: teto de parcelas do cartão transparente (null = sem cartão);
     *  - saved_card_renewal: renova cobrando o cartão guardado no gateway;
     *  - card_replacement: troca o cartão da renovação sem cobrar (exige a chave pública);
     *  - native_recurrence: a recorrência é do gateway (ele emite cada ciclo);
     *    senão o EasyEye emite a cobrança de cada renovação;
     *  - hosted_card_checkout: cartão pago na página hospedada do gateway, com
     *    volta para o EasyEye (Asaas Checkout);
     *  - refund / partial_refund: o manager estorna pela API.
     *
     * Null quando o código não tem classe registrada (gateway só no banco).
     *
     * @return array<string, mixed>|null
     */
    private function capabilities(string $code): ?array
    {
        if (! $this->registry->has($code)) {
            return null;
        }

        $gateway     = $this->registry->get($code);
        $transparent = array_values($gateway->transparentMethods());
        $hasCard     = in_array('credit_card', $transparent, true);

        return [
            'transparent'        => $transparent,
            'card_link'          => $gateway->supportsCardLink(),
            'max_installments'   => $hasCard ? $gateway->cardMaxInstallments() : null,
            'saved_card_renewal' => $gateway->chargesSavedCards(),
            'card_replacement'   => $gateway->supportsCardReplacement(),
            'native_recurrence'  => $gateway->subscriptionIssuesFirstCharge(),
            // Cartão na página hospedada do gateway (Asaas Checkout) e estorno pela API.
            'hosted_card_checkout' => $gateway->supportsHostedCardCheckout(),
            'refund'               => $gateway->supportsRefund(),
            'partial_refund'       => $gateway->supportsRefund() && $gateway->supportsPartialRefund(),
        ];
    }
}
