<?php

namespace App\Support\Billing;

use Illuminate\Support\Str;

/**
 * externalReference do checkout hospedado (até 200 caracteres —
 * https://docs.asaas.com/reference/criar-novo-checkout): liga o checkout à
 * nossa assinatura e à fatura que ele paga, no formato
 * "easyeye:sub:{uuid}:inv:{uuid}" (sem assinatura: "easyeye:inv:{uuid}").
 *
 * Não é o id puro da assinatura de propósito: a busca de recorrências pela
 * referência (findRecurrenceIdsByReference) desfaz as recorrências de uma
 * tentativa de contratação que falhou — a do checkout nunca entra nela.
 */
final class HostedCheckoutReference
{
    public static function make(?string $subscriptionId, string $invoiceId): string
    {
        return filled($subscriptionId)
            ? "easyeye:sub:{$subscriptionId}:inv:{$invoiceId}"
            : "easyeye:inv:{$invoiceId}";
    }

    /**
     * @return array{subscription_id?: string, invoice_id?: string}|null null quando não é deste formato
     */
    public static function parse(string $reference): ?array
    {
        if (! str_starts_with($reference, 'easyeye:')) {
            return null;
        }

        $parsed = [];

        if (preg_match('/:sub:([0-9a-f-]{36})/i', $reference, $m) === 1 && Str::isUuid($m[1])) {
            $parsed['subscription_id'] = $m[1];
        }

        if (preg_match('/:inv:([0-9a-f-]{36})/i', $reference, $m) === 1 && Str::isUuid($m[1])) {
            $parsed['invoice_id'] = $m[1];
        }

        return $parsed;
    }
}
