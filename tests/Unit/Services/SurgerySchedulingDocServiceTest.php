<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SurgerySchedulingDocService;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('resolveEyeLabel mapeia right/left/both para PT-BR', function () {
    $service = new SurgerySchedulingDocService();

    expect($service->resolveEyeLabel('right'))->toBe('OLHO DIREITO')
        ->and($service->resolveEyeLabel('left'))->toBe('OLHO ESQUERDO')
        ->and($service->resolveEyeLabel('both'))->toBe('AMBOS OS OLHOS');
});

it('resolveEyeLabel é case-insensitive e tolera espaços', function () {
    $service = new SurgerySchedulingDocService();

    expect($service->resolveEyeLabel(' RIGHT '))->toBe('OLHO DIREITO')
        ->and($service->resolveEyeLabel('Left'))->toBe('OLHO ESQUERDO');
});

it('resolveEyeLabel rejeita olho inválido', function () {
    (new SurgerySchedulingDocService())->resolveEyeLabel('OD');
})->throws(InvalidArgumentException::class, 'Olho inválido');

it('resolveTemplateSlug aceita identificadores legacy 1/2/3', function () {
    $service = new SurgerySchedulingDocService();

    expect($service->resolveTemplateSlug('1'))->toBe('pre_operatorio')
        ->and($service->resolveTemplateSlug('2'))->toBe('pos_operatorio')
        ->and($service->resolveTemplateSlug('3'))->toBe('instrucoes_cirurgicas');
});

it('resolveTemplateSlug aceita slugs canônicos', function () {
    $service = new SurgerySchedulingDocService();

    expect($service->resolveTemplateSlug('pre_operatorio'))->toBe('pre_operatorio')
        ->and($service->resolveTemplateSlug('pos_operatorio'))->toBe('pos_operatorio')
        ->and($service->resolveTemplateSlug('instrucoes_cirurgicas'))->toBe('instrucoes_cirurgicas');
});

it('resolveTemplateSlug usa default em null/empty/desconhecido', function () {
    $service = new SurgerySchedulingDocService();

    expect($service->resolveTemplateSlug(null))->toBe('pre_operatorio')
        ->and($service->resolveTemplateSlug(''))->toBe('pre_operatorio')
        ->and($service->resolveTemplateSlug('desconhecido'))->toBe('pre_operatorio');
});

it('buildReplacements monta os 3 placeholders esperados', function () {
    $service = new SurgerySchedulingDocService();

    $result = $service->buildReplacements('right', '15/05/2026', '08:30');

    expect($result)->toBe([
        '{{OLHO_OPERADO}}'  => 'OLHO DIREITO',
        '{{DATA_CIRURGIA}}' => '15/05/2026',
        '{{HORA_CIRURGIA}}' => '08:30',
    ]);
});

it('buildReplacements normaliza date/time nulos para string vazia', function () {
    $service = new SurgerySchedulingDocService();

    $result = $service->buildReplacements('left', null, null);

    expect($result['{{OLHO_OPERADO}}'])->toBe('OLHO ESQUERDO')
        ->and($result['{{DATA_CIRURGIA}}'])->toBe('')
        ->and($result['{{HORA_CIRURGIA}}'])->toBe('');
});

it('expoe eyeKeys e templateKeys p/ uso em validation rules', function () {
    expect(SurgerySchedulingDocService::eyeKeys())
        ->toEqualCanonicalizing(['right', 'left', 'both']);

    expect(SurgerySchedulingDocService::templateKeys())
        ->toEqualCanonicalizing([
            '1', '2', '3',
            'pre_operatorio', 'pos_operatorio', 'instrucoes_cirurgicas',
        ]);
});
