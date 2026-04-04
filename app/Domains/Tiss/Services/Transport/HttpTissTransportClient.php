<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use Illuminate\Support\Facades\{Crypt, Http};
use Throwable;

class HttpTissTransportClient implements TissTransportContract
{
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
        $endpoint = $credential->endpoint_submission
            ?: ($credential->operator?->webservice_base_url ? rtrim((string) $credential->operator->webservice_base_url, '/') . '/tiss' : null);

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
        $verifySsl      = (bool) $credential->ssl_enabled;

        $startedAt = microtime(true);

        try {
            $request = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->accept('application/xml')
                ->contentType('application/xml; charset=UTF-8')
                ->withHeaders([
                    'X-Idempotency-Key'  => $idempotencyKey,
                    'X-Entity-Id'        => (string) $credential->entity_id,
                    'X-Tiss-Environment' => (string) $credential->environment,
                    'X-Batch-Number'     => (string) ($context['batch_number'] ?? ''),
                ])
                ->withOptions([
                    'verify' => $verifySsl,
                ]);

            if ($credential->username && $credential->password_encrypted) {
                $request = $request->withBasicAuth(
                    (string) $credential->username,
                    $this->decryptValue((string) $credential->password_encrypted),
                );
            }

            if ($credential->token_encrypted) {
                $request = $request->withToken($this->decryptValue((string) $credential->token_encrypted));
            }

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
                'ok'               => false,
                'status_code'      => null,
                'response_body'    => null,
                'protocol_number'  => null,
                'protocol_status'  => null,
                'error_code'       => 'transport_exception',
                'error_message'    => $exception->getMessage(),
                'response_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private function decryptValue(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Compatibilidade com bases antigas que podem armazenar em texto puro.
            return $value;
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
