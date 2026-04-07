<?php

namespace App\Services\Billing;

use App\Models\Billing\GatewayCredential;
use Illuminate\Support\Facades\Cache;

/**
 * Resolução de credenciais de gateway com separação explícita de contexto.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CONTEXTO 1 — SaaS billing (entityId = null)                       │
 * │  Propósito: cobrar CLÍNICAS pela assinatura do EasyEye             │
 * │  Prioridade:                                                        │
 * │    1. .env  (ASAAS_SECRET, INFINITEPAY_SECRET…)  ← fonte primária  │
 * │    2. gateway_credentials (scope=global) ← override sem redeploy   │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │  CONTEXTO 2 — Tenant payment (entityId = uuid da clínica)          │
 * │  Propósito: clínica recebe pagamentos dos PACIENTES dela            │
 * │  Prioridade:                                                        │
 * │    1. gateway_credentials (scope=tenant, entity_id=uuid)           │
 * │    NÃO cai para global — chave do SaaS ≠ chave da clínica          │
 * └─────────────────────────────────────────────────────────────────────┘
 */
class GatewayCredentialResolver
{
    /** Mapeamento de gateway code → nome da variável de ambiente. */
    private const ENV_MAP = [
        'asaas'       => 'ASAAS_SECRET',
        'infinitepay' => 'INFINITEPAY_SECRET',
        'mercadopago' => 'MERCADOPAGO_SECRET',
        'pagarme'     => 'PAGARME_SECRET',
        'stripe_br'   => 'STRIPE_BR_SECRET',
        'pagbank'     => 'PAGBANK_SECRET',
    ];

    private const ENV_WEBHOOK_MAP = [
        'asaas'       => 'ASAAS_WEBHOOK_SECRET',
        'infinitepay' => 'INFINITEPAY_WEBHOOK_SECRET',
        'mercadopago' => 'MERCADOPAGO_WEBHOOK_SECRET',
        'pagarme'     => 'PAGARME_WEBHOOK_SECRET',
        'stripe_br'   => 'STRIPE_BR_WEBHOOK_SECRET',
        'pagbank'     => 'PAGBANK_WEBHOOK_SECRET',
    ];

    public function __construct(
        private readonly int $cacheTtl = 300,
    ) {
    }

    /**
     * Resolve a chave secreta/token de API para o gateway.
     *
     * @param  string       $gatewayCode  Código do gateway (ex: 'asaas')
     * @param  string|null  $entityId     null = contexto SaaS billing | uuid = contexto tenant
     */
    public function resolveSecret(string $gatewayCode, ?string $entityId = null): ?string
    {
        // ── Contexto SaaS billing (entityId = null) ───────────────────────
        if ($entityId === null) {
            // 1. .env é a fonte primária
            $envSecret = $this->resolveFromEnv($gatewayCode);
            if ($envSecret !== null) {
                return $envSecret;
            }

            // 2. Fallback: credencial global no banco (override operacional sem redeploy)
            return $this->resolveSecretFromDb($gatewayCode, null);
        }

        // ── Contexto tenant (entityId = uuid da clínica) ──────────────────
        // Busca APENAS credencial do tenant — jamais usa chave do SaaS
        return $this->resolveSecretFromDb($gatewayCode, $entityId);
    }

    /**
     * Resolve o webhook secret para validação de assinatura.
     */
    public function resolveWebhookSecret(string $gatewayCode, ?string $entityId = null): ?string
    {
        if ($entityId === null) {
            $envSecret = env(self::ENV_WEBHOOK_MAP[$gatewayCode] ?? '');
            if (is_string($envSecret) && $envSecret !== '') {
                return $envSecret;
            }

            return $this->resolveCredentialFromDb($gatewayCode, null)?->webhook_secret;
        }

        return $this->resolveCredentialFromDb($gatewayCode, $entityId)?->webhook_secret;
    }

    /**
     * Resolve credenciais extras (ex: client_id, public_key).
     */
    public function resolveExtra(string $gatewayCode, string $key, ?string $entityId = null): ?string
    {
        $credential = ($entityId === null)
            ? $this->resolveCredentialFromDb($gatewayCode, null)
            : $this->resolveCredentialFromDb($gatewayCode, $entityId);

        if (! $credential || ! is_array($credential->credentials)) {
            return null;
        }

        $value = $credential->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Verifica se um tenant tem gateway próprio configurado.
     */
    public function tenantHasGateway(string $gatewayCode, string $entityId): bool
    {
        return $this->resolveCredentialFromDb($gatewayCode, $entityId) !== null;
    }

    /**
     * Invalida o cache para o gateway/tenant específico.
     */
    public function forgetCache(string $gatewayCode, ?string $entityId = null): void
    {
        Cache::forget("gateway_credential:{$gatewayCode}:" . ($entityId ?? 'global'));
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function resolveFromEnv(string $gatewayCode): ?string
    {
        $envKey = self::ENV_MAP[$gatewayCode] ?? null;
        if ($envKey === null) {
            return null;
        }

        $value = env($envKey);

        return (is_string($value) && $value !== '') ? $value : null;
    }

    private function resolveSecretFromDb(string $gatewayCode, ?string $entityId): ?string
    {
        $credential  = $this->resolveCredentialFromDb($gatewayCode, $entityId);
        $credentials = $credential?->credentials;

        if (! is_array($credentials)) {
            return null;
        }

        foreach (['secret', 'api_key', 'token', 'access_token'] as $candidate) {
            $value = $credentials[$candidate] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveCredentialFromDb(string $gatewayCode, ?string $entityId): ?GatewayCredential
    {
        $cacheKey = "gateway_credential:{$gatewayCode}:" . ($entityId ?? 'global');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($gatewayCode, $entityId): ?GatewayCredential {
            $scope = $entityId ? 'tenant' : 'global';

            return GatewayCredential::query()
                ->whereHas('gateway', static fn ($q) => $q->where('code', $gatewayCode))
                ->where('active', true)
                ->where('scope', $scope)
                ->where(fn ($q) => $entityId ? $q->where('entity_id', $entityId) : $q->whereNull('entity_id'))
                ->whereNull('deleted_at')
                ->where(static fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
                ->where(static fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
                ->orderByDesc('updated_at')
                ->first();
        });
    }
}
