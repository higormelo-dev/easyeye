<?php

declare(strict_types=1);

use App\Domains\ClinicalReview\Support\ReviewToken;

it('gera tokens longos, únicos e em base64url (só letras, números, - e _)', function () {
    $tokens = array_map(fn () => ReviewToken::generate()['token'], range(1, 50));

    expect(array_unique($tokens))->toHaveCount(50);

    foreach ($tokens as $token) {
        expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/');
    }
});

it('o hash é o sha256 do token e o token não aparece nele', function () {
    ['token' => $token, 'hash' => $hash] = ReviewToken::generate();

    expect($hash)->toBe(hash('sha256', $token))
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->not->toContain($token);
});

it('matches aceita o token certo e recusa qualquer outro', function () {
    ['token' => $token, 'hash' => $hash] = ReviewToken::generate();

    expect(ReviewToken::matches($token, $hash))->toBeTrue()
        ->and(ReviewToken::matches($token . 'x', $hash))->toBeFalse()
        ->and(ReviewToken::matches(substr($token, 1), $hash))->toBeFalse()
        ->and(ReviewToken::matches(strtoupper($token), $hash))->toBeFalse()
        ->and(ReviewToken::matches('', $hash))->toBeFalse()
        ->and(ReviewToken::matches($token, str_repeat('0', 64)))->toBeFalse()
        ->and(ReviewToken::matches($token, ''))->toBeFalse();
});

it('a comparação do token é em tempo constante (hash_equals), nunca ==', function () {
    $source = (string) file_get_contents(__DIR__ . '/../../../app/Domains/ClinicalReview/Support/ReviewToken.php');

    expect($source)->toContain('hash_equals($storedHash, self::hash($token))');
});

it('o código de acesso tem 8 caracteres legíveis (sem 0, O, 1, I, L)', function () {
    for ($i = 0; $i < 200; $i++) {
        $code = ReviewToken::generateAccessCode();

        expect($code)->toMatch('/^[A-HJKMNP-Z2-9]{8}$/')->and($code)->not->toMatch('/[01OIL]/');
    }

    expect(ReviewToken::generateAccessCode(12))->toHaveLength(12);
});

it('normaliza o código digitado: caixa e espaços não contam', function () {
    expect(ReviewToken::normalizeAccessCode(' ab c-d 23 '))->toBe('ABC-D23')
        ->and(ReviewToken::normalizeAccessCode("a b\tc\n"))->toBe('ABC')
        ->and(ReviewToken::normalizeAccessCode(''))->toBe('');
});
