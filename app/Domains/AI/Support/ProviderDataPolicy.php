<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Enums\AI\AiProvider;

/**
 * Proteção de dados (LGPD) de cada provedor de IA quanto a dados de
 * PACIENTES — fonte única do painel (Manager → Provedores de IA), da trava de
 * papéis do assistente e do filtro em execução real.
 *
 * Fatos conferidos nas fontes oficiais em 03/10/2026 (lista em sources() e
 * em docs/legal/ai-providers-lgpd.md). Mudou o contrato/termo do provedor:
 * revise aqui e no documento.
 *
 * - Transferência internacional (LGPD art. 33): no Brasil não há; para a UE
 *   vale a decisão de adequação (Resolução CD/ANPD nº 32/2026); nos demais
 *   destinos o contrato precisa das cláusulas-padrão da ANPD (Resolução
 *   CD/ANPD nº 19/2024) — o sistema exige o registro do mecanismo antes de
 *   pôr o provedor num papel do assistente.
 * - Bloqueado para pacientes: Gemini API (termos do Google proíbem uso em
 *   prática clínica). Segue utilizável onde não há dado de paciente.
 *   DeepSeek (dados na China, uso para treino) e OpenRouter (repassa a
 *   terceiros) foram retirados da lista pronta em 03/10/2026.
 */
final class ProviderDataPolicy
{
    public const CHECKED_AT = '2026-10-03';

    // Onde o provedor processa os dados.
    public const LOCATION_BR = 'br';

    public const LOCATION_EU = 'eu';

    public const LOCATION_US = 'us';

    public const LOCATION_ANY = 'any';    // sem garantia de região

    public const LOCATION_VARIES = 'varies'; // depende do modelo/rota

    // Base para tratar fora do Brasil.
    public const TRANSFER_NONE = 'none';

    public const TRANSFER_ADEQUACY = 'adequacy';

    public const TRANSFER_CONTRACT = 'contract';

    /**
     * Mecanismos contratuais do art. 33, II que o dono do SaaS pode registrar
     * (o sistema guarda a referência ao documento, nunca o documento):
     * cláusulas-padrão da ANPD (Res. 19/2024), cláusulas específicas ou
     * normas corporativas globais aprovadas pela ANPD.
     */
    public const MECHANISMS = ['standard_clauses', 'specific_clauses', 'corporate_rules'];

    /**
     * @return array{location: string, transfer: string, patients: bool, blocked_reason: ?string}
     */
    public static function profile(AiProvider $provider, ?string $model = null): array
    {
        return match ($provider) {
            AiProvider::OpenAI, AiProvider::Groq => self::make(self::LOCATION_US, self::TRANSFER_CONTRACT),
            // Anthropic: inferência "global" por padrão (qualquer geografia; dados
            // guardados nos EUA). xAI: endpoint padrão sem garantia de região.
            AiProvider::Anthropic, AiProvider::XAi => self::make(self::LOCATION_ANY, self::TRANSFER_CONTRACT),
            AiProvider::Mistral => self::make(self::LOCATION_EU, self::TRANSFER_ADEQUACY),
            AiProvider::Gemini  => self::make(self::LOCATION_ANY, self::TRANSFER_CONTRACT, 'gemini_terms'),
            // Depende do deployment criado no Azure (declarado no .env).
            AiProvider::AzureOpenAI => match (config('ai.providers.azure_openai.data_region')) {
                'br'    => self::make(self::LOCATION_BR, self::TRANSFER_NONE),
                'eu'    => self::make(self::LOCATION_EU, self::TRANSFER_ADEQUACY),
                default => self::make(self::LOCATION_ANY, self::TRANSFER_CONTRACT),
            },
            // "-br-sp": inferência e logs no Brasil; sem sufixo: Google Cloud BR/EUA/UE.
            AiProvider::Maritaca => str_ends_with((string) $model, '-br-sp')
                ? self::make(self::LOCATION_BR, self::TRANSFER_NONE)
                : self::make(self::LOCATION_VARIES, self::TRANSFER_CONTRACT),
        };
    }

    /** Pode receber dado de paciente (papéis do assistente)? */
    public static function allowsPatientData(AiProvider $provider): bool
    {
        return self::profile($provider)['patients'];
    }

    /** Leva dado de paciente para fora do Brasil sem adequação: exige registro do mecanismo. */
    public static function requiresTransferRecord(AiProvider $provider, ?string $model): bool
    {
        $profile = self::profile($provider, $model);

        return $profile['patients'] && $profile['transfer'] === self::TRANSFER_CONTRACT;
    }

    /**
     * Algum modelo deste provedor (na configuração atual) exige o registro? —
     * ex.: Sabiá sem "-br-sp". Permite registrar ANTES de trocar o modelo.
     */
    public static function mayRequireTransferRecord(AiProvider $provider): bool
    {
        return self::requiresTransferRecord($provider, null);
    }

    /**
     * Fontes oficiais conferidas (CHECKED_AT).
     *
     * @return list<string>
     */
    public static function sources(AiProvider $provider): array
    {
        return match ($provider) {
            AiProvider::OpenAI      => ['https://developers.openai.com/api/docs/guides/your-data'],
            AiProvider::Anthropic   => ['https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training', 'https://platform.claude.com/docs/en/manage-claude/data-residency'],
            AiProvider::Gemini      => ['https://ai.google.dev/gemini-api/terms'],
            AiProvider::Mistral     => ['https://help.mistral.ai/en/articles/347629-where-do-you-store-my-data-or-my-organization-s-data'],
            AiProvider::Groq        => ['https://console.groq.com/docs/your-data'],
            AiProvider::XAi         => ['https://docs.x.ai/developers/faq/security'],
            AiProvider::AzureOpenAI => [
                'https://learn.microsoft.com/en-us/azure/foundry/responsible-ai/openai/data-privacy',
                'https://learn.microsoft.com/en-us/azure/foundry/foundry-models/concepts/deployment-types',
            ],
            AiProvider::Maritaca => ['https://www.maritaca.ai/tratamento-de-dados/', 'https://www.maritaca.ai/dpa/'],
        };
    }

    /**
     * @return array{location: string, transfer: string, patients: bool, blocked_reason: ?string}
     */
    private static function make(string $location, string $transfer, ?string $blockedReason = null): array
    {
        return [
            'location'       => $location,
            'transfer'       => $transfer,
            'patients'       => $blockedReason === null,
            'blocked_reason' => $blockedReason,
        ];
    }
}
