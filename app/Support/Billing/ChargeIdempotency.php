<?php

namespace App\Support\Billing;

use App\Enums\Billing\PaymentAttemptStatus;
use App\Models\Billing\{Invoice, PaymentAttempt};

/**
 * Chave de idempotência que a renovação local manda ao gateway.
 *
 * Cada tentativa tem a sua linha em payment_attempts (chave única por
 * gateway), mas a chave enviada ao gateway (request_payload.charge_idempotency_key)
 * só muda depois de uma falha definitiva:
 *  - timeout, 5xx, 429, 409, circuito aberto ou erro desconhecido: o gateway
 *    pode ter criado a cobrança mesmo sem responder — a nova tentativa repete
 *    a MESMA chave e o gateway devolve o mesmo pedido (nunca duas cobranças);
 *  - recusa (4xx de validação, cobrança recusada/cancelada na resposta): a
 *    chave anterior ficaria presa ao erro guardado pelo gateway — nova chave.
 */
final class ChargeIdempotency
{
    /** error_code da tentativa: cobrança criada, mas recusada/cancelada na resposta. */
    public const DECLINED = 'declined';

    /** error_code da tentativa: circuito aberto, nada foi enviado ao gateway. */
    public const CIRCUIT_OPEN = 'circuit_open';

    /** Falhas definitivas que não são status HTTP. */
    private const DEFINITIVE_CODES = [self::DECLINED, 'invalid_request', 'order_failed', 'unsupported'];

    /** 4xx que não são definitivos (tempo esgotado, conflito em andamento, limite). */
    private const RETRYABLE_4XX = [408, 409, 425, 429];

    /**
     * @param string $freshKey chave desta tentativa (usada quando a anterior
     *                         falhou de forma definitiva ou não existe)
     */
    public static function keyFor(Invoice $invoice, string $freshKey): string
    {
        $last = PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('created_at')
            ->first();

        if (! $last || $last->status !== PaymentAttemptStatus::Failed || self::isDefinitive($last->error_code)) {
            return $freshKey;
        }

        $previous = data_get($last->request_payload, 'charge_idempotency_key');

        return is_string($previous) && $previous !== '' ? $previous : (string) $last->idempotency_key;
    }

    /**
     * A última tentativa de emitir a cobrança da fatura falhou sem resposta
     * definitiva (timeout, conexão, 5xx, limite): o gateway pode ter criado a
     * cobrança. Sem chave de idempotência no gateway (Asaas), a próxima
     * emissão procura a cobrança pela referência antes de criar outra.
     */
    public static function previousAttemptInconclusive(Invoice $invoice): bool
    {
        $last = PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('created_at')
            ->first();

        return $last !== null
            && $last->status === PaymentAttemptStatus::Failed
            && $last->error_code !== self::CIRCUIT_OPEN
            && ! self::isDefinitive($last->error_code);
    }

    public static function isDefinitive(?string $errorCode): bool
    {
        if ($errorCode === null || $errorCode === '') {
            return false;
        }

        if (ctype_digit($errorCode)) {
            $status = (int) $errorCode;

            return $status >= 400 && $status < 500 && ! in_array($status, self::RETRYABLE_4XX, true);
        }

        return in_array($errorCode, self::DEFINITIVE_CODES, true);
    }
}
