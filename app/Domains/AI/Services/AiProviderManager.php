<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Enums\AI\{AiProvider, AiProviderCallRole, AiRunMode};
use RuntimeException;

class AiProviderManager
{
    /**
     * @param array<string, AiProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly AiProviderSettings $settings,
    ) {
    }

    public function get(AiProvider|string $provider): AiProviderInterface
    {
        $code = $provider instanceof AiProvider ? $provider->value : (string) $provider;

        if (! array_key_exists($code, $this->providers)) {
            throw new RuntimeException("Provider IA [{$code}] não está registrado.");
        }

        return $this->providers[$code];
    }

    /**
     * @return array<int, array{role: AiProviderCallRole, provider: AiProviderInterface}>
     */
    public function providersForMode(AiRunMode $mode): array
    {
        // Gate de runtime: por SUFICIÊNCIA de provedores (validado exige 2,
        // consenso exige 3). A política de oferta no painel (qual modo mostrar)
        // é separada e vive no AiProviderSettings::availableModes()/controller.
        $needed = match ($mode) {
            AiRunMode::Economy   => 1,
            AiRunMode::Validated => 2,
            AiRunMode::Consensus => 3,
        };

        if ($this->settings->count() < $needed) {
            throw new RuntimeException("Modo [{$mode->value}] requer {$needed} provedor(es) de IA habilitado(s).");
        }

        $generator = $this->get($this->settings->roleCode(AiProviderCallRole::Generator));

        if ($mode === AiRunMode::Economy) {
            return [
                ['role' => AiProviderCallRole::Generator, 'provider' => $generator],
            ];
        }

        $reviewer = $this->get($this->settings->roleCode(AiProviderCallRole::Reviewer));

        if ($mode === AiRunMode::Validated) {
            return [
                ['role' => AiProviderCallRole::Generator, 'provider' => $generator],
                ['role' => AiProviderCallRole::Reviewer, 'provider' => $reviewer],
            ];
        }

        $adjudicator = $this->get($this->settings->roleCode(AiProviderCallRole::Adjudicator));

        return [
            ['role' => AiProviderCallRole::Generator, 'provider' => $generator],
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
        // Cadeia restrita ao conjunto de provedores HABILITADOS: começa pelo
        // provider preferido do papel e cai para os demais ativos (rotação da
        // lista de prioridade). Com 1 provedor habilitado, a cadeia tem 1 item.
        $chain = [];

        foreach ($this->settings->fallbackOrder($role) as $code) {
            if (array_key_exists($code, $this->providers)) {
                $chain[] = $this->providers[$code];
            }
        }

        return $chain;
    }
}
