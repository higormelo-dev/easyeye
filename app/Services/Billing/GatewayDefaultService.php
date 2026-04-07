<?php

namespace App\Services\Billing;

use App\Models\Billing\Gateway;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Gerencia o gateway padrão do SaaS para billing de assinaturas.
 *
 * Regras:
 *   - Apenas UM gateway pode ser o padrão por vez.
 *   - O gateway precisa estar ativo (active = true).
 *   - O gateway precisa ter ao menos uma credencial global ativa.
 *   - A troca é atômica: limpa todos antes de definir o novo.
 *   - O resultado é cacheado para evitar consulta a cada resolução de gateway.
 *   - Fallback seguro: se nenhum gateway estiver marcado como padrão no banco,
 *     usa o de menor prioridade (priority ASC) que esteja ativo e com credencial.
 */
class GatewayDefaultService
{
    private const CACHE_KEY = 'billing:default_gateway_code';
    private const CACHE_TTL = 600; // 10 minutos

    /**
     * Retorna o código do gateway padrão.
     * Nunca lança exceção — retorna null se não houver nenhum configurado.
     */
    public function getDefaultCode(): ?string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): ?string {
            // 1. Gateway explicitamente marcado como padrão
            $explicit = Gateway::query()
                ->where('is_default', true)
                ->where('active', true)
                ->whereHas('credentials', fn ($q) => $q
                    ->whereNull('entity_id')
                    ->where('scope', 'global')
                    ->where('active', true)
                    ->whereNull('deleted_at')
                )
                ->value('code');

            if ($explicit) {
                return $explicit;
            }

            // 2. Fallback: menor prioridade ativo com credencial
            return Gateway::query()
                ->where('active', true)
                ->whereHas('credentials', fn ($q) => $q
                    ->whereNull('entity_id')
                    ->where('scope', 'global')
                    ->where('active', true)
                    ->whereNull('deleted_at')
                )
                ->orderBy('priority')
                ->value('code');
        });
    }

    /**
     * Retorna o gateway padrão completo (model).
     */
    public function getDefault(): ?Gateway
    {
        $code = $this->getDefaultCode();

        return $code ? Gateway::query()->where('code', $code)->first() : null;
    }

    /**
     * Define um gateway como padrão do sistema.
     *
     * @throws \InvalidArgumentException se o gateway não puder ser o padrão.
     */
    public function setDefault(Gateway $gateway): void
    {
        if (! $gateway->active) {
            throw new \InvalidArgumentException(__('gateways.error_inactive_gateway'));
        }

        $hasCredential = $gateway->credentials()
            ->whereNull('entity_id')
            ->where('scope', 'global')
            ->where('active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasCredential) {
            throw new \InvalidArgumentException(__('gateways.error_no_credential'));
        }

        DB::transaction(function () use ($gateway): void {
            // Remove o flag de todos os outros
            Gateway::query()
                ->where('id', '!=', $gateway->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // Define o novo padrão
            $gateway->update(['is_default' => true]);
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Invalida o cache do gateway padrão.
     * Útil ao salvar/revogar credenciais — o fallback pode mudar.
     */
    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
