<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Credentials;

use App\Domains\Tiss\Exceptions\TissEndpointNotAllowedException;
use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Services\Transport\TissHttpClientFactory;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Teste de conexão da credencial TISS: um GET simples no endereço de teste (ou no de envio), com a mesma
 * autenticação, TLS e certificado do envio real (TissHttpClientFactory). Nenhum lote, XML ou dado de paciente sai.
 *
 * Prova que o servidor chega à operadora, que o TLS (e o certificado do cliente) fecha e que a autenticação não é
 * recusada de cara. NÃO prova que a operadora aceita os lotes: isso só o envio em homologação mostra.
 *
 * O resultado guarda só códigos (resultado, status HTTP, tempo, motivo), nunca o corpo da resposta nem mensagens
 * de erro brutas; vai para metadata.connection_test e para a auditoria.
 */
final class TissCredentialConnectionTester
{
    public const TIMEOUT_SECONDS = 15;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @return array{result: string, status: ?int, ms: ?int, reason: ?string, at: string}
     */
    public function test(TissEntityOperatorCredential $credential, Request $request): array
    {
        $url    = filled($credential->endpoint_status) ? (string) $credential->endpoint_status : $credential->submissionEndpoint();
        $status = null;
        $ms     = null;
        $reason = null;

        if ($url === null) {
            $result = 'missing_endpoint';
        } else {
            $startedAt = microtime(true);

            try {
                $status = TissHttpClientFactory::make($credential, $url, self::TIMEOUT_SECONDS, self::CONNECT_TIMEOUT_SECONDS)
                    ->get($url)
                    ->status();

                $result = match (true) {
                    in_array($status, [401, 403], true) => 'auth_rejected',
                    $status >= 500                      => 'server_error',
                    // 404/405: o servidor respondeu (rede e TLS ok), só não atende GET nesse endereço; comum em SOAP.
                    $status >= 400 => 'responded',
                    // O envio não segue redirecionamento: o endereço cadastrado precisa ser o final.
                    $status >= 300 => 'redirect',
                    default        => 'reachable',
                };
            } catch (TissEndpointNotAllowedException $e) {
                $result = 'blocked';
                $reason = $e->reason;
            } catch (ConnectionException $e) {
                $result = 'unreachable';
                $reason = self::connectionReason($e->getMessage());
            } catch (Throwable) {
                // Ex.: certificado salvo sumiu do disco ou não abre. A mensagem bruta pode ter caminho ou segredo.
                $result = 'failed';
            }

            $ms = (int) round((microtime(true) - $startedAt) * 1000);
        }

        $test = ['result' => $result, 'status' => $status, 'ms' => $ms, 'reason' => $reason, 'at' => now()->toIso8601String()];

        // Relê com trava e grava só o resultado do teste: um cadastro salvo durante a chamada (até ~20 s) não é
        // desfeito. Sem eventos do model: a auditoria abaixo já registra quem testou e o resultado.
        DB::transaction(function () use ($credential, $test): void {
            $fresh = TissEntityOperatorCredential::query()->withoutGlobalScopes()->lockForUpdate()->find($credential->id);

            if ($fresh) {
                $fresh->metadata = [...(array) ($fresh->metadata ?? []), 'connection_test' => $test];
                $fresh->saveQuietly();
                $credential->setRawAttributes($fresh->getAttributes(), true);
            }
        });

        $this->audit->recordAdminAction(
            event: 'tiss.credential.connection_tested',
            targetEntityId: (string) $credential->entity_id,
            targetUserId: null,
            auditableType: TissEntityOperatorCredential::class,
            auditableId: (string) $credential->id,
            reason: 'Teste de conexão da credencial TISS',
            newValues: [...$test, 'host' => $url !== null ? parse_url($url, PHP_URL_HOST) : null],
            request: $request,
        );

        return $test;
    }

    /** @param array{result: string, status?: ?int, ms?: ?int, reason?: ?string} $test */
    public static function describe(array $test): string
    {
        $reason = filled($test['reason'] ?? null) ? (string) __('tiss_credentials.test.reasons.' . $test['reason']) : '';

        return (string) __('tiss_credentials.test.results.' . $test['result'], [
            'status' => (string) ($test['status'] ?? '—'),
            'ms'     => (string) ($test['ms'] ?? '—'),
            'reason' => $reason,
        ]);
    }

    /** Motivo da falha de conexão em código fixo: a mensagem do cURL pode trazer endereço, caminho e detalhes do TLS. */
    private static function connectionReason(string $message): string
    {
        return match (true) {
            str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false          => 'timeout',
            str_contains($message, 'cURL error 6:') || stripos($message, 'resolve host') !== false       => 'dns',
            str_contains($message, 'cURL error 7:') || stripos($message, 'connection refused') !== false => 'refused',
            preg_match('/cURL error (35|51|53|54|58|59|60|77|80|82|83|90|91):/', $message) === 1
                || stripos($message, 'ssl') !== false || stripos($message, 'certificate') !== false => 'tls',
            default                                                                                 => 'other',
        };
    }
}
