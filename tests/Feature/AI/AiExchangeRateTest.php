<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiExchangeRate, AiProviderTopup, AiRun};
use App\Domains\AI\Services\{AiExchangeRateService, AiPricingPolicyResolver, AiPricingSettings, AiUsdBrlRate};
use App\Enums\AI\AiProvider;
use App\Models\{Entity, User};
use Carbon\CarbonImmutable;

/**
 * Câmbio US$→R$ da precificação de IA: cotação manual do manager (histórico append-only, com autor e data) > última recarga de
 * provedor > fallback 5,50 sinalizado. Execução já precificada guarda a cotação que usou. Plano: ai-credit-packs-brl §1.2.
 */
function topup(float $rate, string $at): AiProviderTopup
{
    return AiProviderTopup::query()->create([
        'provider'      => AiProvider::OpenAI->value, 'amount_usd' => 100, 'amount_brl' => 100 * $rate,
        'exchange_rate' => $rate, 'topped_up_at' => $at,
    ]);
}

it('sem nenhuma cotação vale o fallback 5,50, sinalizado como estimativa', function () {
    $current = app(AiExchangeRateService::class)->current();

    expect($current['rate'])->toBe(5.5)
        ->and($current['source'])->toBe('fallback')
        ->and($current['is_fallback'])->toBeTrue()
        ->and($current['exchange_rate_id'])->toBeNull();
});

it('sem cotação manual vale a última recarga de provedor, como legacy_topup', function () {
    topup(5.10, '2026-09-01 10:00:00');
    topup(5.80, '2026-10-01 10:00:00');

    $current = app(AiExchangeRateService::class)->current();

    expect($current['rate'])->toBe(5.8)->and($current['source'])->toBe('legacy_topup')->and($current['is_fallback'])->toBeFalse();
});

it('a cotação manual tem precedência sobre a recarga, grava autor e fica no histórico (append-only)', function () {
    topup(5.80, '2026-10-01 10:00:00');
    $author = User::factory()->create();
    $rates  = app(AiExchangeRateService::class);

    $first  = $rates->record(5.40, CarbonImmutable::now()->subHour(), (string) $author->id, 'cotação do dia');
    $second = $rates->record(5.55, CarbonImmutable::now()->subMinute(), (string) $author->id);

    $current = $rates->current();

    expect($current['rate'])->toBe(5.55)
        ->and($current['source'])->toBe('manual')
        ->and($current['recorded_by'])->toBe((string) $author->id)
        ->and($current['exchange_rate_id'])->toBe((string) $second->id)
        ->and(AiExchangeRate::query()->count())->toBe(2)
        ->and((float) $first->fresh()->rate_brl_per_usd)->toBe(5.4)
        ->and($rates->history()->pluck('id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $second->id, (string) $first->id]);
});

it('cotação com data futura só vale quando chegar', function () {
    $author = User::factory()->create();
    $rates  = app(AiExchangeRateService::class);

    $rates->record(5.20, CarbonImmutable::now()->subDay(), (string) $author->id);
    $rates->record(6.00, CarbonImmutable::now()->addDay(), (string) $author->id);

    expect($rates->current()['rate'])->toBe(5.2);

    $this->travel(2)->days();

    expect($rates->current()['rate'])->toBe(6.0);
});

it('recusa cotação fora da faixa 1–20 (erro de digitação)', function (float $rate) {
    expect(fn () => app(AiExchangeRateService::class)->record($rate, CarbonImmutable::now(), (string) User::factory()->create()->id))
        ->toThrow(InvalidArgumentException::class, 'ai_exchange_rate_out_of_range');

    expect(AiExchangeRate::query()->count())->toBe(0);
})->with(['0,5' => [0.5], '55 (digitou 5,5)' => [55.0], 'negativa' => [-5.0]]);

it('AiUsdBrlRate é a fachada: mantém rate(), current() e FALLBACK para o P&L e o catálogo', function () {
    expect(AiUsdBrlRate::FALLBACK)->toBe(5.5)->and(app(AiUsdBrlRate::class)->rate())->toBe(5.5)
        ->and(app(AiUsdBrlRate::class)->current()['is_fallback'])->toBeTrue();

    app(AiExchangeRateService::class)->record(5.9, CarbonImmutable::now()->subMinute(), (string) User::factory()->create()->id);

    $current = app(AiUsdBrlRate::class)->current();

    expect($current['rate'])->toBe(5.9)->and($current['is_fallback'])->toBeFalse()->and($current['source'])->toBe('manual');
});

it('a política vigente junta câmbio, markup e pisos; system_settings vence o config e valores inválidos voltam ao config', function () {
    app(AiExchangeRateService::class)->record(5.0, CarbonImmutable::now()->subMinute(), (string) User::factory()->create()->id);

    $policy = app(AiPricingPolicyResolver::class)->current();

    expect($policy->exchangeRateBrlUsd)->toBe(5.0)->and($policy->markupMultiplier)->toBe(5.0)
        ->and($policy->minimumChargeCentsDefault)->toBe(10)->and($policy->source)->toBe('manual');

    app(AiPricingSettings::class)->update(['markup_multiplier' => 7.5, 'minimum_charge_cents_default' => 25, 'minimum_charge_cents_by_workflow' => ['exam_assistant' => 40]]);

    $policy = app(AiPricingPolicyResolver::class)->current();

    expect($policy->markupMultiplier)->toBe(7.5)->and($policy->minimumChargeCentsFor('exam_assistant'))->toBe(40)
        ->and($policy->minimumChargeCentsFor('report_drafting'))->toBe(25);

    expect(fn () => app(AiPricingSettings::class)->update(['markup_multiplier' => 25.0]))->toThrow(InvalidArgumentException::class, 'markup_multiplier')
        ->and(fn () => app(AiPricingSettings::class)->update(['markup_multiplier' => 0.5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(AiPricingSettings::class)->update(['minimum_charge_cents_default' => 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(AiPricingSettings::class)->update(['custom_min_cents' => 200000, 'custom_max_cents' => 100000]))->toThrow(InvalidArgumentException::class, 'custom_limits');
});

it('execução já precificada guarda a cotação do momento: mudar o câmbio depois não a reprecifica', function () {
    $entity = Entity::factory()->create();
    $run    = AiRun::factory()->create([
        'entity_id'            => $entity->id, 'exchange_rate_brl_usd' => '5.000000', 'markup_multiplier' => '5.000',
        'minimum_charge_cents' => 10, 'pricing_source' => 'manual',
    ]);

    app(AiExchangeRateService::class)->record(9.0, CarbonImmutable::now()->subMinute(), (string) User::factory()->create()->id);

    $frozen = $run->fresh()->pricingPolicy();

    expect($frozen->exchangeRateBrlUsd)->toBe(5.0)
        ->and($frozen->markupMultiplier)->toBe(5.0)
        ->and(app(AiPricingPolicyResolver::class)->current()->exchangeRateBrlUsd)->toBe(9.0)
        ->and(AiRun::factory()->create(['entity_id' => $entity->id])->pricingPolicy())->toBeNull();
});
