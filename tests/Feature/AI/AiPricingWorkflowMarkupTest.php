<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiPricingPolicyResolver, AiPricingService, AiPricingSettings};
use App\DTOs\AI\{AiPricingPolicyData, AiProviderResponseData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunMode};
use App\Models\SubscriptionSetting;
use Illuminate\Support\Facades\Http;

/*
 * Markup por ação (plano docs/internal/planos/ai-pricing-lote2-plano.md §3): o global (5x) é o padrão; overrides por
 * workflow (1–20) vivem em system_settings; a fórmula, o toArray e o snapshot da execução usam o markup EFETIVO da ação.
 */

function markupPolicy(array $byWorkflow = ['report_drafting' => 8.0]): AiPricingPolicyData
{
    return new AiPricingPolicyData(exchangeRateBrlUsd: 5.5, markupMultiplier: 5.0, minimumChargeCentsDefault: 10, markupByWorkflow: $byWorkflow);
}

function markupCall(float $usd): AiProviderResponseData
{
    return new AiProviderResponseData(
        provider: AiProvider::OpenAI,
        model: 'gpt-fake-5',
        content: 'ok',
        usage: new AiUsageData(inputTokens: 100, outputTokens: 100, rawCostUsd: $usd),
        latencyMs: 100,
    );
}

beforeEach(fn () => config(['ai.pricing.markup_multiplier' => 5.0, 'ai.pricing.markup_by_workflow' => []]));

describe('política e fórmula', function () {
    it('markupFor devolve o override da ação (1–20) e o global para as demais ou para override inválido', function () {
        $policy = markupPolicy(['report_drafting' => 8.0, 'exam_assistant' => 0.5, 'assistant_chat' => 'abc', 'record_assist' => 25]);

        expect($policy->markupFor('report_drafting'))->toBe(8.0)
            ->and($policy->markupFor('consensus_review'))->toBe(5.0)
            ->and($policy->markupFor('exam_assistant'))->toBe(5.0)
            ->and($policy->markupFor('assistant_chat'))->toBe(5.0)
            ->and($policy->markupFor('record_assist'))->toBe(5.0);
    });

    it('a fórmula usa o markup da ação: US$ 0,0133 × 5,5 × 8 = 58,52 → 59 centavos; com o global dá 37', function () {
        $pricing = app(AiPricingService::class);

        expect($pricing->chargeCentsFromUsd(0.0133, 'report_drafting', markupPolicy()))->toBe(59)
            ->and($pricing->chargeCentsFromUsd(0.0133, 'exam_assistant', markupPolicy()))->toBe(37);
    });

    it('o piso continua vencendo quando o bruto é menor e o override não altera o piso', function () {
        $pricing = app(AiPricingService::class);

        expect($pricing->chargeCentsFromUsd(0.0001, 'report_drafting', markupPolicy()))->toBe(10)
            ->and($pricing->chargeCentsFromUsd(0.0001, 'exam_assistant', markupPolicy()))->toBe(10);
    });

    it('a estimativa congela o markup efetivo da ação no snapshot e no toArray; o run recomputa com o congelado', function () {
        $pricing  = app(AiPricingService::class);
        $estimate = $pricing->calculateActualCharge('report_drafting', AiRunMode::Economy, [markupCall(0.0133)], markupPolicy());

        expect($estimate->chargeCents)->toBe(59)
            ->and($estimate->runPricingAttributes()['markup_multiplier'])->toBe('8.000')
            ->and($estimate->toArray()['markup_multiplier'])->toBe(8.0);

        $run = (new AiRun())->forceFill(['workflow' => 'report_drafting', ...$estimate->runPricingAttributes()]);
        config(['ai.pricing.markup_multiplier' => 12.0]);

        expect($run->pricingPolicy()?->markupFor('report_drafting'))->toBe(8.0)
            ->and($pricing->calculateActualCharge('report_drafting', AiRunMode::Economy, [markupCall(0.0133)], $run->pricingPolicy())->chargeCents)->toBe(59);
    });

    it('normalizeChargeForResponse usa o markup da ação (arredonda para cima, sem piso)', function () {
        $pricing = app(AiPricingService::class);

        // 0,001 × 5,5 × 8 × 100 = 4,4 → 5; com o global 0,001 × 5,5 × 5 × 100 = 2,75 → 3
        expect($pricing->normalizeChargeForResponse(markupCall(0.001), markupPolicy(), 'report_drafting'))->toBe(5)
            ->and($pricing->normalizeChargeForResponse(markupCall(0.001), markupPolicy(), 'exam_assistant'))->toBe(3);
    });
});

