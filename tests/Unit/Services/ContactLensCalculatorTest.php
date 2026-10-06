<?php

declare(strict_types=1);

use App\Services\{ContactLensCalculator, ContactLensFormatter};
use Tests\TestCase;

uses(TestCase::class);

/**
 * Lente de contato v2: refração → cálculo → potência de LC SUGERIDA na grade
 * real das lentes. O servidor RECALCULA o que a tela mostrou — mesmos números
 * do contactLens.js: os dois lados leem tests/fixtures/contact_lens_v2_cases.json
 * (esperados calculados por uma referência independente em aritmética decimal).
 */
function clv2Fixture(): array
{
    static $fixture;

    return $fixture ??= json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/contact_lens_v2_cases.json'), true);
}

function clv2Eye(float|int|string|null $sphere, float|int|string|null $cylinder = null, int|string|null $axis = null, array $options = []): ?array
{
    return (new ContactLensCalculator())->calculateEye(
        ['sphere' => $sphere, 'cylinder' => $cylinder, 'axis' => $axis],
        ['vertex_distance_mm' => 12, ...$options],
    );
}

dataset('clv2_cases', fn () => collect(clv2Fixture()['cases'])->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])->all());

it('perfis, avisos e constantes são os do fixture (e, por ele, os do JS)', function () {
    $fixture = clv2Fixture();

    expect(ContactLensCalculator::PROFILES)->toEqual($fixture['profiles'])
        ->and(ContactLensCalculator::NOTES)->toBe($fixture['note_codes'])
        ->and(ContactLensCalculator::VERSION)->toBe($fixture['version'])
        ->and(ContactLensCalculator::VERTEX_THRESHOLD)->toEqual($fixture['vertex_threshold'])
        ->and(ContactLensCalculator::TORIC_MIN_CYLINDER)->toEqual($fixture['toric_min_cylinder']);
});

it('vetor compartilhado com o JS', function (array $case) {
    expect((new ContactLensCalculator())->calculateEye($case['input']['eye'], $case['input']))->toEqual($case['expected']);
})->with('clv2_cases');

it('grade do esférico, cilindro de estoque e eixo (empates e bordas) iguais ao JS', function () {
    $calc     = new ContactLensCalculator();
    $fixture  = clv2Fixture();
    $profiles = ContactLensCalculator::PROFILES;

    foreach ($fixture['sphere_grid_cases'] as $c) {
        expect($calc->snapSphere((float) $c['value'], $profiles[$c['profile']][$c['lens']]['sphere_ranges']))
            ->toEqual($c['expected'], "esférico {$c['profile']}/{$c['lens']} {$c['value']}");
    }

    foreach ($fixture['cylinder_cases'] as $c) {
        expect($calc->snapCylinder((float) $c['value'], $profiles[$c['profile']]['toric']['cylinders']))
            ->toEqual($c['expected'], "cilindro {$c['profile']} {$c['value']}");
    }

    foreach ($fixture['axis_cases'] as $c) {
        expect($calc->snapAxis($c['axis'], $c['step']))->toBe($c['expected'], "eixo {$c['axis']} passo {$c['step']}");
    }
});

it('vértice só acima de ±4,00 D (o -2,43 da tela antiga vinha de aplicar abaixo de 4 D)', function () {
    expect(clv2Eye(-2.5))->toMatchArray(['vertex_applied' => false, 'suggested' => ['sphere' => -2.5, 'cylinder' => null, 'axis' => null]])
        ->and(clv2Eye(-4)['vertex_applied'])->toBeFalse()
        ->and(clv2Eye(-4.5))->toMatchArray(['vertex_applied' => true])
        ->and(clv2Eye(-4.5)['theoretical']['se'])->toBe(-4.27)
        ->and(clv2Eye(-4.5)['suggested']['sphere'])->toBe(-4.25);
});

it('vértice em CADA meridiano: -5,00 -2,00 × 180 → -4,72 / -1,74 → tórica -4,75 / -1,75 × 180', function () {
    expect(clv2Eye(-5, -2, 180))->toBe([
        'type'           => 'toric',
        'theoretical'    => ['sphere' => -4.72, 'cylinder' => -1.74, 'axis' => 180, 'se' => -5.59],
        'suggested'      => ['sphere' => -4.75, 'cylinder' => -1.75, 'axis' => 180],
        'vertex_applied' => true,
        'notes'          => [],
    ]);
});

it('cilindro positivo é transposto (S + C, −C, eixo ± 90; 0 vale 180)', function () {
    expect(clv2Eye(1, 1.5, 90)['theoretical'])->toBe(['sphere' => 2.5, 'cylinder' => -1.5, 'axis' => 180, 'se' => 1.75])
        ->and(clv2Eye(1, 1.5, 30)['theoretical']['axis'])->toBe(120)
        ->and(clv2Eye(-2, -1, 0)['theoretical']['axis'])->toBe(180)
        ->and(clv2Eye(-2, -1, '180º')['theoretical']['axis'])->toBe(180);
});

