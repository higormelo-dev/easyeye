<?php

namespace App\Support;

/**
 * CPF/CNPJ/telefone/CEP brasileiros — contraparte PHP de resources/js/utils/masks.js.
 *
 * - Normalização canônica para GRAVAR (usada no prepareForValidation dos
 *   FormRequests): canonicalPhone(), documentChars(), digits().
 * - Formatação para EXIBIR (PDFs, props de tela): cpf(), cnpj(), cpfCnpj(),
 *   phone(), cep(). Valor em formato inesperado (legado, número internacional)
 *   volta como veio (trim), para nunca mutilar dado que não sabemos formatar.
 */
final class BrazilianFormat
{
    /** 12/13 dígitos começando por 55 = DDI + DDD + número (DDD 55/RS sem DDI tem 10/11). */
    private const BR_DDI_PATTERN = '/^55(\d{10,11})$/';

    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    /**
     * Telefone canônico para gravar: só dígitos e sem DDI 55; número estrangeiro
     * ("+" com outro DDI) vira "+" + dígitos, para nunca se passar por brasileiro
     * (ex.: "+34 912 345 678" não pode virar DDD 34). null quando não há dígito.
     */
    public static function canonicalPhone(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === '') {
            return null;
        }

        if (self::isForeign($value)) {
            return '+' . $digits;
        }

        return preg_match(self::BR_DDI_PATTERN, $digits, $matches) ? $matches[1] : $digits;
    }

    /**
     * Dígitos de um termo de BUSCA que parece número mascarado (telefone, CPF…),
     * para casar com colunas gravadas só com dígitos. null quando o termo tem
     * letras (é nome) ou já é só dígitos (a busca normal já cobre).
     */
    public static function searchDigits(string $term): ?string
    {
        if (! preg_match('/^[\d\s().\/+-]+$/', $term)) {
            return null;
        }

        $digits = self::digits($term);

        return $digits !== '' && $digits !== $term ? $digits : null;
    }

    /**
     * CPF/CNPJ canônico: letras e números em maiúsculas, sem pontuação — preserva
     * o CNPJ alfanumérico (IN RFB 2.229/2024), mesma regra de Entity::setAttribute.
     * null quando vazio.
     */
    public static function documentChars(?string $value): ?string
    {
        $chars = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');

        return $chars === '' ? null : $chars;
    }

    public static function cpf(?string $value): ?string
    {
        return self::display($value, self::digits($value), [
            '/^(\d{3})(\d{3})(\d{3})(\d{2})$/' => '$1.$2.$3-$4',
        ]);
    }

    /** 00.000.000/0000-00 — as 12 primeiras posições podem ter letras; os 2 DVs são numéricos. */
    public static function cnpj(?string $value): ?string
    {
        return self::display($value, (string) self::documentChars($value), [
            '/^([A-Z0-9]{2})([A-Z0-9]{3})([A-Z0-9]{3})([A-Z0-9]{4})(\d{2})$/' => '$1.$2.$3/$4-$5',
        ]);
    }

    public static function cpfCnpj(?string $value): ?string
    {
        return strlen((string) self::documentChars($value)) === 14 ? self::cnpj($value) : self::cpf($value);
    }

    /**
     * Fixo (10 dígitos) ou celular (11) com DDD; não geográficos 0800/0300 (11) e
     * 3003/4004 (8) — não existe DDD começando por 0, 30 ou 40. DDI 55 é descartado.
     */
    public static function phone(?string $value): ?string
    {
        if (self::isForeign($value)) {
            return trim((string) $value);
        }

        return self::display($value, (string) self::canonicalPhone($value), [
            '/^(0\d{3})(\d{3})(\d{4})$/'  => '$1 $2 $3',
            '/^([34]0\d{2})(\d{4})$/'     => '$1-$2',
            '/^([1-9]\d)(\d{4})(\d{4})$/' => '($1) $2-$3',
            '/^([1-9]\d)(\d{5})(\d{4})$/' => '($1) $2-$3',
        ]);
    }

    public static function cep(?string $value): ?string
    {
        return self::display($value, self::digits($value), [
            '/^(\d{5})(\d{3})$/' => '$1-$2',
        ]);
    }

    /** "+" seguido de DDI que não é 55. */
    private static function isForeign(?string $value): bool
    {
        $trimmed = ltrim((string) $value);

        return str_starts_with($trimmed, '+') && ! str_starts_with(self::digits($trimmed), '55');
    }

    /**
     * @param array<string, string> $patterns regex → substituição; vale a primeira que casar
     */
    private static function display(?string $value, string $normalized, array $patterns): ?string
    {
        $original = trim((string) $value);

        if ($original === '') {
            return null;
        }

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $normalized)) {
                return preg_replace($pattern, $replacement, $normalized) ?? $original;
            }
        }

        return $original;
    }
}
