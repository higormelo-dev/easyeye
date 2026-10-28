<?php

namespace Database\Seeders;

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\AI\AiProvider;
use Illuminate\Database\Seeder;

/**
 * Tabela de preços públicos por milhão de tokens para os provedores LLM
 * suportados. Valores em USD, conforme tabela vigente dos provedores.
 *
 * Atualize `effective_from` ao publicar uma nova tabela: o registro antigo
 * deve receber `effective_until` para preservar histórico de cobrança.
 */
class AiModelPriceSeeder extends Seeder
{
    public function run(): void
    {
        $effectiveFrom = '2026-01-01 00:00:00';

        $prices = [
            // ── OpenAI ──────────────────────────────────────────────────────────
            [
                'provider'                  => AiProvider::OpenAI->value,
                'model'                     => 'gpt-4o',
                'input_usd_per_million'     => 2.50,
                'output_usd_per_million'    => 10.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::OpenAI->value,
                'model'                     => 'gpt-4o-mini',
                'input_usd_per_million'     => 0.15,
                'output_usd_per_million'    => 0.60,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                // Default de config/ai.php (AI_OPENAI_MODEL) — precisa de preço
                // cadastrado ou a liquidação lança AiModelPriceNotFoundException.
                'provider'                  => AiProvider::OpenAI->value,
                'model'                     => 'gpt-5-mini',
                'input_usd_per_million'     => 0.25,
                'output_usd_per_million'    => 2.00,
                'reasoning_usd_per_million' => 2.00,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::OpenAI->value,
                'model'                     => 'o1',
                'input_usd_per_million'     => 15.00,
                'output_usd_per_million'    => 60.00,
                'reasoning_usd_per_million' => 60.00,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::OpenAI->value,
                'model'                     => 'o1-mini',
                'input_usd_per_million'     => 3.00,
                'output_usd_per_million'    => 12.00,
                'reasoning_usd_per_million' => 12.00,
                'tool_call_usd'             => null,
            ],

            // ── Anthropic ──────────────────────────────────────────────────────
            [
                // Topo de linha (Mythos-class).
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-fable-5',
                'input_usd_per_million'     => 10.00,
                'output_usd_per_million'    => 50.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-opus-5',
                'input_usd_per_million'     => 5.00,
                'output_usd_per_million'    => 25.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                // Preco introdutorio virou definitivo (ago/2026).
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-sonnet-5',
                'input_usd_per_million'     => 2.00,
                'output_usd_per_million'    => 10.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-sonnet-4-6',
                'input_usd_per_million'     => 3.00,
                'output_usd_per_million'    => 15.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                // Mais barato — bom para papel de revisor.
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-haiku-4-5',
                'input_usd_per_million'     => 1.00,
                'output_usd_per_million'    => 5.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                // Default de config/ai.php (AI_ANTHROPIC_MODEL).
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-sonnet-4-5',
                'input_usd_per_million'     => 3.00,
                'output_usd_per_million'    => 15.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],

            // ── Google Gemini ──────────────────────────────────────────────────
            [
                // Sucessor do gemini-2.0-flash (aposentado pelo Google em 2026).
                // Preço oficial: ai.google.dev/gemini-api/docs/pricing (standard).
                // Thoughts/raciocínio são cobrados como saída.
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-3.6-flash',
                'input_usd_per_million'     => 1.50,
                'output_usd_per_million'    => 7.50,
                'reasoning_usd_per_million' => 7.50,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-3.5-flash',
                'input_usd_per_million'     => 1.50,
                'output_usd_per_million'    => 9.00,
                'reasoning_usd_per_million' => 9.00,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-3.5-flash-lite',
                'input_usd_per_million'     => 0.30,
                'output_usd_per_million'    => 2.50,
                'reasoning_usd_per_million' => 2.50,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-3.1-flash-lite',
                'input_usd_per_million'     => 0.25,
                'output_usd_per_million'    => 1.50,
                'reasoning_usd_per_million' => 1.50,
                'tool_call_usd'             => null,
            ],
            [
                // Tier ate 200k tokens (prompts clinicos nunca passam disso).
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-3.1-pro-preview',
                'input_usd_per_million'     => 2.00,
                'output_usd_per_million'    => 12.00,
                'reasoning_usd_per_million' => 12.00,
                'tool_call_usd'             => null,
            ],
        ];

        foreach ($prices as $price) {
            AiModelPrice::updateOrCreate(
                [
                    'provider'       => $price['provider'],
                    'model'          => $price['model'],
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'input_usd_per_million'     => $price['input_usd_per_million'],
                    'output_usd_per_million'    => $price['output_usd_per_million'],
                    'reasoning_usd_per_million' => $price['reasoning_usd_per_million'],
                    'tool_call_usd'             => $price['tool_call_usd'],
                    'effective_until'           => null,
                    'active'                    => true,
                ],
            );
        }
    }
}
