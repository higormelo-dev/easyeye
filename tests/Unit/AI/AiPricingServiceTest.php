<?php

use App\Domains\AI\Contracts\AiModelPriceRepositoryInterface;
use App\Domains\AI\Exceptions\AiModelPriceNotFoundException;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Services\AiPricingService;
use App\DTOs\AI\{AiProviderResponseData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunMode};
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('ai.pricing.margin_multiplier', 2.0);
    config()->set('ai.pricing.usd_per_credit', 0.01);
    config()->set('ai.pricing.minimum_credits_default', 1);
    config()->set('ai.pricing.minimum_credits_by_workflow', [
        'exam_assistant'   => 3,
        'report_drafting'  => 2,
        'consensus_review' => 5,
    ]);
});

test('estimateCredits calcula custo por tabela de preços e normaliza créditos', function () {
    $pricing = new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::OpenAI, 'gpt-fake-5', 10.0, 20.0, 5.0, 0.001),
    ]));

    $estimate = $pricing->estimateCredits(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        providerEstimates: [[
            'provider'         => AiProvider::OpenAI,
            'model'            => 'gpt-fake-5',
            'input_tokens'     => 100_000,
            'output_tokens'    => 50_000,
            'reasoning_tokens' => 20_000,
            'tool_calls_count' => 2,
        ]],
    );

    expect(round($estimate->rawCostUsd, 3))->toBe(2.102);
    expect(round($estimate->costUsdWithMargin, 3))->toBe(4.204);
    expect($estimate->creditsBeforeMinimum)->toBe(421);
    expect($estimate->minimumApplied)->toBeFalse();
    expect($estimate->normalizedCredits)->toBe(421);
});

test('estimateCredits aplica crédito mínimo por workflow', function () {
    $pricing = new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::Gemini, 'gemini-fake-pro', 0.1, 0.1, 0, 0),
    ]));

    $estimate = $pricing->estimateCredits(
        workflow: 'consensus_review',
        mode: AiRunMode::Consensus,
        providerEstimates: [[
            'provider'      => AiProvider::Gemini->value,
            'model'         => 'gemini-fake-pro',
            'input_tokens'  => 10,
            'output_tokens' => 10,
        ]],
    );

    expect($estimate->creditsBeforeMinimum)->toBe(1);
    expect($estimate->minimumCredits)->toBe(5);
    expect($estimate->minimumApplied)->toBeTrue();
    expect($estimate->normalizedCredits)->toBe(5);
});

test('calculateActualCredits usa custo reportado pelo provider quando disponível', function () {
    $pricing = new AiPricingService(fakePriceRepository([]));

    $responses = [
        new AiProviderResponseData(
            provider: AiProvider::OpenAI,
            model: 'gpt-fake-5',
            content: 'ok',
            usage: new AiUsageData(inputTokens: 100, outputTokens: 100, rawCostUsd: 0.05),
            latencyMs: 100,
        ),
        new AiProviderResponseData(
            provider: AiProvider::Anthropic,
            model: 'claude-fake-sonnet',
            content: 'ok',
            usage: new AiUsageData(inputTokens: 80, outputTokens: 40, rawCostUsd: 0.03),
            latencyMs: 120,
        ),
    ];

    $estimate = $pricing->calculateActualCredits(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        providerResponses: $responses,
    );

    expect(round($estimate->rawCostUsd, 2))->toBe(0.08);
    expect(round($estimate->costUsdWithMargin, 2))->toBe(0.16);
    expect($estimate->normalizedCredits)->toBe(16);
    expect($estimate->minimumApplied)->toBeFalse();
});

