<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard};
use App\Exceptions\Fiscal\FocusNfeException;
use Illuminate\Http\Client\{ConnectionException, Response};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Http, Log};
use InvalidArgumentException;

/**
 * Cliente da API da Focus NFe (https://doc.focusnfe.com.br) para a NFS-e do SaaS. Um cliente = um ambiente + um token
 * (da empresa ou o master); `NfseCredentialResolver::client()` entrega o certo.
 *
 * - Autenticação: HTTP Basic, usuário = token, senha vazia. O token só existe no cabeçalho — nunca em URL, corpo, log,
 *   exceção nem resposta devolvida.
 * - Sem seguir redirecionamento (o token não pode ir para outro host).
 * - Log: só `op`, `ref`, `http`, `status`, `duracao_ms`, `correlation_id` (allowlist); nenhum corpo ou cabeçalho.
 * - Erro vira FocusNfeException (código, HTTP e os erros da Focus). Propriedades desconhecidas na resposta são
 *   toleradas: o corpo volta como array e quem lê escolhe o que usa.
 *
 * A API é assíncrona: `issue()` só confirma que o pedido entrou na fila; o desfecho vem por webhook ou por `show()`.
 */
final class FocusNfeClient
{
    /** Chaves permitidas no log. */
    private const LOG_ALLOWLIST = ['op', 'ref', 'http', 'status', 'codigo', 'duracao_ms', 'correlation_id'];

    private function __construct(
        private readonly NfseEnvironment $environment,
        private readonly string $token,
        private readonly ?string $correlationId = null,
    ) {
    }

    public static function make(NfseEnvironment $environment, string $token, ?string $correlationId = null): self
    {
        return new self($environment, $token, $correlationId);
    }

    public function environment(): NfseEnvironment
    {
        return $this->environment;
    }

    // ── Notas ────────────────────────────────────────────────────────────────

    /**
     * Pede a emissão (POST /v2/{nfse|nfsen}?ref=). 201/202 = aceito para processamento; a pré-validação síncrona recusa
     * com 400 (requisicao_invalida) ou 422 (erro_validacao / nfe_autorizada).
     *
     * @return array<string, mixed>
     */
    public function issue(NfseStandard $standard, string $ref, array $payload): array
    {
        return $this->json('issue', 'POST', "/v2/{$standard->endpoint()}", ref: $ref, query: ['ref' => $ref], body: $payload);
    }

    /**
     * Consulta a nota (GET /v2/{nfse|nfsen}/{ref}). Null = a Focus não conhece essa ref (404).
     *
     * @return array<string, mixed>|null
     */
    public function show(NfseStandard $standard, string $ref): ?array
    {
        try {
            return $this->json('show', 'GET', "/v2/{$standard->endpoint()}/" . rawurlencode($ref), ref: $ref);
        } catch (FocusNfeException $e) {
            if ($e->errorCode === FocusNfeException::NOT_FOUND) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Cancela (DELETE /v2/{nfse|nfsen}/{ref}, síncrono, definitivo). Volta `{status: cancelado}` ou
     * `{status: erro_cancelamento, erros: [...]}` — o segundo NÃO é exceção: é um desfecho que a tela mostra.
     * 400 `nfe_nao_autorizada` e demais recusas lançam.
     *
     * @return array<string, mixed>
     */
    public function cancel(NfseStandard $standard, string $ref, string $justification): array
    {
        return $this->json('cancel', 'DELETE', "/v2/{$standard->endpoint()}/" . rawurlencode($ref), ref: $ref, body: ['justificativa' => $justification]);
    }

    /**
     * Manda cópia da nota por e-mail (POST /v2/{nfse|nfsen}/{ref}/email, até 10 destinatários por vez).
     *
     * @param list<string> $emails
     *
     * @return array<string, mixed>
     */
    public function email(NfseStandard $standard, string $ref, array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map('trim', $emails))));

        if ($emails === [] || count($emails) > 10) {
            throw new InvalidArgumentException('A Focus aceita de 1 a 10 destinatários por envio.');
        }

