<?php

declare(strict_types=1);

use App\Enums\FeatureKey;
use App\Models\PlanFeature;

/**
 * Rótulo dos limites no card de planos (PlanFeature::formatForDisplay):
 * plural e separador de milhar no idioma — antes "Até 1 médico(s)" e
 * "Até 10000 pacientes", inclusive no site em inglês.
 */
function planLimitLabel(FeatureKey $feature, int $value, string $locale): string
{
    app()->setLocale($locale);

    return PlanFeature::factory()->limit($feature, $value)->make()->formatForDisplay();
}

it('usa singular e plural conforme o limite', function () {
    expect(planLimitLabel(FeatureKey::MaxDoctors, 1, 'pt_BR'))->toBe('Até 1 médico')
        ->and(planLimitLabel(FeatureKey::MaxDoctors, 3, 'pt_BR'))->toBe('Até 3 médicos')
        ->and(planLimitLabel(FeatureKey::MaxDoctors, 1, 'en'))->toBe('Up to 1 doctor')
        ->and(planLimitLabel(FeatureKey::MaxDoctors, 3, 'en'))->toBe('Up to 3 doctors');
});

it('formata o número com o separador de milhar do idioma', function () {
    expect(planLimitLabel(FeatureKey::MaxPatients, 10000, 'pt_BR'))->toBe('Até 10.000 pacientes')
        ->and(planLimitLabel(FeatureKey::MaxPatients, 10000, 'en'))->toBe('Up to 10,000 patients');
});

it('zero continua significando ilimitado, ou "sem créditos" para a IA', function () {
    expect(planLimitLabel(FeatureKey::MaxPatients, 0, 'pt_BR'))->toBe(trans('subscriptions.features.max_patients_unlimited', [], 'pt_BR'))
        ->and(planLimitLabel(FeatureKey::AiMonthlyCredits, 0, 'pt_BR'))->toBe(trans('subscriptions.features.ai_credits_none', [], 'pt_BR'))
        ->and(planLimitLabel(FeatureKey::AiMonthlyCredits, 1, 'pt_BR'))->toBe('1 crédito de IA por mês');
});