test('calculateActualCredits usa tabela de preços quando rawCostUsd não vier do provider', function () {
    $pricing = new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::Anthropic, 'claude-fake-sonnet', 3.0, 12.0, 2.0, 0.0006),
    ]));

    $responses = [
        new AiProviderResponseData(
            provider: AiProvider::Anthropic,
            model: 'claude-fake-sonnet',
            content: 'ok',
            usage: new AiUsageData(
                inputTokens: 200_000,
                outputTokens: 100_000,
                reasoningTokens: 20_000,
                toolCallsCount: 1,
                rawCostUsd: null,
            ),
            latencyMs: 120,
        ),
    ];

    $estimate = $pricing->calculateActualCredits(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        providerResponses: $responses,
    );

    expect(round($estimate->rawCostUsd, 4))->toBe(1.8406);
    expect($estimate->breakdown[0]['source'])->toBe('price_table_fallback');
    expect($estimate->normalizedCredits)->toBe(369);
});

test('lança exceção quando não encontra preço de model', function () {
    $pricing = new AiPricingService(fakePriceRepository([]));

    expect(function () use ($pricing): void {
        $pricing->estimateCredits(
            workflow: 'report_drafting',
            mode: AiRunMode::Validated,
            providerEstimates: [[
                'provider'     => AiProvider::OpenAI,
                'model'        => 'model-inexistente',
                'input_tokens' => 1000,
            ]],
        );
    })->toThrow(AiModelPriceNotFoundException::class);
});

test('estimateCredits soma múltiplas chamadas (gerador + revisor) em modo validated', function () {
    $pricing = new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::OpenAI, 'gpt-fake-5', 10.0, 20.0, 0, 0),
        buildPrice(AiProvider::Anthropic, 'claude-fake-sonnet', 3.0, 12.0, 0, 0),
    ]));

    $estimate = $pricing->estimateCredits(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        providerEstimates: [
            [
                'provider'      => AiProvider::OpenAI,
                'model'         => 'gpt-fake-5',
                'input_tokens'  => 50_000,
                'output_tokens' => 25_000,
            ],
            [
                'provider'      => AiProvider::Anthropic,
                'model'         => 'claude-fake-sonnet',
                'input_tokens'  => 60_000,
                'output_tokens' => 20_000,
            ],
        ],
    );

    // OpenAI: 0.5 + 0.5 = 1.0
    // Anthropic: 0.18 + 0.24 = 0.42
    // total: 1.42
    expect(round($estimate->rawCostUsd, 4))->toBe(1.42);
    expect($estimate->breakdown)->toHaveCount(2);
    expect($estimate->breakdown[0]['provider'])->toBe(AiProvider::OpenAI->value);
    expect($estimate->breakdown[1]['provider'])->toBe(AiProvider::Anthropic->value);
    expect(round($estimate->costUsdWithMargin, 4))->toBe(2.84);
    expect($estimate->creditsBeforeMinimum)->toBe(284);
    expect($estimate->normalizedCredits)->toBe(284);
});

test('calculateActualCredits mistura custos reportados e fallback para tabela', function () {
    $pricing = new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::Gemini, 'gemini-fake-pro', 1.0, 4.0, 0, 0),
    ]));

    $responses = [
        // Primeira: provider reportou custo. Não consulta tabela.
        new AiProviderResponseData(
            provider: AiProvider::OpenAI,
            model: 'gpt-fake-5',
            content: 'ok',
            usage: new AiUsageData(inputTokens: 1000, outputTokens: 500, rawCostUsd: 0.20),
            latencyMs: 100,
        ),
        // Segunda: provider não reportou custo. Fallback para tabela.
        new AiProviderResponseData(
            provider: AiProvider::Gemini,
            model: 'gemini-fake-pro',
            content: 'ok',
            usage: new AiUsageData(inputTokens: 1_000_000, outputTokens: 500_000, rawCostUsd: null),
            latencyMs: 90,
        ),
    ];

    $estimate = $pricing->calculateActualCredits(
        workflow: 'exam_assistant',
        mode: AiRunMode::Validated,
        providerResponses: $responses,
    );

    // Gemini: 1.0 (input) + 2.0 (output) = 3.0
    // Total reportado: 0.20 + 3.0 = 3.20
    expect(round($estimate->rawCostUsd, 4))->toBe(3.20);
    expect($estimate->breakdown[0]['source'])->toBe('provider_reported');
    expect($estimate->breakdown[1]['source'])->toBe('price_table_fallback');
    expect(round($estimate->costUsdWithMargin, 4))->toBe(6.40);
    expect($estimate->normalizedCredits)->toBe(640);
});

