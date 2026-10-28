<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\BrazilianFormat;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF (11 dígitos) ou CNPJ (14 caracteres) com dígitos verificadores corretos.
 *
 *  - Aceita máscara e minúsculas: o valor é reduzido ao canônico (BrazilianFormat::documentChars) antes de conferir.
 *  - CNPJ alfanumérico (IN RFB 2.229/2024): as 12 primeiras posições podem ter letras (A–Z) e os 2 DVs são numéricos;
 *    cada caractere vale ord(c) − 48 (0–9 → 0–9, A → 17 … Z → 42), com os MESMOS pesos e módulo 11 do CNPJ numérico.
 *  - Todos os caracteres iguais (000…, 111…) são recusados, como a Receita faz.
 *  - Vazio não é válido (a obrigatoriedade é de quem usa a regra: `required`/`required_if`; sem elas o Laravel nem a
 *    chama para um valor ausente).
 */
final class CpfCnpj implements ValidationRule
{
    private const CNPJ_WEIGHTS_1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const CNPJ_WEIGHTS_2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('validation.cpf_cnpj_invalid'));
        }
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $chars = BrazilianFormat::documentChars((string) $value);

        if ($chars === null || count(array_unique(str_split($chars))) === 1) {
            return false;
        }

        if (preg_match('/^\d{11}$/', $chars) === 1) {
            return self::validCpf($chars);
        }

        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $chars) === 1) {
            return self::validCnpj($chars);
        }

        return false;
    }

    private static function validCpf(string $cpf): bool
    {
        foreach ([9, 10] as $length) {
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }

            $check = ($sum * 10) % 11;

            if (($check === 10 ? 0 : $check) !== (int) $cpf[$length]) {
                return false;
            }
        }

        return true;
    }

    private static function validCnpj(string $cnpj): bool
    {
        foreach ([self::CNPJ_WEIGHTS_1, self::CNPJ_WEIGHTS_2] as $weights) {
            $sum = 0;

            foreach ($weights as $i => $weight) {
                $sum += (ord($cnpj[$i]) - 48) * $weight;
            }

            $rest  = $sum % 11;
            $check = $rest < 2 ? 0 : 11 - $rest;

            if ($check !== (int) $cnpj[count($weights)]) {
                return false;
            }
        }

        return true;
    }
}