it('empates: grade de 0,50 → mais positivo; cilindro → menor magnitude; eixo → sobe', function () {
    expect(clv2Eye(-6.76)['suggested']['sphere'])->toBe(-6.0) // teórico -6,25
        ->and(clv2Eye(1, 1.5, 90)['suggested']['cylinder'])->toBe(-1.25) // -1,50 entre -1,25 e -1,75
        ->and(clv2Eye(-2, -1, 175)['suggested'])->toBe(['sphere' => -2.0, 'cylinder' => -0.75, 'axis' => 180]);
});

it('modos: automático, esférica (força o EE) e tórica (sem cilindro → esférica com aviso)', function () {
    expect(clv2Eye(-2, -0.5, 90)['type'])->toBe('spherical')
        ->and(clv2Eye(-2.5, -1.25, 180)['type'])->toBe('toric')
        ->and(clv2Eye(-2.5, -1.25, 180, ['lens_mode' => 'spherical'])['suggested'])->toBe(['sphere' => -3.0, 'cylinder' => null, 'axis' => null])
        ->and(clv2Eye(-2, -0.5, 90, ['lens_mode' => 'toric'])['suggested'])->toBe(['sphere' => -2.0, 'cylinder' => -0.75, 'axis' => 90])
        ->and(clv2Eye(-3, null, null, ['lens_mode' => 'toric']))->toMatchArray(['type' => 'spherical', 'notes' => ['no_cylinder']]);
});

it('perfis e fora de faixa: sem sugestão do componente + aviso; a faixa estendida resolve', function () {
    expect(clv2Eye(-16))->toMatchArray(['suggested' => null, 'notes' => ['out_of_range']])
        ->and(clv2Eye(-16, null, null, ['profile' => 'extended'])['suggested']['sphere'])->toBe(-13.5)
        ->and(clv2Eye(-4, -4, 10))->toMatchArray([
            'suggested' => ['sphere' => -3.75, 'cylinder' => null, 'axis' => 10],
            'notes'     => ['cylinder_out_of_range'],
        ])
        ->and(clv2Eye(-4, -4, 10, ['profile' => 'extended'])['suggested'])->toBe(['sphere' => -3.75, 'cylinder' => -3.25, 'axis' => 10])
        ->and(clv2Eye(-14)['suggested']['sphere'])->toBe(-12.0); // -11,99 está dentro da faixa padrão
});

it('tórica sem eixo: sugere esférico/cilindro e pede o eixo; plano avisa', function () {
    expect(clv2Eye(-2.5, -1.25))->toMatchArray([
        'suggested' => ['sphere' => -2.5, 'cylinder' => -1.25, 'axis' => null],
        'notes'     => ['axis_missing'],
    ])
        ->and(clv2Eye(0.12))->toMatchArray(['suggested' => ['sphere' => 0.0, 'cylinder' => null, 'axis' => null], 'notes' => ['plano_check']]);
});

it('grava version 2 com entradas + resultados; nada digitado não grava nada (null)', function () {
    $r = (new ContactLensCalculator())->calculate([
        'vertex_distance_mm' => '12',
        'profile'            => 'standard',
        'lens_mode'          => 'auto',
        'od'                 => ['sphere' => '-5,00', 'cylinder' => -2, 'axis' => '180º'],
        'oe'                 => ['sphere' => -2.5, 'cylinder' => '', 'axis' => null],
    ]);

    expect($r)->toEqual([
        'version'            => 2,
        'vertex_distance_mm' => 12.0,
        'profile'            => 'standard',
        'lens_mode'          => 'auto',
        'od'                 => ['sphere' => -5.0, 'cylinder' => -2.0, 'axis' => 180],
        'oe'                 => ['sphere' => -2.5, 'cylinder' => null, 'axis' => null],
        'results'            => ['od' => clv2Eye(-5, -2, 180), 'oe' => clv2Eye(-2.5)],
    ]);

    $calc = new ContactLensCalculator();
    expect($calc->calculate([]))->toBeNull()
        ->and($calc->calculate(null))->toBeNull()
        ->and($calc->calculate(['vertex_distance_mm' => 12, 'od' => ['sphere' => '  ', 'cylinder' => -1]]))->toBeNull()
        ->and($calc->calculate(['od' => 'lixo', 'oe' => ['sphere' => -1]])['results']['od'])->toBeNull();
});

it('resultado enviado pelo navegador é ignorado; distância vazia/0, perfil e modo desconhecidos → padrão', function () {
    $r = (new ContactLensCalculator())->calculate([
        'vertex_distance_mm' => 0,
        'profile'            => 'premium',
        'lens_mode'          => 'magic',
        'od'                 => ['sphere' => -6],
        'results'            => ['od' => ['suggested' => ['sphere' => -99]]],
    ]);

    expect($r['vertex_distance_mm'])->toBe(12.0)
        ->and($r['profile'])->toBe('standard')
        ->and($r['lens_mode'])->toBe('auto')
        ->and($r['results']['od']['suggested']['sphere'])->toBe(-5.5);
});

