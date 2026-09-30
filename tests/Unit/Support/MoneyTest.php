<?php

/*
 * Aritmética de dinheiro em centavos: rateio determinístico (allocate) e
 * percentual half-up (percentageOf) — usados na base e no rastreio do
 * repasse médico.
 */

use App\Support\Money;

it('divide em parcelas que somam exatamente o total, com o resto nas primeiras', function (int $cents, int $parts, array $expected) {
    $shares = Money::allocate($cents, $parts);

    expect($shares)->toBe($expected)
        ->and(array_sum($shares))->toBe($cents);
})->with([
    'exato'               => [80000, 2, [40000, 40000]],
    'um centavo de resto' => [10001, 2, [5001, 5000]],
    'dois centavos, três' => [100, 3, [34, 33, 33]],
    'parcela única'       => [12345, 1, [12345]],
    'zero'                => [0, 3, [0, 0, 0]],
    'menos que as partes' => [2, 3, [1, 1, 0]],
    'negativo (estorno)'  => [-10001, 2, [-5001, -5000]],
]);

it('recusa zero parcelas', function () {
    Money::allocate(100, 0);
})->throws(InvalidArgumentException::class);

it('proporção (fixo × recebido ÷ esperado) arredonda meio centavo para cima, sem estourar 64 bits', function () {
    expect(Money::proportion(8000, 50000, 100000))->toBe(4000)          // metade do fixo
        ->and(Money::proportion(8000, 1, 3))->toBe(2667)                 // 2666,67 → 2667
        ->and(Money::proportion(8000, 2, 3))->toBe(5333)                 // 5333,33 → 5333
        ->and(Money::proportion(1, 1, 2))->toBe(1)                       // 0,5 → 1 (half-up)
        ->and(Money::proportion(8000, 0, 100))->toBe(0)
        ->and(Money::proportion(-8000, 1, 2))->toBe(-4000)
        ->and(Money::proportion(999_999_999_999, 999_999_999_999, 999_999_999_999))->toBe(999_999_999_999)
        ->and(Money::proportion(999_999_999_999, 1, 3))->toBe(333_333_333_333);
});

it('proporção recusa denominador zero', function () {
    Money::proportion(100, 1, 0);
})->throws(InvalidArgumentException::class);

it('percentual em centavos arredonda meio centavo para cima', function () {
    expect(Money::percentageOf(100000, '40.00'))->toBe(40000)
        ->and(Money::percentageOf(60000, '60.00'))->toBe(36000)
        ->and(Money::percentageOf(60000, '40.00'))->toBe(24000)
        ->and(Money::percentageOf(101, '50.00'))->toBe(51)
        ->and(Money::percentageOf(333, '33.33'))->toBe(111);
});
