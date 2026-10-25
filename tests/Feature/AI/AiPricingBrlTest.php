<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiExchangeRate, AiProviderTopup};
use App\Domains\AI\Services\{AiCreditPurchaseService, AiCreditWalletService, AiExchangeRateService, AiPricingPolicyResolver, AiPricingService, AiPricingSettings};
use App\Domains\AI\Support\AiLegacyCreditConverter;
use App\DTOs\AI\AiPricingPolicyData;
use App\Enums\AI\AiRunMode;
use App\Enums\{ClientRule, FeatureKey, SaasRule, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Pacotes de IA em reais (plano docs/internal/planos/ai-credit-packs-brl-plano.md): fórmula custo × câmbio × markup,
 * câmbio manual com histórico, pacote personalizado validado no servidor, conversão de saldos legados e a regra que
 * mais importa para o negócio: a clínica NUNCA vê custo do provedor, câmbio, markup ou margem.
 */

const AI_PRICING_FORBIDDEN_KEYS = [
    'raw_cost_usd', 'cost_usd', 'cost_usd_with_margin', 'margin_multiplier', 'usd_per_credit', 'margin', 'gross_margin',
    'exchange_rate', 'exchange_rate_brl_usd', 'provider_cost', 'revenue_estimate', 'pricing_source', 'markup_multiplier',
    'gross_charge_cents', 'cost_brl', 'markup_by_workflow', 'markup_effective', 'effective_markup', 'markup_for_workflow', 'workflow_examples', 'internal_workflows',
];

/** @return list<string> caminhos onde apareceu chave proibida */
function aiPricingForbiddenPaths(mixed $value, string $path = ''): array
{
    if (! is_array($value)) {
        return [];
    }

    $found = [];

    foreach ($value as $key => $child) {
        $here = $path === '' ? (string) $key : "{$path}.{$key}";

        if (is_string($key) && in_array($key, AI_PRICING_FORBIDDEN_KEYS, true)) {
            $found[] = $here;
        }

        $found = [...$found, ...aiPricingForbiddenPaths($child, $here)];
    }

    return $found;
}

function aiPricingClinic(): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $plan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 3000)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);
    $admin  = User::factory()->create();
    $member = createEntityUser($entity, $admin, ClientRule::Admin->value, isOwner: true);
    app(AiCreditWalletService::class)->purchaseCredits(entityId: $entity->id, amount: 50000, description: 'Saldo inicial');

    return [$entity, $admin, $member];
}

describe('fórmula do preço', function () {
    it('cobra custo × câmbio × markup em centavos, HALF_UP, com piso', function () {
        $pricing = app(AiPricingService::class);
        $policy  = new AiPricingPolicyData(exchangeRateBrlUsd: 5.0, markupMultiplier: 5.0, minimumChargeCentsDefault: 10, minimumChargeCentsByWorkflow: ['exam_assistant' => 30]);

        // US$ 0,0133 × 5 × 5 × 100 = 33,25 → 33
        expect($pricing->chargeCentsFromUsd(0.0133, 'report_drafting', $policy))->toBe(33)
            // borda 0,5 sobe: 0,0135 × 25 × 100 = 33,75 → 34; 0,0134 × 25 × 100 = 33,5 → 34 (HALF_UP)
            ->and($pricing->chargeCentsFromUsd(0.0134, 'report_drafting', $policy))->toBe(34)
            // abaixo do piso global
            ->and($pricing->chargeCentsFromUsd(0.00001, 'report_drafting', $policy))->toBe(10)
            // piso do workflow vence
            ->and($pricing->chargeCentsFromUsd(0.00001, 'exam_assistant', $policy))->toBe(30)
            // sem custo: piso × modo
            ->and($pricing->fallbackMinimumCharge('exam_assistant', AiRunMode::Consensus, $policy)->chargeCents)->toBe(90);
    });

    it('markup e pisos vêm de system_settings com precedência sobre o config, dentro da faixa', function () {
        config(['ai.pricing.markup_multiplier' => 5.0]);
        $settings = app(AiPricingSettings::class);

        expect($settings->markupMultiplier())->toBe(5.0);

        $settings->update(['markup_multiplier' => 7.5, 'minimum_charge_cents_default' => 25, 'custom_min_cents' => 150000, 'custom_max_cents' => 500000]);

        expect($settings->markupMultiplier())->toBe(7.5)
            ->and($settings->minimumChargeCentsDefault())->toBe(25)
            ->and($settings->customMinCents())->toBe(150000)
            ->and($settings->customMaxCents())->toBe(500000)
            ->and(app(AiPricingPolicyResolver::class)->current()->markupMultiplier)->toBe(7.5);

        expect(fn () => $settings->update(['markup_multiplier' => 50]))->toThrow(InvalidArgumentException::class, 'markup_multiplier')
            ->and(fn () => $settings->update(['custom_min_cents' => 600000]))->toThrow(InvalidArgumentException::class, 'custom_limits')
            ->and(fn () => $settings->update(['custom_min_cents' => 150050]))->toThrow(InvalidArgumentException::class, 'custom_min_cents');
    });
});