test('margem zero zera custo com margem mas mantém piso do workflow', function () {
    config()->set('ai.pricing.margin_multiplier', 0.0);

    $pricing = new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::OpenAI, 'gpt-fake-5', 10.0, 20.0, 0, 0),
    ]));

    $estimate = $pricing->estimateCredits(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        providerEstimates: [[
            'provider'      => AiProvider::OpenAI,
            'model'         => 'gpt-fake-5',
            'input_tokens'  => 100_000,
            'output_tokens' => 50_000,
        ]],
    );

    expect($estimate->marginMultiplier)->toBe(0.0);
    expect($estimate->costUsdWithMargin)->toBe(0.0);
    expect($estimate->creditsBeforeMinimum)->toBe(0);
    expect($estimate->minimumApplied)->toBeTrue();
    expect($estimate->normalizedCredits)->toBe(2); // mínimo do workflow report_drafting
});

test('normalizeCreditsForResponse aplica margem, conversão e arredondamento para cima', function () {
    config()->set('ai.pricing.margin_multiplier', 2.0);
    config()->set('ai.pricing.usd_per_credit', 0.01);

    $pricing  = new AiPricingService(fakePriceRepository([]));
    $response = new AiProviderResponseData(
        provider: AiProvider::OpenAI,
        model: 'gpt-fake-5',
        content: 'ok',
        usage: new AiUsageData(rawCostUsd: 0.011),
        latencyMs: 100,
    );

    // (0.011 * 2.0) / 0.01 = 2.2 => ceil = 3 créditos
    $normalized = $pricing->normalizeCreditsForResponse($response);

    expect($normalized)->toBe(3);
});

test('normalizeCreditsForResponse retorna zero quando custo bruto é nulo ou zero', function () {
    $pricing = new AiPricingService(fakePriceRepository([]));

    $responseWithNullCost = new AiProviderResponseData(
        provider: AiProvider::Gemini,
        model: 'gemini-fake-pro',
        content: 'ok',
        usage: new AiUsageData(rawCostUsd: null),
        latencyMs: 80,
    );

    $responseWithZeroCost = new AiProviderResponseData(
        provider: AiProvider::Anthropic,
        model: 'claude-fake-sonnet',
        content: 'ok',
        usage: new AiUsageData(rawCostUsd: 0.0),
        latencyMs: 90,
    );

    expect($pricing->normalizeCreditsForResponse($responseWithNullCost))->toBe(0);
    expect($pricing->normalizeCreditsForResponse($responseWithZeroCost))->toBe(0);
});

/**
 * @param list<AiModelPrice> $prices
 */
function fakePriceRepository(array $prices): AiModelPriceRepositoryInterface
{
    return new class($prices) implements AiModelPriceRepositoryInterface {
        /**
         * @param list<AiModelPrice> $prices
         */
        public function __construct(private readonly array $prices)
        {
        }

        public function findActive(AiProvider $provider, string $model): ?AiModelPrice
        {
            foreach ($this->prices as $price) {
                if ($price->provider === $provider && $price->model === $model && $price->active === true) {
                    return $price;
                }
            }

            return null;
        }
    };
}

function buildPrice(
    AiProvider $provider,
    string $model,
    float $input,
    float $output,
    float $reasoning,
    float $toolCall,
): AiModelPrice {
    return new AiModelPrice([
        'provider'                  => $provider->value,
        'model'                     => $model,
        'input_usd_per_million'     => $input,
        'output_usd_per_million'    => $output,
        'reasoning_usd_per_million' => $reasoning,
        'tool_call_usd'             => $toolCall,
        'effective_from'            => now()->subDay(),
        'active'                    => true,
    ]);
}
