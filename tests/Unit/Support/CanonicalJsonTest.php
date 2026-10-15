<?php

declare(strict_types=1);

/**
 * JSON canônico v1 (App\Support\CanonicalJson) — base da prova de "quem validou
 * qual versão". Os vetores dourados vêm de OUTRA implementação (Python, ver
 * tests/Fixtures/canonical-json/generate_vectors.py) e são CONGELADOS: um vetor
 * que falha aqui significa que o algoritmo mudou, e isso é uma versão nova
 * (CanonicalJson::VERSION), nunca um ajuste de vetor — hashes já gravados
 * deixariam de bater.
 */

use App\Enums\Eye;
use App\Support\CanonicalJson;
use Carbon\Carbon;

/** @return array<int, array{name: string, domain: string, input: mixed, canonical: string, hash: string}> */
function cjVectors(): array
{
    $vectors = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/canonical-json/vectors.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($vectors)->not->toBeEmpty();

    return $vectors;
}

it('[DOURADO] cada vetor produz exatamente o texto e o hash da implementação independente', function () {
    foreach (cjVectors() as $vector) {
        $encoded = CanonicalJson::encode($vector['input']);

        expect($encoded)->toBe($vector['canonical'], $vector['name'])
            ->and(CanonicalJson::hash($vector['domain'], $encoded))->toBe($vector['hash'], $vector['name'])
            ->and(CanonicalJson::hashOf($vector['domain'], $vector['input']))->toBe($vector['hash'], $vector['name'])
            ->and(CanonicalJson::matches($vector['domain'], $vector['canonical'], $vector['hash']))->toBeTrue($vector['name']);
    }
});

it('[DOURADO] o texto canônico é ponto fixo: ler e escrever de novo devolve os mesmos bytes', function () {
    foreach (cjVectors() as $vector) {
        expect(CanonicalJson::isCanonical($vector['canonical']))->toBeTrue($vector['name'])
            ->and(CanonicalJson::encode(CanonicalJson::decode($vector['canonical'])))->toBe($vector['canonical'], $vector['name']);
    }
});

it('[DOURADO] o mesmo valor em domínios diferentes dá hashes diferentes', function () {
    $byName = array_column(cjVectors(), null, 'name');

    expect($byName['protocol-like']['canonical'])->toBe($byName['protocol-like-other-domain']['canonical'])
        ->and($byName['protocol-like']['hash'])->not->toBe($byName['protocol-like-other-domain']['hash']);
});

it('ordena as chaves, mantém a ordem das listas e não põe espaço', function () {
    expect(CanonicalJson::encode(['b' => 1, 'a' => ['y' => true, 'x' => null], 'c' => [3, 1, 2]]))
        ->toBe('{"a":{"x":null,"y":true},"b":1,"c":[3,1,2]}');
});

it('chave numérica em texto continua texto: "10" vem antes de "9", como ordem de bytes', function () {
    // O PHP vira as chaves '10' e '9' em inteiros; a ordem tem que ser a do texto.
    expect(CanonicalJson::encode(['9' => 'y', '10' => 'x', 'a' => 'z']))->toBe('{"10":"x","9":"y","a":"z"}');
});

it('lista é array com chaves 0..n-1 em sequência; qualquer outra coisa é objeto; vazio é []', function () {
    expect(CanonicalJson::encode([0 => 'a', 1 => 'b']))->toBe('["a","b"]')
        ->and(CanonicalJson::encode([1 => 'a']))->toBe('{"1":"a"}')
        ->and(CanonicalJson::encode([1 => 'a', 0 => 'b']))->toBe('{"0":"b","1":"a"}')
        ->and(CanonicalJson::encode([]))->toBe('[]')
        ->and(CanonicalJson::encode(['a' => []]))->toBe('{"a":[]}');
});

it('escapa só aspas, barra invertida e controles; o resto fica literal', function () {
    expect(CanonicalJson::encode('a"b\\c/d'))->toBe('"a\\"b\\\\c/d"')
        ->and(CanonicalJson::encode("\x08\x0c\n\r\t"))->toBe('"\\b\\f\\n\\r\\t"')
        ->and(CanonicalJson::encode("\x00\x01\x1f"))->toBe('"\\u0000\\u0001\\u001f"')
        ->and(CanonicalJson::encode("\x7f\u{2028}\u{2029}é😀"))->toBe("\"\x7f\u{2028}\u{2029}é😀\"");
});

