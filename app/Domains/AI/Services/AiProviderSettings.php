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
     * Papéis EXPLÍCITOS definidos no painel do Manager: quem é o principal
     * (gerador), o revisor e o árbitro (adjudicador). Tem precedência sobre a
     * lista ordenada legada — que permanece sincronizada para retrocompat.
     */
    public const ROLES_SETTING_KEY = 'ai.provider_roles';

    public const ROLE_KEYS = ['primary', 'reviewer', 'adjudicator'];

    /**
     * Modelos escolhidos no painel por provedor ({openai: 'gpt-4o', ...}).
     * O TOKEN continua no .env (segredo); o MODELO é operação do dia a dia —
     * editável pelo admin do SaaS sem deploy. Fallback: config/ai.php (env).
     */
    public const MODELS_SETTING_KEY = 'ai.provider_models';

    /**
     * Códigos de provedores habilitados, na ordem de prioridade, já filtrados
     * para provedores conhecidos e configurados (com credencial + modelo).
     *
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        $codes = $this->rawOrderedCodes();

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
            && $this->model($code) !== null;
    }

    /**
     * Modelo EFETIVO de um provedor: escolha do painel (system_settings) com
     * fallback para env/config. Fonte única — providers e estimativa leem daqui.
     */
    public function model(string $code): ?string
    {
        $fromPanel = $this->panelModels()[$code] ?? null;

        if ($fromPanel !== null) {
            return $fromPanel;
        }

        $model = config("ai.providers.{$code}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Modelos salvos pelo painel (sem fallback), validados por provedor.
     *
     * @return array<string, string>
     */
    public function panelModels(): array
    {
        $raw     = SubscriptionSetting::getValue(self::MODELS_SETTING_KEY);
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $code => $model) {
            $code = is_string($code) ? mb_strtolower(trim($code)) : '';

            if ($code === '' || AiProvider::tryFrom($code) === null) {
                continue;
            }

            if (is_string($model) && trim($model) !== '') {
                $out[$code] = trim($model);
            }
        }

        return $out;
    }

    /**
     * Persiste os modelos do painel (parcial: só os provedores presentes;
     * valor vazio/null remove a escolha e volta ao fallback do env).
     *
     * @param array<string, ?string> $models
     */
    public function setModels(array $models): void
    {
        $current = $this->panelModels();

        foreach ($models as $code => $model) {
            $code = is_string($code) ? mb_strtolower(trim($code)) : '';

            if ($code === '' || AiProvider::tryFrom($code) === null) {
                continue;
            }

            if (is_string($model) && trim($model) !== '') {
                $current[$code] = trim($model);
            } else {
                unset($current[$code]);
            }
        }

        SubscriptionSetting::setValue(self::MODELS_SETTING_KEY, json_encode($current));
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
     * Papéis explícitos como salvos pelo painel (sem filtro de credencial).
     * Ausente/inválido → deriva da lista ordenada legada (índices 0/1/2).
     *
     * @return array{primary: ?string, reviewer: ?string, adjudicator: ?string}
     */
    public function roleAssignments(): array
    {
        $raw = SubscriptionSetting::getValue(self::ROLES_SETTING_KEY);

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (is_array($decoded)) {
            $out  = ['primary' => null, 'reviewer' => null, 'adjudicator' => null];
            $seen = [];

            foreach (self::ROLE_KEYS as $key) {
                $code = $decoded[$key] ?? null;
                $code = is_string($code) ? mb_strtolower(trim($code)) : '';

                if ($code === '' || isset($seen[$code]) || AiProvider::tryFrom($code) === null) {
                    continue;
                }

                $seen[$code] = true;
                $out[$key]   = $code;
            }

            if ($out['primary'] !== null) {
                return $out;
            }
        }

        // Legado: a ordem da lista habilitada define os papéis. Saneia
        // (códigos válidos, sem duplicar) ANTES de fatiar os 3 primeiros —
        // um código inválido no meio não pode "roubar" um papel.
        $legacy = [];

        foreach ($this->rawEnabledCodes() as $code) {
            $code = is_string($code) ? mb_strtolower(trim($code)) : '';

            if ($code !== '' && AiProvider::tryFrom($code) !== null && ! in_array($code, $legacy, true)) {
                $legacy[] = $code;
            }
        }

        return [
            'primary'     => $legacy[0] ?? null,
            'reviewer'    => $legacy[1] ?? null,
            'adjudicator' => $legacy[2] ?? null,
        ];
    }

    /**
     * Persiste os papéis explícitos e sincroniza a lista legada (retrocompat).
     * A invalidação de cache é imediata (SubscriptionSetting::setValue faz
     * Cache::forget num store compartilhado) — a mudança vale para todos os
     * clientes no request seguinte, sem deploy nem mexer em .env.
     *
     * @param array{primary: ?string, reviewer: ?string, adjudicator: ?string} $roles
     */
    public function setRoleAssignments(array $roles): void
    {
        $clean = ['primary' => null, 'reviewer' => null, 'adjudicator' => null];
        $seen  = [];

        foreach (self::ROLE_KEYS as $key) {
            $code = $roles[$key] ?? null;
            $code = is_string($code) ? mb_strtolower(trim($code)) : '';

            if ($code === '' || isset($seen[$code]) || AiProvider::tryFrom($code) === null) {
                continue;
            }

            $seen[$code] = true;
            $clean[$key] = $code;
        }

        SubscriptionSetting::setValue(self::ROLES_SETTING_KEY, json_encode($clean));

        // Lista legada espelha os papéis na ordem primary→reviewer→adjudicator.
        $this->setEnabledCodes(array_values(array_filter($clean)));
    }

    /**
     * Lista ordenada papel→provedor (primary, reviewer, adjudicator), fonte
     * dos enabledCodes(). Papel vazio simplesmente não entra na lista.
     *
     * @return list<string>
     */
    private function rawOrderedCodes(): array
    {
        return array_values(array_filter($this->roleAssignments()));
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
