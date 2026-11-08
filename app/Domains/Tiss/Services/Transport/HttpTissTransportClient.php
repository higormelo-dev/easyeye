<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Exceptions\{TissCredentialIncompleteException, TissEndpointNotAllowedException};
use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use Throwable;

class HttpTissTransportClient implements TissMessageTransportContract, TissTransportContract
{
    /** Recusado antes de conectar (destino ou certificado): nada saiu, então não conta como entrega em dúvida. */
    public const NOT_SENT = 'not_sent';

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function sendBatch(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        array $context = [],
    ): array {
        return $this->post($credential, $xml, $idempotencyKey, [
            'X-Batch-Number' => (string) ($context['batch_number'] ?? ''),
        ]);
    }

    /**
     * Mensagem avulsa (sem lote): mesmo endpoint, credenciais e política de erro do
     * lote; o cabeçalho diz qual transação é e de qual guia.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        string $transactionType,
        array $context = [],
    ): array {
        return $this->post($credential, $xml, $idempotencyKey, [
            'X-Tiss-Transaction' => $transactionType,
            'X-Guide-Number'     => (string) ($context['guide_number'] ?? ''),
        ]);
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function post(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $extraHeaders): array
    {
        $endpoint = $credential->submissionEndpoint();

        if (! $endpoint) {
            return [
                'ok'               => false,
                'status_code'      => null,
                'response_body'    => null,
                'protocol_number'  => null,
                'protocol_status'  => null,
                'error_code'       => 'missing_endpoint',
                'error_message'    => 'Endpoint de submissão TISS não configurado para a operadora.',
                'response_time_ms' => null,
            ];
        }

        $timeout        = (int) config('tiss.transport.http.timeout_seconds', 30);
        $connectTimeout = (int) config('tiss.transport.http.connect_timeout_seconds', 10);

        try {
            // Autenticação, TLS, certificado do cliente e trava de destino: os mesmos do teste de conexão da tela.
            $client = TissHttpClientFactory::make($credential, $endpoint, $timeout, $connectTimeout);
        } catch (TissEndpointNotAllowedException|TissCredentialIncompleteException $exception) {
            // Recusado aqui, antes de qualquer conexão: a operadora com certeza não recebeu nada (não é dúvida).
            return [
                'ok'               => false,
                'status_code'      => null,
                'response_body'    => null,
                'protocol_number'  => null,
                'protocol_status'  => null,
                'error_code'       => self::NOT_SENT,
                'error_message'    => $exception->getMessage(),
                'response_time_ms' => null,
            ];
        }

        $startedAt = microtime(true);

        try {
            $request = $client
                ->accept('application/xml')
                ->contentType('application/xml; charset=UTF-8')
                ->withHeaders([
                    'X-Idempotency-Key'  => $idempotencyKey,
                    'X-Entity-Id'        => (string) $credential->entity_id,
                    'X-Tiss-Environment' => (string) $credential->environment,
                    ...$extraHeaders,
                ]);

            $response     = $request->send('POST', $endpoint, ['body' => $xml]);
            $responseBody = (string) $response->body();

            return [
                'ok'               => $response->successful(),
                'status_code'      => $response->status(),
                'response_body'    => $responseBody,
                'protocol_number'  => $this->extractFirstTagValue($responseBody, 'numeroProtocolo'),
                'protocol_status'  => $this->extractProtocolStatus($responseBody),
                'error_code'       => $response->successful() ? null : 'http_' . $response->status(),
                'error_message'    => $response->successful() ? null : mb_substr($responseBody, 0, 1500),
                'response_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $exception) {
            return [
                'ok'              => false,
                'status_code'     => null,
                'response_body'   => null,
                'protocol_number' => null,
                'protocol_status' => null,
                'error_code'      => 'transport_exception',
                // A mensagem do cURL/Guzzle pode trazer o caminho absoluto do certificado no servidor.
                'error_message'    => str_replace(storage_path(), '[storage]', $exception->getMessage()),
                'response_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private function extractFirstTagValue(string $xml, string $tag): ?string
    {
        $pattern = sprintf('/<%s>(.*?)<\\/%s>/is', preg_quote($tag, '/'), preg_quote($tag, '/'));

        if (! preg_match($pattern, $xml, $matches)) {
            return null;
        }

        $value = trim(strip_tags((string) ($matches[1] ?? '')));

        return $value === '' ? null : $value;
    }

    private function extractProtocolStatus(string $xml): ?string
    {
        $raw = $this->extractFirstTagValue($xml, 'statusProtocolo')
            ?? $this->extractFirstTagValue($xml, 'situacaoProtocolo');

        if (! $raw) {
            return null;
        }

        $normalized = mb_strtolower($raw);

        return match (true) {
            str_contains($normalized, 'aceit')   => 'accepted',
            str_contains($normalized, 'rejeit')  => 'rejected',
            str_contains($normalized, 'process') => 'processing',
            default                              => 'received',
        };
    }
}
