<?php

namespace App\Enums\AI;

enum AiProvider: string
{
    case OpenAI    = 'openai';
    case Anthropic = 'anthropic';
    case Gemini    = 'gemini';

    /** Nome de exibição (marca) do provedor. */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI    => 'OpenAI',
            self::Anthropic => 'Anthropic (Claude)',
            self::Gemini    => 'Google (Gemini)',
        };
    }
}
