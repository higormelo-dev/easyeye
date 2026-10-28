<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DayExtensionService;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('soletra inteiros pequenos em PT-BR', function () {
    $service = new DayExtensionService();

    expect($service->spell(1))->toContain('um')
        ->and($service->spell(2))->toContain('dois')
        ->and($service->spell(3))->toContain('três');
});

it('soletra valores de 2 dígitos', function () {
    $service = new DayExtensionService();

    expect($service->spell(15))->toContain('quinze')
        ->and($service->spell(30))->toContain('trinta');
});

it('retorna "zero" para valores ≤ 0', function () {
    $service = new DayExtensionService();

    expect($service->spell(0))->toBe('zero')
        ->and($service->spell(-5))->toBe('zero');
});

it('format gera string composta "N (extenso) dia(s)" com unidade plural', function () {
    $service = new DayExtensionService();

    expect($service->format(1))->toBe('1 (um) dia');
    expect($service->format(7))->toContain('7 (')
        ->and($service->format(7))->toEndWith(' dias');
});

it('format trata 0/negativo como 1 (atestado nunca tem 0 dias)', function () {
    $service = new DayExtensionService();

    expect($service->format(0))->toBe('1 (um) dia')
        ->and($service->format(-3))->toBe('1 (um) dia');
});