describe('câmbio', function () {
    it('sem cotação nem recarga usa o fallback sinalizado; a recarga de provedor vem antes; a manual vence tudo', function () {
        $rates = app(AiExchangeRateService::class);

        expect($rates->current())->toMatchArray(['rate' => 5.5, 'source' => 'fallback', 'is_fallback' => true]);

        AiProviderTopup::query()->create(['provider' => 'openai', 'amount_usd' => 100, 'amount_brl' => 520, 'exchange_rate' => 5.2, 'topped_up_at' => now()->subDays(10)]);

        expect($rates->current())->toMatchArray(['rate' => 5.2, 'source' => 'legacy_topup', 'is_fallback' => false]);

        $user = User::factory()->create();
        $rates->record(5.75, now()->subHour(), (string) $user->id, 'PTAX de ontem');
        // Cotação agendada para o futuro ainda não vale.
        $rates->record(6.10, now()->addDay(), (string) $user->id);

        expect($rates->current())->toMatchArray(['rate' => 5.75, 'source' => 'manual', 'recorded_by_name' => $user->name, 'note' => 'PTAX de ontem'])
            ->and(AiExchangeRate::query()->count())->toBe(2)
            ->and(fn () => $rates->record(55.0, now(), (string) $user->id))->toThrow(InvalidArgumentException::class);
    });
});

describe('pacote personalizado', function () {
    it('resolve o valor no servidor: pacote fixo não aceita valor; personalizado só entre os limites e em reais inteiros', function () {
        $purchases = app(AiCreditPurchaseService::class);

        expect($purchases->resolvePackage('premium')['value_cents'])->toBe(49900)
            ->and($purchases->resolvePackage('custom', 150000)['value_cents'])->toBe(150000)
            ->and(fn () => $purchases->resolvePackage('premium', 150000))->toThrow(DomainException::class, 'ai_credit_custom_amount_not_allowed')
            ->and(fn () => $purchases->resolvePackage('custom'))->toThrow(DomainException::class, 'ai_credit_custom_amount_required')
            ->and(fn () => $purchases->resolvePackage('custom', 99900))->toThrow(DomainException::class, 'ai_credit_custom_amount_invalid')
            ->and(fn () => $purchases->resolvePackage('custom', 1000001))->toThrow(DomainException::class, 'ai_credit_custom_amount_invalid')
            ->and(fn () => $purchases->resolvePackage('custom', 150050))->toThrow(DomainException::class, 'ai_credit_custom_amount_invalid')
            ->and(fn () => $purchases->resolvePackage('starter'))->toThrow(DomainException::class, 'ai_credit_package_not_found');

        $custom = collect($purchases->packages())->firstWhere('code', 'custom');

        expect($custom)->toMatchArray(['custom' => true, 'value_cents' => null, 'custom_min_cents' => 100000, 'custom_max_cents' => 1000000, 'custom_step_cents' => 100])
            ->and(collect($purchases->packages())->pluck('code')->all())->toBe(['basic', 'premium', 'enterprise', 'custom']);
    });

    it('config de limites inválido (teto acima de R$ 100.000) cai no padrão sem passar do teto duro', function () {
        config(['ai.credit_purchases.custom_package.max_cents' => 20000000]);

        expect(app(AiPricingSettings::class)->customMaxCents())->toBeLessThanOrEqual(AiPricingSettings::CUSTOM_HARD_MAX_CENTS)
            ->and(app(AiCreditPurchaseService::class)->customAmountValid(15000000))->toBeFalse();
    });

    it('a compra pendente credita exatamente o valor pago (1:1) e o pedido aberto é por pacote + valor', function () {
        [$entity]  = aiPricingClinic();
        $purchases = app(AiCreditPurchaseService::class);

        $fixed  = $purchases->createPendingPurchase(entityId: (string) $entity->id, packageCode: 'basic', source: 'checkout');
        $custom = $purchases->createPendingPurchase(entityId: (string) $entity->id, packageCode: 'custom', source: 'checkout', customAmountCents: 250000);

        expect($fixed->wallet_credit_cents)->toBe(10000)->and($fixed->amount_cents)->toBe(10000)->and($fixed->credits)->toBeNull()
            ->and($custom->wallet_credit_cents)->toBe(250000)->and($custom->amount_cents)->toBe(250000)
            ->and($purchases->presentPurchase($custom))->toMatchArray(['package_code' => 'custom', 'wallet_credit_cents' => 250000, 'wallet_credit_formatted' => 'R$ 2.500,00']);
    });
});

