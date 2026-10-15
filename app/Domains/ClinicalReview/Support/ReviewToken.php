<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Support;

use Illuminate\Support\Str;

/**
 * Token do link do Revisor e código de acesso (PIN) opcional.
 *
 * O token tem 256 bits de aleatoriedade e NUNCA é gravado em claro: guarda-se o sha256 e a
 * comparação é feita com hash_equals. Como o token é aleatório e longo (não uma senha de
 * gente), sha256 sem sal basta; o PIN, curto, usa Hash::make no serviço.
 */
final class ReviewToken
{
    /** Sem 0/O, 1/I/L: o PIN é lido por telefone e digitado à mão. */
    private const PIN_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Compara em tempo constante o token recebido com o hash gravado. */
    public static function matches(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($token));
    }

    /** PIN de 8 caracteres legíveis (≈ 39 bits): serve para a 2ª barreira, que é limitada por tentativas. */
    public static function generateAccessCode(int $length = 8): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::PIN_ALPHABET[random_int(0, strlen(self::PIN_ALPHABET) - 1)];
        }

        return $code;
    }

    /** Normaliza o que o Revisor digitou (caixa e espaços não contam). */
    public static function normalizeAccessCode(string $code): string
    {
        return Str::upper(preg_replace('/\s+/', '', $code) ?? '');
    }
}
