<?php

namespace App\Services\Billing;

use App\Enums\Billing\CredentialScope;
use App\Models\Billing\GatewayCredential;
use Illuminate\Support\Facades\Cache;

/**
 * Resolução das credenciais de gateway do DONO do SaaS.
 *
 * Os gateways servem só para o EasyEye cobrar as CLÍNICAS (assinatura e
 * pacotes de créditos de IA). Clínica não tem gateway próprio: a credencial é
 * sempre a global (gateway_credentials com scope=global e entity_id NULL),
 * cadastrada em /panel/manager/gateways. Qualquer linha com entity_id é
 * ignorada — mesmo que alguém a grave por fora.
 *
 * Sem credencial no banco, o gateway cai na configuração do .env
 * (config('billing.gateways.*'), ver AbstractHttpGateway::resolveSecret).
 */
class GatewayCredentialResolver
{
    public function __construct(
        private readonly int $cacheTtl = 300,
    ) {
    }

    /**
     * Resolve a chave secreta/token de API do gateway.
     *
     * @param string $gatewayCode Código do gateway (ex: 'asaas')
     */
    public function resolveSecret(string $gatewayCode): ?string
    {
        $credentials = $this->resolveCredentialFromDb($gatewayCode)?->credentials;

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

    /**
     * Resolve o webhook secret para validação de assinatura.
     */
    public function resolveWebhookSecret(string $gatewayCode): ?string
    {
        return $this->resolveCredentialFromDb($gatewayCode)?->webhook_secret;
    }

    /**
     * Resolve credenciais extras (ex: handle, public_key).
     */
    public function resolveExtra(string $gatewayCode, string $key): ?string
    {
        $credential = $this->resolveCredentialFromDb($gatewayCode);

        if (! $credential || ! is_array($credential->credentials)) {
            return null;
        }

        $value = $credential->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Invalida o cache da credencial do gateway.
     */
    public function forgetCache(string $gatewayCode): void
    {
        Cache::forget(self::cacheKey($gatewayCode));
    }

    /** Mesma chave que o manager esquece ao salvar/revogar credencial. */
    public static function cacheKey(string $gatewayCode): string
    {
        return "gateway_credential:{$gatewayCode}:global";
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function resolveCredentialFromDb(string $gatewayCode): ?GatewayCredential
    {
        return Cache::remember(self::cacheKey($gatewayCode), $this->cacheTtl, static function () use ($gatewayCode): ?GatewayCredential {
            return GatewayCredential::query()
                ->whereHas('gateway', static fn ($q) => $q->where('code', $gatewayCode))
                ->where('active', true)
                ->where('scope', CredentialScope::Global->value)
                ->whereNull('entity_id')
                ->whereNull('deleted_at')
                ->where(static fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
                ->where(static fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
                ->orderByDesc('updated_at')
                ->first();
        });
    }
}
