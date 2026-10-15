<?php

declare(strict_types=1);

/**
 * Feature de plano `has_patient_recall` (retorno de pacientes crônicos — Fase 6):
 * a chave, o que o seeder entrega por plano, o backfill dos planos já existentes
 * e o que o FeatureGateService faz com ela.
 */

use App\Enums\FeatureKey;
use App\Models\{Entity, Plan, PlanFeature, Subscription};
use App\Services\FeatureGateService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;

function prfBackfill(): object
{
    return require base_path('database/migrations/2026_10_22_000000_backfill_patient_recall_feature.php');
}

it('a chave é booleana, tem rótulo traduzido e não conta como recurso de IA', function () {
    $feature = FeatureKey::HasPatientRecall;

    expect($feature->value)->toBe('has_patient_recall')
        ->and($feature->isBoolean())->toBeTrue()
        ->and($feature->isNumeric())->toBeFalse()
        ->and($feature->isMonthlyReset())->toBeFalse()
        // IA fica indisponível com o acesso limitado pela régua de cobrança; o recall não é IA.
        ->and($feature->isAi())->toBeFalse();

    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        expect($feature->label())->not->toBe('subscriptions.features.has_patient_recall')
            ->and($feature->label())->not->toBeEmpty();
    }
});

it('o PlanSeeder entrega o recall no Pro e no Premium e não no Básico', function () {
    $this->seed(PlanSeeder::class);

    $value = fn (string $slug) => PlanFeature::query()
        ->where('plan_id', Plan::query()->where('slug', $slug)->value('id'))
        ->where('feature', FeatureKey::HasPatientRecall->value)
        ->value('value');

    expect($value('basico'))->toBe('0')
        ->and($value('pro'))->toBe('1')
        ->and($value('premium'))->toBe('1');
});

it('o backfill liga o recall nos planos pro/premium e desliga nos demais, sem tocar no que já existe', function () {
    $make = fn (string $slug) => Plan::factory()->create(['slug' => $slug]);

    $basic   = $make('basico');
    $pro     = $make('pro');
    $premium = $make('premium');
    $custom  = $make('plano-sob-medida');

    // O dono já tinha decidido desligar o recall no Premium antes do backfill rodar.
    PlanFeature::create(['plan_id' => $premium->id, 'feature' => FeatureKey::HasPatientRecall->value, 'value' => '0']);

    prfBackfill()->up();

    $value = fn (Plan $plan) => PlanFeature::query()
        ->where('plan_id', $plan->id)
        ->where('feature', FeatureKey::HasPatientRecall->value)
        ->pluck('value')
        ->all();

    expect($value($basic))->toBe(['0'])
        ->and($value($pro))->toBe(['1'])
        ->and($value($premium))->toBe(['0'])
        ->and($value($custom))->toBe(['0']);

    // Rodar de novo não duplica nem muda nada.
    prfBackfill()->up();

    expect(PlanFeature::query()->where('feature', FeatureKey::HasPatientRecall->value)->count())->toBe(4)
        ->and($value($pro))->toBe(['1']);

    prfBackfill()->down();

    expect(PlanFeature::query()->where('feature', FeatureKey::HasPatientRecall->value)->count())->toBe(0);
});

it('o FeatureGateService libera só quando o plano da clínica tem o recall ligado', function (?string $planValue, bool $allowed) {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $plan   = Plan::factory()->create();

    if ($planValue !== null) {
        PlanFeature::create(['id' => (string) Str::uuid(), 'plan_id' => $plan->id, 'feature' => FeatureKey::HasPatientRecall->value, 'value' => $planValue]);
    }

    Subscription::factory()->create(['entity_id' => $entity->id, 'plan_id' => $plan->id]);

    expect(app(FeatureGateService::class)->can((string) $entity->id, FeatureKey::HasPatientRecall))->toBe($allowed);
})->with([
    'ligado'          => ['1', true],
    'desligado'       => ['0', false],
    'não configurado' => [null, false],
]);