it('normaliza strings e chaves para NFC', function () {
    $decomposed = "e\u{0301}";
    $composed   = "\u{00e9}";

    expect(CanonicalJson::encode($decomposed))->toBe('"' . $composed . '"')
        ->and(CanonicalJson::encode([$decomposed => $decomposed]))->toBe('{"' . $composed . '":"' . $composed . '"}')
        ->and(CanonicalJson::encode($decomposed))->toBe(CanonicalJson::encode($composed));
});

it('ordena chaves por bytes UTF-8 (ponto de código), não por UTF-16: U+FFFF vem antes de U+10000', function () {
    expect(CanonicalJson::encode(["\u{10000}" => 1, "\u{ffff}" => 2, 'z' => 3]))
        ->toBe("{\"z\":3,\"\u{ffff}\":2,\"\u{10000}\":1}");
});

it('aceita inteiros até ±(2^53−1) e recusa além', function () {
    expect(CanonicalJson::encode([0, -1, 9007199254740991, -9007199254740991]))->toBe('[0,-1,9007199254740991,-9007199254740991]');

    foreach ([9007199254740992, -9007199254740992, PHP_INT_MAX, PHP_INT_MIN] as $tooBig) {
        expect(fn () => CanonicalJson::encode($tooBig))->toThrow(InvalidArgumentException::class);
    }
});

it('recusa o que não é null, bool, int, string ou array', function (mixed $value) {
    expect(fn () => CanonicalJson::encode(['x' => $value]))->toThrow(InvalidArgumentException::class);
})->with([
    'float'         => [1.5],
    'float inteiro' => [1.0],
    'NaN'           => [NAN],
    'INF'           => [INF],
    'objeto'        => [new stdClass()],
    'enum'          => [Eye::Right],
    'Carbon'        => [Carbon::parse('2026-10-13 12:00:00')],
]);

it('recusa closure (Pest resolveria uma closure de dataset antes de chegar aqui, por isso fora da lista)', function () {
    expect(fn () => CanonicalJson::encode(['x' => fn () => 1]))->toThrow(InvalidArgumentException::class);
});

