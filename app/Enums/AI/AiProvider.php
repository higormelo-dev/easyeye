<?php

namespace App\Enums\AI;

enum AiProvider: string
{
    case OpenAI    = 'openai';
    case Anthropic = 'anthropic';
    case Gemini    = 'gemini';
}
