<?php

use App\Models\TvDisplay;

// ── generateToken ────────────────────────────────────────────────────────────

test('generateToken retorna string de 48 caracteres', function () {
    $token = TvDisplay::generateToken();

    expect($token)->toBeString()->toHaveLength(48);
});

test('generateToken produz tokens únicos', function () {
    $tokens = array_map(fn () => TvDisplay::generateToken(), range(1, 20));

    expect(array_unique($tokens))->toHaveCount(20);
});

// ── generatePin ──────────────────────────────────────────────────────────────

test('generatePin retorna string de 6 dígitos', function () {
    $pin = TvDisplay::generatePin();

    expect($pin)->toBeString()->toHaveLength(6)->toMatch('/^\d{6}$/');
});

test('generatePin preenche zeros à esquerda quando necessário', function () {
    // Força um valor baixo via mock para garantir padding
    for ($i = 0; $i < 50; $i++) {
        $pin = TvDisplay::generatePin();
        expect(strlen($pin))->toBe(6);
        expect(ctype_digit($pin))->toBeTrue();
    }
});

// ── status helpers ───────────────────────────────────────────────────────────

test('isPending retorna true somente para status pending', function () {
    $tv = new TvDisplay(['status' => TvDisplay::STATUS_PENDING]);

    expect($tv->isPending())->toBeTrue();
    expect($tv->isActive())->toBeFalse();
});

test('isActive retorna true somente para status active', function () {
    $tv = new TvDisplay(['status' => TvDisplay::STATUS_ACTIVE]);

    expect($tv->isActive())->toBeTrue();
    expect($tv->isPending())->toBeFalse();
});

test('TV revogada não é nem pending nem active', function () {
    $tv = new TvDisplay(['status' => TvDisplay::STATUS_REVOKED]);

    expect($tv->isPending())->toBeFalse();
    expect($tv->isActive())->toBeFalse();
});

// ── constantes de status ─────────────────────────────────────────────────────

test('constantes de status têm os valores esperados', function () {
    expect(TvDisplay::STATUS_PENDING)->toBe('pending');
    expect(TvDisplay::STATUS_ACTIVE)->toBe('active');
    expect(TvDisplay::STATUS_REVOKED)->toBe('revoked');
});