describe('AiPricingSettings', function () {
    it('lista os 9 workflows canônicos na ordem do manager e marca os 3 internos (configuráveis, não cobrados)', function () {
        expect(AiPricingSettings::WORKFLOWS)->toBe(['exam_assistant', 'report_drafting', 'consensus_review', 'eye_image_analysis', 'assistant_chat', 'record_assist', 'medicine_posology', 'platform_finance_digest', 'platform_finance_chat'])
            ->and(AiPricingSettings::INTERNAL_WORKFLOWS)->toBe(['medicine_posology', 'platform_finance_digest', 'platform_finance_chat'])
            ->and(app(AiPricingSettings::class)->markupMultiplierFor('medicine_posology'))->toBe(5.0);
    });

    it('grava só overrides válidos, herda o global para o resto, invalida o cache e a política carrega o mapa', function () {
        $settings = app(AiPricingSettings::class);

        expect($settings->markupByWorkflow())->toBe([])
            ->and($settings->markupMultiplierFor('report_drafting'))->toBe(5.0);

        $settings->update(['markup_by_workflow' => ['report_drafting' => '8', 'medicine_posology' => 2.5]]);

        expect($settings->markupByWorkflow())->toBe(['report_drafting' => 8.0, 'medicine_posology' => 2.5])
            ->and($settings->markupMultiplierFor('report_drafting'))->toBe(8.0)
            ->and($settings->markupMultiplierFor('medicine_posology'))->toBe(2.5)
            ->and($settings->markupMultiplierFor('exam_assistant'))->toBe(5.0)
            ->and(app(AiPricingPolicyResolver::class)->current()->markupFor('report_drafting'))->toBe(8.0);

        // Mudar o global alcança quem não tem override (não se grava 5x em cada ação).
        $settings->update(['markup_multiplier' => 6]);

        expect($settings->markupMultiplierFor('exam_assistant'))->toBe(6.0)
            ->and($settings->markupMultiplierFor('report_drafting'))->toBe(8.0);

        // Valor inválido gravado por fora é ignorado na leitura; forgetCache esquece a chave nova.
        SubscriptionSetting::setValue(AiPricingSettings::KEY_MARKUP_BY_WORKFLOW, json_encode(['report_drafting' => 50, 'assistant_chat' => 3]));
        expect($settings->markupByWorkflow())->toBe(['assistant_chat' => 3.0]);

        SubscriptionSetting::query()->where('key', AiPricingSettings::KEY_MARKUP_BY_WORKFLOW)->delete();
        expect($settings->markupByWorkflow())->toBe(['assistant_chat' => 3.0]);
        $settings->forgetCache();
        expect($settings->markupByWorkflow())->toBe([]);
    });

    it('recusa chave desconhecida e valores fora de 1–20 com o erro markup_by_workflow', function (array $map) {
        expect(fn () => app(AiPricingSettings::class)->update(['markup_by_workflow' => $map]))
            ->toThrow(InvalidArgumentException::class, 'markup_by_workflow');
    })->with([
        'desconhecida' => [['foo' => 5]],
        'zero'         => [['report_drafting' => 0]],
        'acima'        => [['report_drafting' => 21]],
        'texto'        => [['report_drafting' => 'abc']],
    ]);
});

describe('AiPricingPolicyResolver', function () {
    it('current() só lê o banco; current(ensureFresh: true) busca a automática do dia uma única vez', function () {
        config(['ai.exchange_rate.auto_fetch' => true]);
        Http::preventStrayRequests();
        $resolver = app(AiPricingPolicyResolver::class);

        expect($resolver->current()->source)->toBe('fallback');

        Http::fake(['api.frankfurter.dev/*' => Http::response(['base' => 'USD', 'date' => now()->toDateString(), 'rates' => ['BRL' => 5.4]])]);
        $policy = $resolver->current(ensureFresh: true);

        expect($policy->source)->toBe('frankfurter')
            ->and($policy->exchangeRateBrlUsd)->toBe(5.4)
            ->and($resolver->current(ensureFresh: true)->source)->toBe('frankfurter')
            ->and($resolver->current()->source)->toBe('frankfurter');

        Http::assertSentCount(1);
    });
});
