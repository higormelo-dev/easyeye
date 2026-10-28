<?php

namespace App\DTOs\Billing;

/**
 * Resposta do gateway ao pedido de estorno. O estorno só conta como
 * devolvido quando o gateway confirma (status done — no Asaas, o item de
 * refunds[] com status DONE ou o webhook PAYMENT_REFUNDED /
 * PAYMENT_PARTIALLY_REFUNDED: https://docs.asaas.com/docs/estornos); até lá,
 * requested.
 */
readonly class RefundResultDTO
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Conferência (refundStatus): o pedido não existe no gateway — nunca chegou lá. */
    public const STATUS_NOT_FOUND = 'not_found';

    public function __construct(
        public bool $success,
        public string $status,
        public ?float $amount = null,
        public ?string $externalRefundId = null,
        // Boleto (Asaas): link em que o pagador informa a conta para receber.
        public ?string $requestUrl = null,
        public array $rawResponse = [],
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        // Sem resposta definitiva (timeout, conexão, 5xx): o gateway pode ter
        // feito o estorno — conferir (refundStatus) antes de pedir de novo.
        public bool $inconclusive = false,
        // Situação vista na conferência (ex.: awaiting_payer_account — boleto
        // aguardando a conta do pagador; in_progress).
        public ?string $note = null,
    ) {
    }

    public static function failed(string $message, ?int $httpStatus = null, array $raw = []): self
    {
        return new self(success: false, status: self::STATUS_FAILED, rawResponse: $raw, errorMessage: $message, httpStatus: $httpStatus);
    }

    public static function inconclusive(string $message, ?int $httpStatus = null): self
    {
        return new self(success: false, status: self::STATUS_REQUESTED, errorMessage: $message, httpStatus: $httpStatus, inconclusive: true);
    }

    /** Resultado da conferência de um pedido de estorno no gateway. */
    public static function checked(string $status, ?string $note = null, ?float $amount = null, ?string $externalRefundId = null): self
    {
        return new self(success: true, status: $status, amount: $amount, externalRefundId: $externalRefundId, note: $note);
    }
}