it('recusa UTF-8 inválido em valor e em chave', function () {
    expect(fn () => CanonicalJson::encode("abc\xff"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalJson::encode(["abc\xff" => 1]))->toThrow(InvalidArgumentException::class);
});

it('recusa duas chaves que viram a mesma depois do NFC', function () {
    expect(fn () => CanonicalJson::encode(["\u{00e9}" => 1, "e\u{0301}" => 2]))->toThrow(InvalidArgumentException::class);
});

it('recusa estrutura profunda demais, em vez de estourar a pilha', function () {
    $value = 'x';

    for ($level = 0; $level < 40; $level++) {
        $value = [$value];
    }

    expect(fn () => CanonicalJson::encode($value))->toThrow(InvalidArgumentException::class);
});

it('decode lê inteiros e recusa float e JSON inválido', function () {
    expect(CanonicalJson::decode('{"a":[1,-2],"b":"x"}'))->toBe(['a' => [1, -2], 'b' => 'x']);

    foreach (['[1.5]', '1.0', '[1e3]', '{"a":', ''] as $bad) {
        expect(fn () => CanonicalJson::decode($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('decode recusa profundidade excessiva, e o limite do encode cabe no do decode', function () {
    $deep = str_repeat('[', 40) . '1' . str_repeat(']', 40);

    expect(fn () => CanonicalJson::decode($deep))->toThrow(InvalidArgumentException::class);

    // O mais fundo que encode() aceita ainda volta por decode().
    $value = 1;

    for ($level = 0; $level < 32; $level++) {
        $value = [$value];
    }

    expect(CanonicalJson::decode(CanonicalJson::encode($value)))->toBe($value);
});

it('isCanonical pega qualquer desvio da forma canônica', function (string $text) {
    expect(CanonicalJson::isCanonical($text))->toBeFalse();
})->with([
    'espaço'               => ['{"a": 1}'],
    'quebra de linha'      => ["{\"a\":1}\n"],
    'chaves fora de ordem' => ['{"b":1,"a":2}'],
    'chave repetida'       => ['{"a":1,"a":2}'],
    'Unicode decomposto'   => ["\"e\u{0301}\""],
    'barra escapada'       => ['"a\\/b"'],
    'Unicode escapado'     => ['"\\u00e9"'],
    'hex maiúsculo'        => ['"\\u001F"'],
    'objeto vazio'         => ['{}'],
    'float'                => ['1.0'],
    'zero negativo'        => ['-0'],
    'JSON inválido'        => ['{"a":'],
]);

it('hash: prefixo da versão, 64 hex minúsculos e domínio obrigatório sem quebra de linha', function () {
    $hash = CanonicalJson::hash('easyeye.test.v1', '{"a":1}');

    expect($hash)->toMatch(CanonicalJson::HASH_PATTERN)
        ->and($hash)->toStartWith(CanonicalJson::VERSION . ':')
        ->and($hash)->toBe('1:' . hash('sha256', "easyeye.test.v1\n{\"a\":1}"));

    foreach (['', "easyeye\ntest"] as $domain) {
        expect(fn () => CanonicalJson::hash($domain, '{}'))->toThrow(InvalidArgumentException::class);
    }
});

it('matches só aceita texto, domínio e versão exatos', function () {
    $text = CanonicalJson::encode(['a' => 1]);
    $good = CanonicalJson::hash('easyeye.test.v1', $text);

    expect(CanonicalJson::matches('easyeye.test.v1', $text, $good))->toBeTrue()
        ->and(CanonicalJson::matches('easyeye.test.v1', '{"a":2}', $good))->toBeFalse('texto alterado')
        ->and(CanonicalJson::matches('easyeye.test.v1', $text . ' ', $good))->toBeFalse('byte a mais')
        ->and(CanonicalJson::matches('easyeye.other.v1', $text, $good))->toBeFalse('outro domínio')
        ->and(CanonicalJson::matches('easyeye.test.v1', $text, '2:' . substr($good, 2)))->toBeFalse('versão desconhecida')
        ->and(CanonicalJson::matches('easyeye.test.v1', $text, substr($good, 2)))->toBeFalse('sem prefixo')
        ->and(CanonicalJson::matches('easyeye.test.v1', $text, strtoupper($good)))->toBeFalse('hex maiúsculo')
        ->and(CanonicalJson::matches('easyeye.test.v1', $text, substr($good, 0, -1)))->toBeFalse('truncado')
        ->and(CanonicalJson::matches('easyeye.test.v1', $text, ''))->toBeFalse('vazio');
});

it('matches compara em tempo constante (hash_equals), nunca com == ou ===', function () {
    $source = (string) file_get_contents(__DIR__ . '/../../../app/Support/CanonicalJson.php');

    expect($source)->toContain('hash_equals(self::hash($domain, $canonicalText), $expectedHash)');
});

it('verificação refaz o hash do TEXTO gravado sem recanonicalizar: texto fora da forma não "se conserta"', function () {
    // Gravado há tempos com NFD (ou por outra versão do Unicode): o hash foi feito sobre estes bytes.
    $stored = "{\"a\":\"e\u{0301}\"}";
    $hash   = CanonicalJson::hash('easyeye.test.v1', $stored);

    expect(CanonicalJson::isCanonical($stored))->toBeFalse()
        ->and(CanonicalJson::matches('easyeye.test.v1', $stored, $hash))->toBeTrue()
        ->and(CanonicalJson::matches('easyeye.test.v1', CanonicalJson::encode(CanonicalJson::decode($stored)), $hash))->toBeFalse();
});

it('normalizeText compõe, recusa UTF-8 inválido e não mexe em espaço (trim é do esquema)', function () {
    expect(CanonicalJson::normalizeText("e\u{0301}"))->toBe("\u{00e9}")
        ->and(CanonicalJson::normalizeText('  x  '))->toBe('  x  ')
        ->and(fn () => CanonicalJson::normalizeText("\xc3\x28"))->toThrow(InvalidArgumentException::class);
});

it('a normalização NFC existe neste ambiente (extensão intl ou polyfill do Symfony)', function () {
    expect(class_exists(Normalizer::class))->toBeTrue()
        ->and(Normalizer::normalize("e\u{0301}", Normalizer::FORM_C))->toBe("\u{00e9}");
});
