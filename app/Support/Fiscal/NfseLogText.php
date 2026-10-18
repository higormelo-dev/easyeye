<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

/**
 * Texto livre que veio da Focus (mensagem de erro) antes de ir para o log de cobrança: sem caracteres de controle (quebra de
 * linha, ESC, NUL — nada de forjar linha de log) e com tamanho máximo. Códigos curtos (`codigo`) passam por `code()`.
 */
final class NfseLogText
{
    public static function clean(string $text, int $max = 200): string
    {
        $text = preg_replace('/\p{Cc}+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, max(1, $max));
    }

    /**
     * Código de erro que PODE ir ao log de cobrança: só o que é conhecido, nunca um valor qualquer que a Focus (ou quem a
     * imite) mande — um CPF ou um token no campo `codigo` iria direto para billing_logs/Log. Passa:
     *  a) código da allowlist EXATA em `fiscal.log_codes` (os documentados da Focus + os nossos internos);
     *  b) código de prefeitura no padrão estreito ^[A-Z]{1,3}[0-9]{1,4}$ (L99, V999, E0014).
     * Qualquer outro valor vira `unknown`; vazio continua vazio (não há código a registrar).
     */
    public static function code(string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        if (in_array($code, (array) config('fiscal.log_codes', []), true) || preg_match('/^[A-Z]{1,3}[0-9]{1,4}$/', $code) === 1) {
            return $code;
        }

        return 'unknown';
    }
}