it('paridade com a tela: cilindro inválido não gera resultado (nunca ignora o cilindro); eixo fracionado = não informado', function () {
    $calc = new ContactLensCalculator();

    expect($calc->calculateEye(['sphere' => -2, 'cylinder' => 'abc']))->toBeNull()
        ->and($calc->calculateEye(['sphere' => -2, 'cylinder' => -1, 'axis' => 90.5])['notes'])->toBe(['axis_missing']);
});

it('regras de validação das entradas (faixas, 2 casas, eixo inteiro, perfis, modos, versão)', function () {
    $rules = ContactLensCalculator::validationRules();

    expect($rules['contact_lens_calculation.od.sphere'])->toBe(['nullable', 'numeric', 'decimal:0,2', 'min:-40', 'max:40'])
        ->and($rules['contact_lens_calculation.oe.cylinder'])->toBe(['nullable', 'numeric', 'decimal:0,2', 'min:-15', 'max:15'])
        ->and($rules['contact_lens_calculation.oe.axis'])->toBe(['nullable', 'integer', 'min:0', 'max:180'])
        ->and($rules['contact_lens_calculation.vertex_distance_mm'])->toContain('min:5', 'max:25')
        ->and($rules['contact_lens_calculation.profile'])->toContain('in:standard,extended')
        ->and($rules['contact_lens_calculation.lens_mode'])->toContain('in:auto,spherical,toric')
        ->and($rules['contact_lens_calculation.version'])->toContain('in:2');
});

describe('ContactLensFormatter (PDF e exportação LGPD)', function () {
    beforeEach(fn () => app()->setLocale('pt_BR'));

    $v2 = fn () => (new ContactLensCalculator())->calculate([
        'od' => ['sphere' => -5, 'cylinder' => -2, 'axis' => 180],
        'oe' => ['sphere' => -16],
    ]);

    it('v2: sugerida por olho com o tipo, teórico com vértice, linha e avisos', function () use ($v2) {
        $rows = (new ContactLensFormatter())->rows($v2());

        expect(array_column($rows, 'value', 'label'))->toBe([
            'Lente de contato sugerida'       => 'OD −4,75 / −1,75 × 180° (tórica)  ·  OE sem lente nesta linha',
            'Cálculo teórico (vértice 12 mm)' => 'OD −4,72 / −1,74 × 180° — vértice aplicado (acima de ±4,00 D)  ·  OE −13,42 D — vértice aplicado (acima de ±4,00 D)',
            'Linha de lentes'                 => 'Padrão de mercado',
            'Avisos'                          => 'OE: Fora da faixa comum — considere a faixa estendida ou consulte o fabricante.',
        ]);
    });

    it('em inglês: OS, ponto decimal e textos traduzidos', function () use ($v2) {
        app()->setLocale('en');

        expect((new ContactLensFormatter())->text($v2()))->toStartWith('Suggested contact lens: OD −4.75 / −1.75 × 180° (toric)  ·  OS no lens in this range;');
    });

    it('plano, esférica com cilindro (EE) e componente sem sugestão', function () {
        $f = new ContactLensFormatter();

        expect($f->lens(['sphere' => 0.0, 'cylinder' => null, 'axis' => null], 'spherical'))->toBe('Plano (0,00)')
            ->and($f->lens(['sphere' => -2.5, 'cylinder' => -1.25, 'axis' => null], 'toric'))->toBe('−2,50 / −1,25 × —')
            ->and($f->theoretical(clv2Eye(-2, -0.5, 90)))->toBe('−2,25 D (equivalente esférico) — sem compensação de vértice (até ±4,00 D)')
            ->and($f->power(5.25))->toBe('+5,25')
            ->and($f->power(0))->toBe('0,00');
    });

    it('v1 (gravado antes da v2): formato antigo, como está', function () {
        $legacy = [
            'version'      => 1, 'vertex_distance_mm' => 12.5,
            'vertex_od'    => -6, 'vertex_od_result' => -5.6, 'vertex_oe' => null, 'vertex_oe_result' => null,
            'se_od_sphere' => -2, 'se_od_cylinder' => -1, 'se_od_result' => -2.5, 'se_oe_result' => null,
        ];
        $f = new ContactLensFormatter();

        expect($f->isLegacy($legacy))->toBeTrue()
            ->and($f->text($legacy))->toBe('Esférico → lente de contato (vértice 12,5 mm) (versão anterior): OD: -6.00 → -5.60; Equivalente esférico (versão anterior): OD: -2.50')
            ->and($f->text(null))->toBeNull();
    });
});
