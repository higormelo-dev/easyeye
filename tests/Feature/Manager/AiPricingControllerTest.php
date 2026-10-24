<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiExchangeRate;
use App\Domains\AI\Services\{AiExchangeRateService, AiPricingSettings};
use App\Enums\SaasRule;
use App\Models\{AuditLog, Entity, User};
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

/*
 * Manager → IA → Precificação: só dono/admin financeiro do SaaS; toda escrita exige motivo, é auditada e o histórico de
 * câmbio é append-only. É a ÚNICA tela onde custo do provedor, câmbio e markup aparecem (plano ai-credit-packs-brl §4).
 */

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true, 'name' => 'EasyEye']);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
});

function pricingSession(Entity $saas, string $rule): array
{
    return ['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule];
}

function asPricingUser(?User $user = null, string $rule = SaasRule::Admin->value)
{
    return test()->actingAs($user ?? test()->admin)->withSession(pricingSession(test()->saas, $rule));
}

it('mostra câmbio (com aviso de fallback), markup, pisos, pacotes e a conta de exemplo', function () {
    config(['ai.pricing.markup_multiplier' => 5.0]);

    $props = asPricingUser()->get(route('manager.ai-pricing.index'))->assertOk()->viewData('page')['props'];

    expect($props['rate'])->toMatchArray(['rate' => 5.5, 'source' => 'fallback', 'is_fallback' => true])
        ->and($props['settings'])->toMatchArray(['markup_multiplier' => 5.0, 'minimum_charge_cents_default' => 10, 'custom_min_cents' => 100000, 'custom_max_cents' => 1000000])
        ->and(collect($props['packages'])->pluck('code')->all())->toBe(['basic', 'premium', 'enterprise', 'custom'])
        // US$ 1,00 × 5,50 × 5 = R$ 27,50
        ->and(collect($props['examples'])->firstWhere('raw_cost_usd', 1.0))->toMatchArray(['charge_cents' => 2750, 'charge_formatted' => 'R$ 27,50', 'minimum_applied' => false])
        ->and($props['history']['data'])->toBe([]);
});

it('salva markup e pisos com motivo, dentro da faixa, e audita; fora da faixa é 422 com o campo', function () {
    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 6.5, 'minimum_charge_cents_default' => 20, 'reason' => 'Reajuste do custo de infra'])
        ->assertOk()
        ->assertJsonPath('settings.markup_multiplier', 6.5)
        ->assertJsonPath('settings.minimum_charge_cents_default', 20);

    expect(app(AiPricingSettings::class)->markupMultiplier())->toBe(6.5)
        ->and(AuditLog::query()->where('event', 'manager.ai_pricing.settings_updated')->exists())->toBeTrue();

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 50, 'reason' => 'Tentativa fora da faixa'])
        ->assertStatus(422)
        ->assertJsonPath('field', 'markup_multiplier');

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 4])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect(app(AiPricingSettings::class)->markupMultiplier())->toBe(6.5);
});

it('grava cotação manual com autor, data e observação; histórico só cresce; fora da faixa é 422', function () {
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5.25, 'note' => 'PTAX 22/10', 'reason' => 'Cotação do dia informada'])
        ->assertCreated()
        ->assertJsonPath('rate.rate', 5.25)
        ->assertJsonPath('rate.source', 'manual')
        ->assertJsonPath('history_first.recorded_by_name', $this->admin->name);

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5.40, 'reason' => 'Correção da cotação'])
        ->assertCreated();

    expect(app(AiExchangeRateService::class)->current()['rate'])->toBe(5.4)
        ->and(AiExchangeRate::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'manager.ai_pricing.exchange_rate_recorded')->count())->toBe(2);

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 55, 'reason' => 'Erro de digitação'])
        ->assertStatus(422)
        ->assertJsonPath('field', 'rate_brl_per_usd');

    expect(AiExchangeRate::query()->count())->toBe(2);
});

it('só dono ou admin financeiro do SaaS entra; financeiro sem ser dono e suporte não', function () {
    $owner = User::factory()->create();
    createEntityUser($this->saas, $owner, SaasRule::Financial->value, isOwner: true);
    asPricingUser($owner, SaasRule::Financial->value)->get(route('manager.ai-pricing.index'))->assertOk();

    $financial = User::factory()->create();
    createEntityUser($this->saas, $financial, SaasRule::Financial->value);
    asPricingUser($financial, SaasRule::Financial->value)->get(route('manager.ai-pricing.index'))->assertForbidden();
    asPricingUser($financial, SaasRule::Financial->value)->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 2, 'reason' => 'Tentativa sem permissão'])->assertForbidden();

    $support = User::factory()->create();
    createEntityUser($this->saas, $support, SaasRule::Support->value);
    asPricingUser($support, SaasRule::Support->value)->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5, 'reason' => 'Tentativa sem permissão'])->assertForbidden();

    expect(AiExchangeRate::query()->count())->toBe(0);
});

it('sem a linha de auditoria nada entra em vigor: markup e cotação são desfeitos', function () {
    config(['ai.pricing.markup_multiplier' => 5.0]);
    // AuditLogger que "engole" a falha de escrita (comportamento real quando audit_logs está indisponível).
    // Durante a auditoria (ainda dentro da transação) o cache compartilhado NÃO pode conter o markup novo (Sentinela S3).
    $this->mock(AuditLogger::class)->shouldReceive('recordAdminAction')->andReturnUsing(function (): void {
        expect(Cache::has('subscription_setting:' . AiPricingSettings::KEY_MARKUP))->toBeFalse();
    });

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 9, 'reason' => 'Sem trilha de auditoria'])
        ->assertStatus(500);
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 6.0, 'reason' => 'Sem trilha de auditoria'])
        ->assertStatus(500);

    expect(app(AiPricingSettings::class)->markupMultiplier())->toBe(5.0)
        ->and(AiExchangeRate::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'like', 'manager.ai_pricing.%')->count())->toBe(0);
});
