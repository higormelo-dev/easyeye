<?php

use App\Domains\AI\Contracts\AiModelPriceRepositoryInterface;
use App\Domains\AI\Exceptions\AiModelPriceNotFoundException;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Services\AiPricingService;
use App\DTOs\AI\{AiPricingPolicyData, AiProviderResponseData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunMode};
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Preço da IA em CENTAVOS: `bruto = round(custo_usd × câmbio × markup × 100, HALF_UP)`; `preço = max(1, piso, bruto)`.
 * A política (câmbio, markup, pisos) é um DTO montado na mão — sem banco, sem config.
 */
function pricingPolicy(float $rate = 5.0, float $markup = 5.0, int $minimum = 10, array $byWorkflow = []): AiPricingPolicyData
{
    return new AiPricingPolicyData($rate, $markup, $minimum, $byWorkflow);
}

function gptPricing(): AiPricingService
{
    return new AiPricingService(fakePriceRepository([
        buildPrice(AiProvider::OpenAI, 'gpt-fake-5', 10.0, 20.0, 5.0, 0.001),
    ]));
}

function oneCall(float $usd): array
{
    return [new AiProviderResponseData(
        provider: AiProvider::OpenAI,
        model: 'gpt-fake-5',
        content: 'ok',
        usage: new AiUsageData(inputTokens: 100, outputTokens: 100, rawCostUsd: $usd),
        latencyMs: 100,
    )];
}

test('estimateCharge: custo do provedor × câmbio × markup, em centavos', function () {
    $estimate = gptPricing()->estimateCharge('report_drafting', AiRunMode::Validated, [[
        'provider'     => AiProvider::OpenAI, 'model' => 'gpt-fake-5',
        'input_tokens' => 100_000, 'output_tokens' => 50_000, 'reasoning_tokens' => 20_000, 'tool_calls_count' => 2,
    ]], pricingPolicy());

    // US$ 2,102 × 5,00 × 5,0 = R$ 52,55
    expect(round($estimate->rawCostUsd, 3))->toBe(2.102)
        ->and($estimate->grossChargeCents)->toBe(5255)
        ->and($estimate->chargeCents)->toBe(5255)
        ->and($estimate->minimumApplied)->toBeFalse();
});

test('o arredondamento é HALF_UP no centavo: 0,5 sobe, 0,49 desce', function (float $usd, int $expectedGross) {
    // câmbio 5 × markup 5 × 100 = ×2500: US$ 0,0002 = 0,5 centavo
    $estimate = gptPricing()->calculateActualCharge('x_workflow', AiRunMode::Economy, oneCall($usd), pricingPolicy(minimum: 1));

    expect($estimate->grossChargeCents)->toBe($expectedGross);
})->with([
    '0,5 centavo sobe para 1' => [0.0002, 1],
    '1,5 centavo sobe para 2' => [0.0006, 2],
    '2,5 centavo sobe para 3' => [0.001, 3],
    '0,49 centavo desce a 0'  => [0.000196, 0],
    '1 centavo exato'         => [0.0004, 1],
]);

test('o piso vale quando o bruto fica abaixo: máximo entre 1 centavo, piso global e piso do workflow', function () {
    $estimate = gptPricing()->calculateActualCharge('consensus_review', AiRunMode::Consensus, oneCall(0.001), pricingPolicy(minimum: 10, byWorkflow: ['consensus_review' => 25]));

    expect($estimate->grossChargeCents)->toBe(3)
        ->and($estimate->minimumChargeCents)->toBe(25)
        ->and($estimate->minimumApplied)->toBeTrue()
        ->and($estimate->chargeCents)->toBe(25);
});

test('o piso por workflow nunca reduz o piso global nem passa abaixo de 1 centavo', function () {
    expect(pricingPolicy(minimum: 10, byWorkflow: ['a' => 5])->minimumChargeCentsFor('a'))->toBe(10)
        ->and(pricingPolicy(minimum: 10, byWorkflow: ['a' => 40])->minimumChargeCentsFor('a'))->toBe(40)
        ->and(pricingPolicy(minimum: 0)->minimumChargeCentsFor('qualquer'))->toBe(1);
});

test('acima do piso o bruto é o preço (piso não é somado)', function () {
    $estimate = gptPricing()->calculateActualCharge('exam_assistant', AiRunMode::Economy, oneCall(0.08), pricingPolicy());

    // US$ 0,08 × 5 × 5 = R$ 2,00
    expect($estimate->chargeCents)->toBe(200)->and($estimate->minimumApplied)->toBeFalse();
});

test('câmbio e markup entram na fórmula e ficam no snapshot da estimativa', function () {
    $base   = gptPricing()->calculateActualCharge('x', AiRunMode::Economy, oneCall(0.08), pricingPolicy(rate: 5.0, markup: 5.0));
    $markup = gptPricing()->calculateActualCharge('x', AiRunMode::Economy, oneCall(0.08), pricingPolicy(rate: 5.0, markup: 1.0));
    $rate   = gptPricing()->calculateActualCharge('x', AiRunMode::Economy, oneCall(0.08), pricingPolicy(rate: 6.0, markup: 5.0));

    expect($base->chargeCents)->toBe(200)
        ->and($markup->chargeCents)->toBe(40)
        ->and($rate->chargeCents)->toBe(240)
        ->and($rate->policy->exchangeRateBrlUsd)->toBe(6.0)
        ->and($rate->policy->markupMultiplier)->toBe(5.0);
});

