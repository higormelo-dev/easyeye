<?php

declare(strict_types=1);

use App\Enums\Eye;
use Tests\TestCase;

uses(TestCase::class);

it('lê a lateralidade do exame: 1 = OD, 2 = OE; 0, nulo e qualquer outro valor = AO', function () {
    expect(Eye::fromLaterality(1))->toBe(Eye::Right)
        ->and(Eye::fromLaterality(2))->toBe(Eye::Left)
        ->and(Eye::fromLaterality(0))->toBe(Eye::Both)
        ->and(Eye::fromLaterality(null))->toBe(Eye::Both)
        ->and(Eye::fromLaterality(3))->toBe(Eye::Both)
        ->and(Eye::fromLaterality(-1))->toBe(Eye::Both);
});

it('lê o olho do procedimento: right|left|both; vazio ou desconhecido é procedimento SEM olho', function () {
    expect(Eye::fromProcedureEye('right'))->toBe(Eye::Right)
        ->and(Eye::fromProcedureEye('left'))->toBe(Eye::Left)
        ->and(Eye::fromProcedureEye('both'))->toBe(Eye::Both)
        ->and(Eye::fromProcedureEye('  RIGHT '))->toBe(Eye::Right)
        ->and(Eye::fromProcedureEye(null))->toBeNull()
        ->and(Eye::fromProcedureEye(''))->toBeNull()
        ->and(Eye::fromProcedureEye('od'))->toBeNull();
});

it('o código inteiro de ida e volta fecha: laterality() é o inverso de fromLaterality()', function () {
    foreach (Eye::cases() as $eye) {
        expect(Eye::fromLaterality($eye->laterality()))->toBe($eye);
    }

    expect(Eye::Right->laterality())->toBe(1)
        ->and(Eye::Left->laterality())->toBe(2)
        ->and(Eye::Both->laterality())->toBe(0);
});

it('cobertura: AO cobre qualquer olho; OD só OD; OE só OE; exigência de AO só é coberta por AO', function () {
    $matrix = [
        // ato registrado em => [exigência OD, exigência OE, exigência AO]
        'right' => [true, false, false],
        'left'  => [false, true, false],
        'both'  => [true, true, true],
    ];

    foreach ($matrix as $performed => $expected) {
        foreach ([Eye::Right, Eye::Left, Eye::Both] as $i => $required) {
            expect(Eye::from($performed)->covers($required))->toBe($expected[$i], "{$performed} cobre {$required->value}");
        }
    }
});

it('components decompõe AO em OD + OE e deixa OD/OE como são', function () {
    expect(Eye::Both->components())->toBe([Eye::Right, Eye::Left])
        ->and(Eye::Right->components())->toBe([Eye::Right])
        ->and(Eye::Left->components())->toBe([Eye::Left]);
});

it('isLateral: só OD e OE', function () {
    expect(Eye::Right->isLateral())->toBeTrue()
        ->and(Eye::Left->isLateral())->toBeTrue()
        ->and(Eye::Both->isLateral())->toBeFalse();
});

it('o rótulo vem das siglas traduzidas de eye_images (OD/OE/AO em português; OD/OS/OU em inglês)', function () {
    app()->setLocale('pt_BR');
    expect(Eye::Right->label())->toBe('OD')
        ->and(Eye::Left->label())->toBe('OE')
        ->and(Eye::Both->label())->toBe('AO');

    app()->setLocale('en');
    expect(Eye::Right->label())->toBe('OD')
        ->and(Eye::Left->label())->toBe('OS')
        ->and(Eye::Both->label())->toBe('OU');
});
