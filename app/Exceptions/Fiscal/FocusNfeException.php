<?php

declare(strict_types=1);

namespace App\Exceptions\Fiscal;

use App\Support\Fiscal\NfseLogText;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Falha numa chamada à Focus NFe. A mensagem da exceção é só para log (código + HTTP, nunca corpo, header nem token);
 * o que vai à tela é `userMessage()` (traduzido) e `errors` (os erros da própria Focus, campo a campo).
 *
 * Códigos (mesmos nomes da API quando existem):
 *  - auth: 401/403 (token recusado, de outro ambiente ou sem acesso) — não adianta repetir até trocar o token;
 *  - requisicao_invalida / erro_validacao: 400 / 422 — o pedido foi recusado, repetir igual dá o mesmo erro;
 *  - nfe_autorizada: 422, a ref já tem nota autorizada;
 *  - nfe_nao_autorizada: 400, tentou cancelar nota que não está autorizada;
 *  - nao_encontrado: 404;
 *  - rate_limited: 429 — repetir depois;
 *  - http: 5xx, timeout ou conexão — a Focus pode ter feito o que foi pedido: conferir antes de repetir;
 *  - credencial_ausente: nada foi chamado (token do ambiente não configurado).
 */
final class FocusNfeException extends RuntimeException
{
    public const AUTH = 'auth';

    public const INVALID_REQUEST = 'requisicao_invalida';

    public const VALIDATION = 'erro_validacao';

    public const ALREADY_AUTHORIZED = 'nfe_autorizada';

    public const NOT_AUTHORIZED = 'nfe_nao_autorizada';

    public const NOT_FOUND = 'nao_encontrado';

    public const RATE_LIMITED = 'rate_limited';

    public const UNAVAILABLE = 'http';

    public const NOT_CONFIGURED = 'credencial_ausente';

    /**
     * @param list<array{codigo: ?string, mensagem: string, correcao: ?string}> $errors
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status = 0,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Focus NFe: {$errorCode}" . ($status > 0 ? " (HTTP {$status})" : ''), $status, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $body   = $response->json();
        $body   = is_array($body) ? $body : [];

        $code = match (true) {
            in_array($status, [401, 403], true) => self::AUTH,
            $status === 404                     => self::NOT_FOUND,
            $status === 429                     => self::RATE_LIMITED,
            $status >= 500                      => self::UNAVAILABLE,
            default                             => self::classify($status, (string) ($body['codigo'] ?? '')),
        };

        return new self($code, $status, self::errorsFrom($body), $code === self::RATE_LIMITED ? self::retryAfterFrom($response) : null);
    }

    /** Timeout ou falha de conexão: não se sabe se a Focus recebeu o pedido. */
    public static function connection(?Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, 0, [], null, $previous);
    }

    /** O ambiente não tem o token pedido (empresa ou master) gravado. */
    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED);
    }

    /** Pode valer a pena repetir mais tarde (sem mudar nada). */
    public function retryable(): bool
    {
        return in_array($this->errorCode, [self::RATE_LIMITED, self::UNAVAILABLE], true);
    }

    /** A Focus pode ter processado o pedido sem a gente ter visto a resposta. */
    public function inconclusive(): bool
    {
        return in_array($this->errorCode, [self::RATE_LIMITED, self::UNAVAILABLE], true);
    }

    /** Texto da tela: traduzido, com o primeiro erro da Focus quando ela explica o motivo. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            self::AUTH           => __('fiscal.errors.focus_auth'),
            self::NOT_CONFIGURED => __('fiscal.errors.credencial_ausente'),
            self::NOT_FOUND      => __('fiscal.errors.focus_not_found'),
            self::RATE_LIMITED   => __('fiscal.errors.focus_rate_limited'),
            self::UNAVAILABLE    => __('fiscal.errors.focus_unavailable'),
            self::NOT_AUTHORIZED => __('fiscal.errors.nota_nao_autorizada'),
            default              => __('fiscal.errors.focus_rejected', ['detail' => $this->errors[0]['mensagem'] ?? '']),
        };
    }

    private static function classify(int $status, string $codigo): string
    {
        return match (true) {
            $codigo === self::ALREADY_AUTHORIZED => self::ALREADY_AUTHORIZED,
            $codigo === self::NOT_AUTHORIZED     => self::NOT_AUTHORIZED,
            $codigo === self::VALIDATION         => self::VALIDATION,
            $codigo === self::INVALID_REQUEST    => self::INVALID_REQUEST,
            $status === 422                      => self::VALIDATION,
            default                              => self::INVALID_REQUEST,
        };
    }

    /**
     * A Focus responde `{codigo, mensagem, correcao?}` (erro único) ou `{erros: [{codigo, mensagem, correcao}]}`.
     *
     * @return list<array{codigo: ?string, mensagem: string, correcao: ?string}>
     */
    public static function errorsFrom(array $body): array
    {
        $raw = isset($body['erros']) && is_array($body['erros'])
            ? $body['erros']
            : (isset($body['mensagem']) ? [$body] : []);

        $errors = [];

        foreach ($raw as $error) {
            if (! is_array($error) || ! isset($error['mensagem'])) {
                continue;
            }

            $errors[] = [
                'codigo'   => isset($error['codigo']) ? NfseLogText::clean((string) $error['codigo'], 40) : null,
                'mensagem' => NfseLogText::clean((string) $error['mensagem'], 500),
                'correcao' => isset($error['correcao']) ? NfseLogText::clean((string) $error['correcao'], 500) : null,
            ];
        }

        return $errors;
    }

    private static function retryAfterFrom(Response $response): ?int
    {
        foreach (['Retry-After', 'RateLimit-Reset', 'X-RateLimit-Reset'] as $header) {
            $value = trim((string) $response->header($header));

            if ($value !== '' && ctype_digit($value)) {
                $seconds = (int) $value;

                // Alguns servidores mandam o instante (epoch) em vez dos segundos.
                if ($seconds > 1_000_000_000) {
                    $seconds -= time();
                }

                return max(1, min(3600, $seconds));
            }
        }

        return null;
    }
}
