<?php

namespace App\Support\Billing;

/**
 * Link de pagamento (página da fatura, boleto ou Pix) vindo do gateway: só
 * http(s), sem espaços e até 2048 caracteres (o tamanho da coluna
 * invoices.payment_url). Outro esquema (javascript:, data:…) ou valor longo
 * demais é descartado — nunca truncado: o link vira href no painel ("Pagar
 * agora") e no e-mail da régua.
 */
final class PaymentUrl
{
    public const MAX_LENGTH = 2048;

    public static function safe(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('#^https?://\S+$#i', $value) === 1 && strlen($value) <= self::MAX_LENGTH ? $value : null;
    }
}
