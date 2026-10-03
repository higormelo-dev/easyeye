<?php

namespace App\Enums\AI;

/**
 * Provedores de IA que o SaaS sabe usar (lista pronta).
 *
 * OpenAI, Anthropic e Gemini têm integração própria. Os demais falam o
 * protocolo "compatível com OpenAI" (POST /chat/completions) e usam o mesmo
 * driver (OpenAiCompatibleProvider) — incluir outro provedor desse tipo é
 * acrescentar o case aqui + o bloco em config/ai.php e a chave em
 * config/services.php. Chave de API sempre no .env (nunca no banco).
 */
enum AiProvider: string
{
    case OpenAI    = 'openai';
    case Anthropic = 'anthropic';
    case Gemini    = 'gemini';
    case Mistral   = 'mistral';
    case Groq      = 'groq';
    case XAi       = 'xai';
    // Azure OpenAI (Microsoft): mesmos modelos GPT sob contrato/região da Microsoft.
    case AzureOpenAI = 'azure_openai';
    // Maritaca AI (Brasil): modelos Sabiá; versões "-br-sp" processam 100% no Brasil.
    case Maritaca = 'maritaca';

    /** Nome de exibição (marca) do provedor. */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI      => 'OpenAI',
            self::Anthropic   => 'Anthropic (Claude)',
            self::Gemini      => 'Google (Gemini)',
            self::Mistral     => 'Mistral AI',
            self::Groq        => 'Groq',
            self::XAi         => 'xAI (Grok)',
            self::AzureOpenAI => 'Azure OpenAI (Microsoft)',
            self::Maritaca    => 'Maritaca AI (Sabiá)',
        };
    }

    /** Usa o driver genérico "compatível com OpenAI" (sem integração própria). */
    public function isOpenAiCompatible(): bool
    {
        return ! in_array($this, [self::OpenAI, self::Anthropic, self::Gemini], true);
    }

    /** Variável do .env com a chave de API (lida em config/services.php). */
    public function apiKeyEnv(): string
    {
        return strtoupper($this->value) . '_API_KEY';
    }

    /** Prefixo das demais variáveis do provedor no .env (AI_MISTRAL_MODEL...). */
    public function envPrefix(): string
    {
        return 'AI_' . strtoupper($this->value) . '_';
    }

    /** Onde o dono do SaaS gera a chave de API (link do "Como configurar"). */
    public function keysUrl(): string
    {
        return match ($this) {
            self::OpenAI      => 'https://platform.openai.com/api-keys',
            self::Anthropic   => 'https://console.anthropic.com/settings/keys',
            self::Gemini      => 'https://aistudio.google.com/apikey',
            self::Mistral     => 'https://console.mistral.ai/api-keys',
            self::Groq        => 'https://console.groq.com/keys',
            self::XAi         => 'https://console.x.ai',
            self::AzureOpenAI => 'https://ai.azure.com',
            self::Maritaca    => 'https://plataforma.maritaca.ai',
        };
    }

    /** Nome do provedor no catálogo de preços LiteLLM (campo litellm_provider). */
    public function litellmProvider(): string
    {
        return match ($this) {
            self::AzureOpenAI => 'azure',
            default           => $this->value,
        };
    }
}
