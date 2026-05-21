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
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-3-5-sonnet-20241022',
                'input_usd_per_million'     => 3.00,
                'output_usd_per_million'    => 15.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-3-5-haiku-20241022',
                'input_usd_per_million'     => 0.80,
                'output_usd_per_million'    => 4.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Anthropic->value,
                'model'                     => 'claude-3-opus-20240229',
                'input_usd_per_million'     => 15.00,
                'output_usd_per_million'    => 75.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],

            // ── Google Gemini ──────────────────────────────────────────────────
            [
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-1.5-pro',
                'input_usd_per_million'     => 1.25,
                'output_usd_per_million'    => 5.00,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-1.5-flash',
                'input_usd_per_million'     => 0.075,
                'output_usd_per_million'    => 0.30,
                'reasoning_usd_per_million' => null,
                'tool_call_usd'             => null,
            ],
            [
                'provider'                  => AiProvider::Gemini->value,
                'model'                     => 'gemini-2.0-flash',
                'input_usd_per_million'     => 0.10,
                'output_usd_per_million'    => 0.40,
                'reasoning_usd_per_million' => null,
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
                ]
            );
        }
    }
}
