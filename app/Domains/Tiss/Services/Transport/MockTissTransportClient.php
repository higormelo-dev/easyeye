<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;

class MockTissTransportClient implements TissTransportContract
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array{ok: bool, status_code: int|null, response_body: string|null, protocol_number: string|null, protocol_status: string|null, error_code: string|null, error_message: string|null, response_time_ms: int|null}
     */
    public function sendBatch(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        array $context = [],
    ): array {
        $protocolNumber = (string) ($context['protocol_number'] ?? ('PRT-' . now()->format('YmdHis') . '-' . random_int(100, 999)));

        $responseXml = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            '<ansTISS>' .
            '  <operadoraParaPrestador>' .
            '    <protocoloRecebimento>' .
            '      <registroANS>%s</registroANS>' .
            '      <numeroProtocolo>%s</numeroProtocolo>' .
            '      <statusProtocolo>Aceito</statusProtocolo>' .
            '      <idempotencyKey>%s</idempotencyKey>' .
            '    </protocoloRecebimento>' .
            '  </operadoraParaPrestador>' .
            '</ansTISS>',
            $credential->operator?->ans_code ?? '000000',
            $protocolNumber,
            $idempotencyKey,
        );

        return [
            'ok'               => true,
            'status_code'      => 200,
            'response_body'    => $responseXml,
            'protocol_number'  => $protocolNumber,
            'protocol_status'  => 'accepted',
            'error_code'       => null,
            'error_message'    => null,
            'response_time_ms' => random_int(40, 220),
        ];
    }
}
