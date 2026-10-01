<?php

declare(strict_types=1);

use App\Services\ContactLensCalculator;

/**
 * Cálculo de lentes de contato do prontuário — o servidor RECALCULA o que a
 * tela mostrou (mesmas fórmulas e o mesmo arredondamento do componente Vue,
 * Math.round(x·100)/100), e grava entradas + resultados. Nunca confia em
 * resultado vindo do navegador.
 */
function clc(array $inputs): ?array
{
    return (new ContactLensCalculator())->calculate($inputs);
}

it('vértice: -6.00 a 12 mm vira -5.60; +6.00 vira +6.47 (F/(1 - d·F))', function (): void {
    $r = clc(['vertex_distance_mm' => 12, 'vertex_od' => -6, 'vertex_oe' => 6]);

    expect($r['vertex_od_result'])->toBe(-5.6)
        ->and($r['vertex_oe_result'])->toBe(6.47)
        ->and($r['vertex_distance_mm'])->toBe(12.0);
});

it('vértice: plano (0) é 0, vazio é null, distância vazia/0 usa 12 mm', function (): void {
    $r = clc(['vertex_distance_mm' => 0, 'vertex_od' => 0, 'vertex_oe' => null, 'se_od_sphere' => -1]);

    expect($r['vertex_od_result'])->toBe(0.0)
        ->and($r['vertex_oe_result'])->toBeNull()
        ->and($r['vertex_distance_mm'])->toBe(12.0);
});

it('equivalente esférico: SE = esférico + cilindro/2; cilindro vazio conta 0', function (): void {
    $r = clc(['se_od_sphere' => -2, 'se_od_cylinder' => -1, 'se_oe_sphere' => 1.5, 'se_oe_cylinder' => null]);

    expect($r['se_od_result'])->toBe(-2.5)
        ->and($r['se_oe_result'])->toBe(1.5);
});

it('arredondamento igual ao da tela (Math.round): -2.375 vira -2.37', function (): void {
    // -2.25 + (-0.25)/2 = -2.375 → Math.round(-237.5) = -237 → -2.37
    expect(clc(['se_od_sphere' => -2.25, 'se_od_cylinder' => -0.25])['se_od_result'])->toBe(-2.37);
});

it('nada digitado: não grava nada (null)', function (): void {
    expect(clc([]))->toBeNull()
        ->and(clc(['vertex_distance_mm' => 12]))->toBeNull()
        ->and(clc(['vertex_od' => '', 'se_od_cylinder' => '']))->toBeNull();
});

it('resultado enviado pelo navegador é ignorado (recalcula sempre)', function (): void {
    $r = clc(['vertex_od' => -6, 'vertex_od_result' => 99, 'se_od_sphere' => -2, 'se_od_result' => 99]);

    expect($r['vertex_od_result'])->toBe(-5.6)
        ->and($r['se_od_result'])->toBe(-2.0);
});

it('paridade com a tela: cilindro inválido não gera SE; só espaços conta como vazio', function (): void {
    $r = clc(['se_od_sphere' => -2, 'se_od_cylinder' => 'abc', 'se_oe_sphere' => -2, 'se_oe_cylinder' => '  ']);

    expect($r['se_od_result'])->toBeNull()
        ->and($r['se_od_cylinder'])->toBeNull()
        ->and($r['se_oe_result'])->toBe(-2.0);

    expect(clc(['vertex_od' => '   ']))->toBeNull();
});