test('sem preço cadastrado a estimativa cai no piso do workflow × modo (1, 2, 3), sem custo USD', function (AiRunMode $mode, int $factor) {
    $estimate = (new AiPricingService(fakePriceRepository([])))->fallbackMinimumCharge('exam_assistant', $mode, pricingPolicy(minimum: 10, byWorkflow: ['exam_assistant' => 30]));

    expect($estimate->chargeCents)->toBe(30 * $factor)
        ->and($estimate->rawCostUsd)->toBe(0.0)
        ->and($estimate->minimumApplied)->toBeTrue();
})->with([
    'economy'   => [AiRunMode::Economy, 1],
    'validated' => [AiRunMode::Validated, 2],
    'consensus' => [AiRunMode::Consensus, 3],
]);

test('calculateActualCharge usa o custo reportado pelo provedor e soma as chamadas antes de arredondar', function () {
    $responses = [
        ...oneCall(0.05),
        new AiProviderResponseData(provider: AiProvider::Anthropic, model: 'claude-fake-sonnet', content: 'ok', usage: new AiUsageData(inputTokens: 80, outputTokens: 40, rawCostUsd: 0.03), latencyMs: 120),
    ];

    $estimate = gptPricing()->calculateActualCharge('report_drafting', AiRunMode::Validated, $responses, pricingPolicy());

    expect(round($estimate->rawCostUsd, 2))->toBe(0.08)->and($estimate->chargeCents)->toBe(200);
});

test('calculateActualCharge usa a tabela de preços quando rawCostUsd não vier do provedor', function () {
    $pricing = new AiPricingService(fakePriceRepository([buildPrice(AiProvider::Anthropic, 'claude-fake-sonnet', 3.0, 12.0, 2.0, 0.0006)]));

    $estimate = $pricing->calculateActualCharge('exam_assistant', AiRunMode::Economy, [new AiProviderResponseData(
        provider: AiProvider::Anthropic,
        model: 'claude-fake-sonnet',
        content: 'ok',
        usage: new AiUsageData(inputTokens: 200_000, outputTokens: 100_000, reasoningTokens: 20_000, toolCallsCount: 1, rawCostUsd: null),
        latencyMs: 120,
    )], pricingPolicy());

    // 0,6 + 1,2 + 0,04 + 0,0006 = US$ 1,8406 → × 25 × 100
    expect(round($estimate->rawCostUsd, 4))->toBe(1.8406)->and($estimate->chargeCents)->toBe(4602);
});

test('raciocínio sem preço próprio é cobrado como saída (0 explícito = gratuito)', function () {
    $semPreco = new AiPricingService(fakePriceRepository([buildPrice(AiProvider::OpenAI, 'gpt-fake-5', 10.0, 20.0, null, 0)]));
    $gratis   = new AiPricingService(fakePriceRepository([buildPrice(AiProvider::OpenAI, 'gpt-fake-5', 10.0, 20.0, 0.0, 0)]));
    $call     = [['provider' => AiProvider::OpenAI, 'model' => 'gpt-fake-5', 'reasoning_tokens' => 1_000_000]];

    expect($semPreco->estimateCharge('x', AiRunMode::Economy, $call, pricingPolicy(rate: 1.0, markup: 1.0, minimum: 1))->chargeCents)->toBe(2000)
        ->and($gratis->estimateCharge('x', AiRunMode::Economy, $call, pricingPolicy(rate: 1.0, markup: 1.0, minimum: 1))->chargeCents)->toBe(1);
});

test('lança exceção quando não encontra preço de model', function () {
    expect(fn () => (new AiPricingService(fakePriceRepository([])))->estimateCharge('x', AiRunMode::Economy, [['provider' => AiProvider::OpenAI, 'model' => 'sem-preco']], pricingPolicy()))
        ->toThrow(AiModelPriceNotFoundException::class);
});

test('o valor por chamada (diagnóstico) arredonda para CIMA e NÃO é o que se cobra', function () {
    $pricing = gptPricing();
    $call    = fn (float $usd) => oneCall($usd)[0];

    // 3 chamadas de 0,5 centavo: diagnóstico = 3 × 1; cobrança da execução = round(1,5) = 2
    expect($pricing->normalizeChargeForResponse($call(0.0002), pricingPolicy()))->toBe(1);

    $execucao = $pricing->calculateActualCharge('x', AiRunMode::Economy, [...oneCall(0.0002), ...oneCall(0.0002), ...oneCall(0.0002)], pricingPolicy(minimum: 1));

    expect($execucao->chargeCents)->toBe(2)->and($execucao->chargeCents)->toBeLessThan(3);
});

test('o valor por chamada é zero quando o custo bruto é nulo ou zero', function () {
    $pricing  = new AiPricingService(fakePriceRepository([]));
    $nullCost = new AiProviderResponseData(provider: AiProvider::OpenAI, model: 'sem-preco', content: 'ok', usage: new AiUsageData(inputTokens: 10, outputTokens: 10, rawCostUsd: null), latencyMs: 90);

    expect($pricing->normalizeChargeForResponse($nullCost, pricingPolicy()))->toBe(0)
        ->and($pricing->normalizeChargeForResponse(oneCall(0.0)[0], pricingPolicy()))->toBe(0);
});

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
    ?float $reasoning,
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
