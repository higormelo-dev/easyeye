<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;

/**
 * Transporte de UMA mensagem TISS avulsa (solicitação de internação, anexo de
 * OPME...), sem lote. Contrato à parte de TissTransportContract (que só envia lote)
 * para não quebrar quem já implementa o do lote.
 */
interface TissMessageTransportContract
{
    /**
     * @param string               $transactionType `tipoTransacao` da mensagem (ex.: SOLICITACAO_PROCEDIMENTOS)
     * @param array<string, mixed> $context
     *
     * @return array{
     *   ok: bool,
     *   status_code: int|null,
     *   response_body: string|null,
     *   protocol_number: string|null,
     *   protocol_status: string|null,
     *   error_code: string|null,
     *   error_message: string|null,
     *   response_time_ms: int|null
     * }
     */
    public function sendMessage(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        string $transactionType,
        array $context = [],
    ): array;
}
