<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Requests\{StoreMedicalRecordRequest, UpdateMedicalRecordRequest};
use App\Services\FormRequestRulesExporter;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('exporta regras client-safe do StoreMedicalRecordRequest', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules)->toBeArray()
        ->and($rules)->toHaveKey('doctor_id')
        ->and($rules['doctor_id']['rules'])->toContain('required')
        ->and($rules['doctor_id']['rules'])->toContain('uuid');
});

it('descarta regras server-only (exists/unique) na exportação', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules['doctor_id']['rules'])->not->toContain('exists')
        ->and($rules['doctor_id']['rules'])->not->toContain('unique');
});

it('preserva params min/max em campos numéricos', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules)->toHaveKey('pachymetry_right')
        ->and($rules['pachymetry_right']['params']['min'])->toBe('0')
        ->and($rules['pachymetry_right']['params']['max'])->toBe('9999');
});

it('preserva max em campos string', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules['main_complaint']['rules'])->toContain('string')
        ->and($rules['main_complaint']['params']['max'])->toBe('5000');
});

it('preserva regras booleanas e nullable', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules['diabetic']['rules'])->toContain('nullable')
        ->and($rules['diabetic']['rules'])->toContain('boolean');
});

it('Update inclui sometimes nas regras', function () {
    $rules = (new FormRequestRulesExporter())->export(new UpdateMedicalRecordRequest());

    expect($rules['main_complaint']['rules'])->toContain('sometimes')
        ->and($rules['main_complaint']['rules'])->toContain('nullable');
});

it('exporta regras nested (diagnosis_cids.*) como entrada separada', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules)->toHaveKey('diagnosis_cids')
        ->and($rules['diagnosis_cids']['rules'])->toContain('array')
        ->and($rules['diagnosis_cids']['params']['max'])->toBe('20');
});

it('exporta range numérico de eixo refrativo', function () {
    $rules = (new FormRequestRulesExporter())->export(new StoreMedicalRecordRequest());

    expect($rules['dynamic_axis_right']['rules'])->toContain('numeric')
        ->and($rules['dynamic_axis_right']['params']['min'])->toBe('0')
        ->and($rules['dynamic_axis_right']['params']['max'])->toBe('180');
});