describe('conversão dos saldos legados', function () {
    it('converte pelo preço médio pago, cota a R$ 1,00 por crédito, grava a trilha e é idempotente', function () {
        $paid   = Entity::factory()->create(['is_client' => true]);
        $noBuy  = Entity::factory()->create(['is_client' => true]);
        $wallet = fn (Entity $e) => DB::table('ai_credit_wallets')->where('entity_id', $e->id)->first();

        foreach ([[$paid, 100, 20, 30, 10], [$noBuy, 40, 0, 30, 0]] as [$entity, $balance, $reserved, $quota, $used]) {
            DB::table('ai_credit_wallets')->insert([
                'id'                             => (string) Str::uuid(), 'entity_id' => $entity->id, 'balance' => $balance, 'reserved_balance' => $reserved,
                'lifetime_purchased'             => $balance + $reserved, 'lifetime_consumed' => 0, 'monthly_quota' => $quota, 'monthly_quota_used' => $used,
                'monthly_quota_lifetime_granted' => $quota, 'lifetime_consumed_openai' => 0, 'lifetime_consumed_anthropic' => 0, 'lifetime_consumed_gemini' => 0,
                'created_at'                     => now(), 'updated_at' => now(),
            ]);
        }

        // Dois pacotes antigos pagos: 25 créditos por R$ 69,90 e 100 por R$ 249,90 → R$ 319,80 / 125 = 255,84 centavos/crédito.
        foreach ([[25, 6990], [100, 24990]] as [$credits, $cents]) {
            DB::table('ai_credit_purchases')->insert([
                'id'       => (string) Str::uuid(), 'entity_id' => $paid->id, 'package_code' => 'legacy', 'credits' => $credits, 'amount_cents' => $cents,
                'currency' => 'BRL', 'status' => 'credited', 'description' => 'legado', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Estorno parcial antigo (em créditos) na compra de 100: 50 já revogados.
        DB::table('ai_credit_purchases')->where('entity_id', $paid->id)->where('credits', 100)
            ->update(['metadata' => json_encode(['partial_refund' => ['handled_credits' => 50, 'revoked_credits' => 50, 'shortfall' => 0]])]);

        // Carteira NATIVA em centavos (criada pelo código novo): o conversor nunca pode tocá-la.
        $native = Entity::factory()->create(['is_client' => true]);
        app(AiCreditWalletService::class)->purchaseCredits(entityId: (string) $native->id, amount: 12345);

        $report = AiLegacyCreditConverter::fromConfig()->convertAll();

        expect($report['wallets'])->toBe(2)->and($report['purchases'])->toBe(2)
            ->and((int) $wallet($native)->balance_cents)->toBe(12345)
            ->and(json_decode((string) $wallet($native)->legacy_conversion, true)['method'])->toBe('native_brl');

        $rawMeta = json_decode((string) DB::table('ai_credit_purchases')->where('entity_id', $paid->id)->where('credits', 100)->value('metadata'), true);
        $partial = (is_string($rawMeta) ? json_decode($rawMeta, true) : $rawMeta)['partial_refund'];
        expect($partial)->toMatchArray(['handled_credits' => 50, 'revoked_credits' => 50, 'handled_cents' => 12792, 'revoked_cents' => 12792, 'legacy_unit' => 'legacy_credit']);

        $w = $wallet($paid);
        expect((int) $w->balance_cents)->toBe(25584)              // 100 × 255,84
            ->and((int) $w->reserved_balance_cents)->toBe(5117)    // 20 × 255,84 = 5116,8 → HALF_UP
            ->and((int) $w->monthly_quota_cents)->toBe(3000)
            ->and((int) $w->monthly_quota_used_cents)->toBe(1000)
            ->and(json_decode((string) $w->legacy_conversion, true))->toMatchArray(['method' => 'purchase_average', 'factor_cents_per_credit' => 255.84]);

        $n = $wallet($noBuy);
        expect((int) $n->balance_cents)->toBe(8000)                // 40 × R$ 2,00
            ->and((int) $n->monthly_quota_cents)->toBe(3000)
            ->and(json_decode((string) $n->legacy_conversion, true)['method'])->toBe('default');

        expect(DB::table('ai_credit_purchases')->where('entity_id', $paid->id)->where('credits', 100)->value('wallet_credit_cents'))->toBe(25584)
            ->and(AiLegacyCreditConverter::fromConfig()->reconcile())->toBe([]);

        // Reexecutar não converte de novo — nem a legada, nem a nativa.
        expect(AiLegacyCreditConverter::fromConfig()->convertAll()['wallets'])->toBe(0)
            ->and((int) $wallet($paid)->balance_cents)->toBe(25584)
            ->and((int) $wallet($native)->balance_cents)->toBe(12345);
    });
});

describe('a clínica não vê a conta', function () {
    it('nenhuma rota do painel devolve custo do provedor, câmbio, markup ou margem', function () {
        [$entity, $admin, $member] = aiPricingClinic();

        $index = $this->actingAs($admin)->withSession(panelSession($member))
            ->get(route('panel.ai-runs.index'), inertiaHeaders())
            ->assertOk()
            ->json();

        $estimate = $this->actingAs($admin)->withSession(panelSession($member))
            ->postJson(route('panel.ai-runs.estimate'), baseRunPayload())
            ->assertOk()
            ->json();

        $packs = $this->actingAs($admin)->withSession(panelSession($member))
            ->getJson(route('panel.my-subscription.ai-credits.options', ['package_code' => 'custom', 'custom_amount_cents' => 150000]))
            ->assertOk()
            ->json();

        expect(aiPricingForbiddenPaths($index))->toBe([])
            ->and(aiPricingForbiddenPaths($estimate))->toBe([])
            ->and(aiPricingForbiddenPaths($packs))->toBe([])
            ->and($estimate['estimate'])->toHaveKeys(['charge_cents', 'charge_formatted'])
            ->and($estimate['balance'])->toHaveKey('available_cents')
            ->and(data_get($packs, 'data.package.value_cents'))->toBe(150000)
            ->and(json_encode($index))->not->toContain('raw_cost_usd');
    });

    it('o manager, e só ele, carrega o markup por ação e a conta por ação (prova de que o detector enxerga o lado proibido)', function () {
        $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $admin = User::factory()->create();
        createEntityUser($saas, $admin, SaasRule::Admin->value);

        $props = $this->actingAs($admin)
            ->withSession(['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => SaasRule::Admin->value])
            ->get(route('manager.ai-pricing.index'))
            ->assertOk()
            ->viewData('page')['props'];

        expect(aiPricingForbiddenPaths($props))->toContain('settings.markup_by_workflow', 'settings.internal_workflows', 'workflow_examples', 'workflow_examples.0.markup_multiplier', 'examples.0.exchange_rate_brl_usd');
    });

    it('o detector de vazamento enxerga chave proibida em qualquer profundidade', function () {
        expect(aiPricingForbiddenPaths(['props' => ['analytics' => ['runs' => [['markup_multiplier' => 5]]]]]))->toBe(['props.analytics.runs.0.markup_multiplier'])
            ->and(aiPricingForbiddenPaths(['props' => ['internal_workflows' => ['medicine_posology']]]))->toBe(['props.internal_workflows'])
            ->and(aiPricingForbiddenPaths(['charge_cents' => 10, 'balance' => ['available_cents' => 1]]))->toBe([]);
    });
});