        return $this->json('email', 'POST', "/v2/{$standard->endpoint()}/" . rawurlencode($ref) . '/email', ref: $ref, body: ['emails' => $emails]);
    }

    /**
     * Baixa um arquivo da Focus (XML da nota ou do cancelamento) pelo caminho RELATIVO que ela devolve
     * (`caminho_xml_nota_fiscal`, ex.: /arquivos/123_1/202610/XMLsNFSe/NFS….xml), com o mesmo token. Só caminho dentro de
     * /arquivos/: nunca URL absoluta nem `..` — o token só viaja para o host do ambiente.
     */
    public function download(string $relativePath): string
    {
        if (preg_match('#^/arquivos/[A-Za-z0-9_\-./]+$#', $relativePath) !== 1 || str_contains($relativePath, '..')) {
            throw new InvalidArgumentException('Caminho de arquivo da Focus inválido.');
        }

        return $this->send('download', 'GET', $relativePath, accept: '*/*')->body();
    }

    // ── Webhook (gatilho) ────────────────────────────────────────────────────

    /**
     * Registra o gatilho (POST /v2/hooks): a Focus passa a fazer POST JSON em `$url` a cada mudança de status, mandando
     * `$authorization` no cabeçalho `$authorizationHeader`.
     *
     * @return array<string, mixed> {id, url, event, cnpj, ...}
     */
    public function registerHook(string $cnpj, NfseStandard $standard, string $url, string $authorization, string $authorizationHeader): array
    {
        return $this->json('hook.create', 'POST', '/v2/hooks', body: [
            'cnpj'                 => $cnpj,
            'event'                => $standard->hookEvent(),
            'url'                  => $url,
            'authorization'        => $authorization,
            'authorization_header' => $authorizationHeader,
        ]);
    }

    /**
     * Gatilhos da conta (GET /v2/hooks). Também é a chamada barata e não fiscal do "testar credencial".
     *
     * @return list<array<string, mixed>>
     */
    public function listHooks(): array
    {
        $hooks = $this->json('hook.list', 'GET', '/v2/hooks');

        return array_values(array_filter($hooks, 'is_array'));
    }

    /** Remove o gatilho (DELETE /v2/hooks/{id}); 404 = já não existe (conta como removido). */
    public function deleteHook(string $hookId): void
    {
        try {
            $this->json('hook.delete', 'DELETE', '/v2/hooks/' . rawurlencode($hookId));
        } catch (FocusNfeException $e) {
            if ($e->errorCode !== FocusNfeException::NOT_FOUND) {
                throw $e;
            }
        }
    }

    // ── Municípios (token master) ────────────────────────────────────────────

    /**
     * Metadados de NFS-e do município (GET /v2/municipios/{ibge}): habilitado, cancelamento por webservice,
     * obrigatoriedades (endereço, CNPJ/CPF, CNAE, item, código tributário), provedor, status.
     *
     * @return array<string, mixed>
     */
    public function municipality(string $ibge): array
    {
        return $this->json('municipality', 'GET', '/v2/municipios/' . rawurlencode($ibge));
    }

    /**
     * Busca municípios por parte do nome e/ou UF (GET /v2/municipios?nome=&sigla_uf=&limit=).
     *
     * @return list<array<string, mixed>>
     */
    public function searchMunicipalities(?string $name, ?string $uf, int $limit = 20): array
    {
        $query = array_filter([
            'nome'     => $name !== null && trim($name) !== '' ? trim($name) : null,
            'sigla_uf' => $uf !== null && trim($uf) !== '' ? strtoupper(trim($uf)) : null,
            'limit'    => max(1, min(50, $limit)),
        ], static fn ($value) => $value !== null);

        $rows = $this->json('municipality.search', 'GET', '/v2/municipios', query: $query);

        return array_values(array_filter($rows, 'is_array'));
    }

    // ── Infraestrutura HTTP ──────────────────────────────────────────────────

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function json(string $op, string $method, string $path, ?string $ref = null, array $query = [], ?array $body = null): array
    {
        $data = $this->send($op, $method, $path, $ref, $query, $body)->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, scalar> $query
     */
    private function send(string $op, string $method, string $path, ?string $ref = null, array $query = [], ?array $body = null, string $accept = 'application/json'): Response
    {
        $started = hrtime(true);

        try {
            $request = Http::baseUrl($this->environment->baseUrl())
                ->withBasicAuth($this->token, '')
                ->withHeaders(['Accept' => $accept])
                ->timeout((int) config('fiscal.timeout', 30))
                ->connectTimeout((int) config('fiscal.connect_timeout', 10))
                ->withoutRedirecting()
                ->withQueryParameters($query);

            $response = match ($method) {
                'POST'   => $request->post($path, $body ?? []),
                'DELETE' => $request->delete($path, $body ?? []),
                default  => $request->get($path),
            };
        } catch (ConnectionException $e) {
            $this->log('warning', ['op' => $op, 'ref' => $ref, 'codigo' => FocusNfeException::UNAVAILABLE, 'duracao_ms' => $this->elapsed($started)]);

            throw FocusNfeException::connection($e);
        }

        $context = ['op' => $op, 'ref' => $ref, 'http' => $response->status(), 'duracao_ms' => $this->elapsed($started)];

        if ($response->successful()) {
            $status = $response->json('status');

            $this->log('info', $context + (is_string($status) ? ['status' => $status] : []));

            return $response;
        }

        $exception = FocusNfeException::fromResponse($response);

        $this->log('warning', $context + ['codigo' => $exception->errorCode]);

        throw $exception;
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, array $context): void
    {
        $context['correlation_id'] = $this->correlationId;

        Log::{$level}('nfse.focus', Arr::only($context, self::LOG_ALLOWLIST));
    }
}
