<?php

declare(strict_types=1);

use App\Enums\ActivationStep;
use Illuminate\Support\Facades\App;

/**
 * Etapas do card "Configure sua clínica" (Dashboard): o rótulo vem das
 * traduções — em inglês o card não pode misturar texto fixo em português.
 */
it('toda etapa tem rótulo traduzido em pt e en', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        foreach (ActivationStep::cases() as $step) {
            $key = "dashboard.activation_steps.{$step->value}";

            expect(trans()->has($key, $locale, false))->toBeTrue("{$locale}: falta {$key}");
        }
    }
});

it('label() segue o idioma atual', function () {
    App::setLocale('en');
    expect(ActivationStep::FirstPatientAdded->label())->toBe('First patient registered');

    App::setLocale('pt_BR');
    expect(ActivationStep::FirstPatientAdded->label())->toBe(trans('dashboard.activation_steps.first_patient_added', [], 'pt_BR'))
        ->not->toBe('First patient registered');
});
