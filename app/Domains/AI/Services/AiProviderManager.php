<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiProviderCallRole;
use App\Enums\AI\AiRunMode;

class AiProviderManager
{
    /**
     * @param array<string, AiProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {
    }

    public function get(AiProvider|string $provider): AiProviderInterface
    {
        $code = $provider instanceof AiProvider ? $provider->value : (string) $provider;

        if (! array_key_exists($code, $this->providers)) {
            throw new \RuntimeException("Provider IA [{$code}] não está registrado.");
        }

        return $this->providers[$code];
    }

    /**
     * @return array<int, array{role: AiProviderCallRole, provider: AiProviderInterface}>
     */
    public function providersForMode(AiRunMode $mode): array
    {
        if ($mode === AiRunMode::Consensus && ! (bool) config('ai.enable_consensus', true)) {
            throw new \RuntimeException('Modo consensus está desabilitado por configuração.');
        }

        $primary = $this->get($this->configured('primary', AiProvider::OpenAI->value));

        if ($mode === AiRunMode::Economy) {
            return [
                ['role' => AiProviderCallRole::Generator, 'provider' => $primary],
            ];
        }

        $reviewer = $this->get($this->configured('reviewer', AiProvider::Anthropic->value));

        if ($mode === AiRunMode::Validated) {
            return [
                ['role' => AiProviderCallRole::Generator, 'provider' => $primary],
                ['role' => AiProviderCallRole::Reviewer, 'provider' => $reviewer],
            ];
        }

        $adjudicator = $this->get($this->configured('adjudicator', AiProvider::Gemini->value));

        return [
            ['role' => AiProviderCallRole::Generator, 'provider' => $primary],
            ['role' => AiProviderCallRole::Reviewer, 'provider' => $reviewer],
            ['role' => AiProviderCallRole::Adjudicator, 'provider' => $adjudicator],
        ];
    }

    /**
     * Cadeia de fallback para um papel. Cada papel começa pelo seu provider preferido
     * e cai para os demais providers registrados se o preferido falhar ou seu circuit
     * breaker estiver aberto. Ordem importa: o primeiro é o preferido, os demais são
     * tentados em sequência.
     *
     * @return list<AiProviderInterface>
     */
    public function fallbackChainForRole(AiProviderCallRole $role): array
    {
        $primaryCode     = $this->configured('primary', AiProvider::OpenAI->value);
        $reviewerCode    = $this->configured('reviewer', AiProvider::Anthropic->value);
        $adjudicatorCode = $this->configured('adjudicator', AiProvider::Gemini->value);

        $preferred = match ($role) {
            AiProviderCallRole::Generator   => [$primaryCode, $reviewerCode, $adjudicatorCode],
            AiProviderCallRole::Reviewer    => [$reviewerCode, $adjudicatorCode, $primaryCode],
            AiProviderCallRole::Adjudicator => [$adjudicatorCode, $primaryCode, $reviewerCode],
            AiProviderCallRole::Fallback    => [$primaryCode, $reviewerCode, $adjudicatorCode],
        };

        // Deduplica preservando ordem (caso o usuário tenha configurado o mesmo
        // provider para múltiplos papéis) e ignora códigos não registrados.
        $seen  = [];
        $chain = [];
        foreach ($preferred as $code) {
            if (isset($seen[$code]) || !array_key_exists($code, $this->providers)) {
                continue;
            }
            $seen[$code] = true;
            $chain[]     = $this->providers[$code];
        }

        return $chain;
    }

    private function configured(string $key, string $fallback): string
    {
        return (string) config("ai.providers.{$key}", $fallback);
    }
}
