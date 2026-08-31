<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AI\{AiProvider, AiProviderCallRole, AiRunMode};
use App\Models\SubscriptionSetting;

/**
 * Fonte única da verdade sobre QUAIS provedores de IA o sistema pode usar e em
 * que ORDEM (prioridade). Lê o setting global `ai.enabled_providers`
 * (system_settings) — editável pelo dono do SaaS na área administrativa — e
 * deriva papéis (gerador/revisor/adjudicador), modos disponíveis e cadeia de
 * fallback. Quando o setting está ausente, cai para a ordem configurada em
 * config/ai.php (mantém o comportamento anterior em ambientes sem seed).
 */
class AiProviderSettings
{
    public const SETTING_KEY = 'ai.enabled_providers';

    /**
     * Códigos de provedores habilitados, na ordem de prioridade, já filtrados
     * para provedores conhecidos e configurados (com credencial + modelo).
     *
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        $codes = $this->rawEnabledCodes();

        // Mantém só os provedores CONHECIDOS, sem duplicar, preservando a ordem.
        // Com runtime REAL, também exige credencial: um provedor habilitado que
        // perdeu a chave (removida do env após a habilitação no Manager) lançava
        // em toda chamada — call 'failed' por run, latência extra e circuit
        // breaker abrindo por motivo de configuração, não de saúde do provedor.
        // No modo fake/testes a lista crua é preservada (fakes não usam chave).
        $requireKey = config('ai.provider_runtime') === 'real';

        $seen   = [];
        $result = [];

        foreach ($codes as $code) {
            $code = is_string($code) ? mb_strtolower(trim($code)) : '';

            if ($code === '' || isset($seen[$code]) || AiProvider::tryFrom($code) === null) {
                continue;
            }

            if ($requireKey && ! $this->isConfigured($code)) {
                continue;
            }

            $seen[$code] = true;
            $result[]    = $code;
        }

        // Salvaguarda: nunca deixar o sistema sem nenhum provedor. Aplica o
        // mesmo filtro de credencial ao fallback; se nem o fallback tem chave,
        // devolve a lista crua (o painel continua de pé e o erro de chave
        // aparece na execução — cenário de ambiente sem nenhuma credencial).
        if ($result === []) {
            $fallback = $this->configFallbackOrder();

            if ($requireKey) {
                $configured = array_values(array_filter($fallback, fn (string $c) => $this->isConfigured($c)));

                return $configured !== [] ? $configured : $fallback;
            }

            return $fallback;
        }

        return $result;
    }

    /**
     * @return list<AiProvider>
     */
    public function enabledProviders(): array
    {
        return array_map(static fn (string $c) => AiProvider::from($c), $this->enabledCodes());
    }

    public function count(): int
    {
        return count($this->enabledCodes());
    }

    /**
     * Provedor que atende um papel, derivado da ordem de prioridade:
     * 0 = gerador/primário, 1 = revisor, 2 = adjudicador. Com menos provedores
     * que o índice, faz clamp para o último disponível.
     */
    public function roleCode(AiProviderCallRole|string $role): string
    {
        $codes = $this->enabledCodes();
        $index = min($this->roleIndex($role), count($codes) - 1);

        return $codes[$index];
    }

    /**
     * Ordem de fallback para um papel: começa no provedor preferido do papel e
     * percorre os demais provedores ativos (rotação da lista de prioridade).
     *
     * @return list<string>
     */
    public function fallbackOrder(AiProviderCallRole|string $role): array
    {
        $codes = $this->enabledCodes();
        $count = count($codes);

        if ($count === 0) {
            return [];
        }

        $start   = min($this->roleIndex($role), $count - 1);
        $ordered = [];

        for ($i = 0; $i < $count; $i++) {
            $ordered[] = $codes[($start + $i) % $count];
        }

        return $ordered;
    }

    private function roleIndex(AiProviderCallRole|string $role): int
    {
        $role = $role instanceof AiProviderCallRole ? $role : AiProviderCallRole::from($role);

        return match ($role) {
            AiProviderCallRole::Generator, AiProviderCallRole::Fallback => 0,
            AiProviderCallRole::Reviewer    => 1,
            AiProviderCallRole::Adjudicator => 2,
        };
    }

    /**
     * Códigos de provedor por modo, cortados ao número de provedores ativos.
     *
     * @return list<string>
     */
    public function providerCodesForMode(AiRunMode $mode): array
    {
        $codes = $this->enabledCodes();

        $needed = match ($mode) {
            AiRunMode::Economy   => 1,
            AiRunMode::Validated => 2,
            AiRunMode::Consensus => 3,
        };

        return array_slice($codes, 0, min($needed, count($codes)));
    }

    /**
     * Modos disponíveis dado o número de provedores ativos (e a flag de consenso).
     *
     * @return list<AiRunMode>
     */
    public function availableModes(): array
    {
        $count = $this->count();

        // Com 1 provedor só é possível 1 chamada -> Economia.
        if ($count <= 1) {
            return [AiRunMode::Economy];
        }

        // Com 2+ provedores o piso volta a ser Validado (mantém a regra do
        // painel de exigir validação por 2 provedores); Consenso a partir de 3.
        $modes = [AiRunMode::Validated];

        if ($count >= 3 && (bool) config('ai.enable_consensus', true)) {
            $modes[] = AiRunMode::Consensus;
        }

        return $modes;
    }

    public function isModeAvailable(AiRunMode $mode): bool
    {
        return in_array($mode, $this->availableModes(), true);
    }

    /**
     * Um provedor está "configurado" quando tem credencial (services.$code.api_key)
     * e um modelo definido (ai.providers.$code.model).
     */
    public function isConfigured(string $code): bool
    {
        return filled(config("services.{$code}.api_key"))
            && filled(config("ai.providers.{$code}.model"));
    }

    /**
     * Modelo configurado para um provedor (read-only; vem de env/config).
     */
    public function model(string $code): ?string
    {
        $model = config("ai.providers.{$code}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Persiste a lista habilitada (ordenada). Não valida configuração aqui — a
     * validação de "configurado" fica na camada de entrada (Request/Controller).
     *
     * @param list<string> $codes
     */
    public function setEnabledCodes(array $codes): void
    {
        $clean = [];

        foreach ($codes as $code) {
            $code = is_string($code) ? mb_strtolower(trim($code)) : '';

            if ($code !== '' && AiProvider::tryFrom($code) !== null && ! in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        SubscriptionSetting::setValue(self::SETTING_KEY, json_encode(array_values($clean)));
    }

    /**
     * Lê o valor cru do setting (decodificado) sem filtragem.
     *
     * @return list<string>
     */
    private function rawEnabledCodes(): array
    {
        $raw = SubscriptionSetting::getValue(self::SETTING_KEY);

        if ($raw === null || $raw === '') {
            return $this->configFallbackOrder();
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? array_values($decoded) : $this->configFallbackOrder();
    }

    /**
     * Ordem de fallback derivada de config/ai.php (primary, reviewer, adjudicator),
     * deduplicada — preserva o comportamento legado quando o setting está ausente.
     *
     * @return list<string>
     */
    private function configFallbackOrder(): array
    {
        $order = [
            (string) config('ai.providers.primary', AiProvider::OpenAI->value),
            (string) config('ai.providers.reviewer', AiProvider::Anthropic->value),
            (string) config('ai.providers.adjudicator', AiProvider::Gemini->value),
        ];

        $seen   = [];
        $result = [];

        foreach ($order as $code) {
            if ($code !== '' && ! isset($seen[$code])) {
                $seen[$code] = true;
                $result[]    = $code;
            }
        }

        return $result;
    }
}
