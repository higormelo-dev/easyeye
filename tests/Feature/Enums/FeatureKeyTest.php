<?php

use App\Enums\FeatureKey;

// ── isBoolean ────────────────────────────────────────────────────────────────

test('features booleanas retornam isBoolean true', function () {
    expect(FeatureKey::HasAiExamAssistant->isBoolean())->toBeTrue();
    expect(FeatureKey::HasAiReportDrafting->isBoolean())->toBeTrue();
    expect(FeatureKey::HasAiConsensus->isBoolean())->toBeTrue();
    expect(FeatureKey::HasApiIntegrator->isBoolean())->toBeTrue();
});

test('features numéricas retornam isBoolean false', function () {
    expect(FeatureKey::MaxUsers->isBoolean())->toBeFalse();
    expect(FeatureKey::MaxPatients->isBoolean())->toBeFalse();
    expect(FeatureKey::MaxDoctors->isBoolean())->toBeFalse();
    expect(FeatureKey::AiMonthlyCredits->isBoolean())->toBeFalse();
    expect(FeatureKey::ApiMonthlyExamSends->isBoolean())->toBeFalse();
});

// ── isMonthlyReset ───────────────────────────────────────────────────────────

test('AiMonthlyCredits tem reset mensal', function () {
    expect(FeatureKey::AiMonthlyCredits->isMonthlyReset())->toBeTrue();
});

test('ApiMonthlyExamSends tem reset mensal', function () {
    expect(FeatureKey::ApiMonthlyExamSends->isMonthlyReset())->toBeTrue();
});

test('features não-mensais retornam isMonthlyReset false', function () {
    expect(FeatureKey::MaxUsers->isMonthlyReset())->toBeFalse();
    expect(FeatureKey::MaxPatients->isMonthlyReset())->toBeFalse();
    expect(FeatureKey::MaxDoctors->isMonthlyReset())->toBeFalse();
    expect(FeatureKey::HasApiIntegrator->isMonthlyReset())->toBeFalse();
    expect(FeatureKey::HasAiExamAssistant->isMonthlyReset())->toBeFalse();
});

// ── valores das chaves ───────────────────────────────────────────────────────

test('ApiMonthlyExamSends tem a chave correta no banco', function () {
    expect(FeatureKey::ApiMonthlyExamSends->value)->toBe('api_monthly_exam_sends');
});

test('HasApiIntegrator tem a chave correta no banco', function () {
    expect(FeatureKey::HasApiIntegrator->value)->toBe('has_api_integrator');
});

// ── label ────────────────────────────────────────────────────────────────────

test('label retorna string não-vazia para todos os casos', function () {
    foreach (FeatureKey::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});
