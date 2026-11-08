<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;

/**
 * Transporte de TESTE: nada sai para a operadora. O recibo é inventado e diz isso em todo lugar onde aparece — o
 * número do protocolo começa com SIMULADO- e o XML guardado traz "Aceito (SIMULADO)" e a observação abaixo —, para
 * nunca parecer um protocolo de aceitação real. Em produção o envio com este transporte é bloqueado
 * (SimulatedTransportGuard), salvo a liberação explícita para demonstração.
 */
class MockTissTransportClient implements TissMessageTransportContract, TissTransportContract
{
    public const PROTOCOL_PREFIX = 'SIMULADO-';

    public const NOTICE = 'SIMULADO: transporte de teste (TISS_TRANSPORT_DRIVER=mock), nada foi enviado à operadora.';

    public static function simulatedProtocolNumber(): string
    {
        return self::PROTOCOL_PREFIX . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    /** Protocolo gerado por este transporte de teste (não por uma operadora). */
    public static function isSimulatedProtocol(?string $protocolNumber): bool
    {
        return str_starts_with((string) $protocolNumber, self::PROTOCOL_PREFIX);
    }

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
        $protocolNumber = (string) ($context['protocol_number'] ?? self::simulatedProtocolNumber());

        $responseXml = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            '<ansTISS>' .
            '  <operadoraParaPrestador>' .
            '    <protocoloRecebimento>' .
            '      <registroANS>%s</registroANS>' .
            '      <numeroProtocolo>%s</numeroProtocolo>' .
            '      <statusProtocolo>Aceito (SIMULADO)</statusProtocolo>' .
            '      <observacao>' . self::NOTICE . '</observacao>' .
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

    /**
     * Mensagem avulsa simulada: recibo de recebimento aceito. Com
     * `$context['mock_authorization']` (['status' => '1'|'3'|..., 'operator_guide_number', 'password',
     * 'valid_until', 'guide_number']) devolve também a resposta de autorização — demos e testes.
     *
     * @param array<string, mixed> $context
     *
     * @return array{ok: bool, status_code: int|null, response_body: string|null, protocol_number: string|null, protocol_status: string|null, error_code: string|null, error_message: string|null, response_time_ms: int|null}
     */
    public function sendMessage(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        string $transactionType,
        array $context = [],
    ): array {
        $protocolNumber = (string) ($context['protocol_number'] ?? self::simulatedProtocolNumber());
        $authorization  = '';

        if (is_array($context['mock_authorization'] ?? null)) {
            $a     = $context['mock_authorization'];
            $dados = sprintf(
                '<dadosAutorizacao><numeroGuiaPrestador>%s</numeroGuiaPrestador><numeroGuiaOperadora>%s</numeroGuiaOperadora>' .
                '<dataAutorizacao>%s</dataAutorizacao><senha>%s</senha><dataValidadeSenha>%s</dataValidadeSenha></dadosAutorizacao>' .
                '<statusSolicitacao>%s</statusSolicitacao>',
                htmlspecialchars((string) ($a['guide_number'] ?? ($context['guide_number'] ?? ''))),
                htmlspecialchars((string) ($a['operator_guide_number'] ?? '')),
                now()->toDateString(),
                htmlspecialchars((string) ($a['password'] ?? '')),
                htmlspecialchars((string) ($a['valid_until'] ?? '')),
                htmlspecialchars((string) ($a['status'] ?? '1')),
            );

            // Cada transação tem o envelope do XSD: o anexo de OPME responde por `recebimentoAnexo`,
            // a solicitação de internação por `autorizacaoServicos`.
            $authorization = $transactionType === 'ENVIO_ANEXO'
                ? '<recebimentoAnexo><protocoloRecebimentoAnexo><nrProtocoloRecebimento>' . htmlspecialchars($protocolNumber) . '</nrProtocoloRecebimento>' .
                  '<anexosClinicos><anexoOPME>' . $dados . '</anexoOPME></anexosClinicos></protocoloRecebimentoAnexo></recebimentoAnexo>'
                : '<autorizacaoServicos><autorizacaoInternacao><autorizacaoDosServicos>' . $dados . '</autorizacaoDosServicos></autorizacaoInternacao></autorizacaoServicos>';
        }

        $responseXml = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            '<ansTISS><operadoraParaPrestador>' .
            '<protocoloRecebimento><registroANS>%s</registroANS><numeroProtocolo>%s</numeroProtocolo>' .
            '<statusProtocolo>Aceito (SIMULADO)</statusProtocolo><observacao>' . self::NOTICE . '</observacao>' .
            '<tipoTransacao>%s</tipoTransacao></protocoloRecebimento>' .
            '%s</operadoraParaPrestador></ansTISS>',
            $credential->operator?->ans_code ?? '000000',
            $protocolNumber,
            htmlspecialchars($transactionType),
            $authorization,
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
