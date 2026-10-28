<?php

namespace App\Exceptions\Billing;

use Throwable;

class GatewayIntegrationException extends BillingException
{
    /** Credencial recusada pelo gateway (401/403: chave inválida, desabilitada, de outro ambiente ou IP fora da whitelist). */
    public const TRIGGER_AUTH = 'auth_error';

    public const TRIGGER_RATE_LIMIT = 'provider_rate_limit';

    private string $triggerType;

    /** Segundos até poder chamar de novo (429: RateLimit-Reset/Retry-After). */
    private ?int $retryAfter = null;

    private ?int $httpStatus = null;

    public function __construct(string $message = '', string $triggerType = 'unknown', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->triggerType = $triggerType;
    }

    /** Tipo de gatilho para o FallbackGatewayService: timeout | http_5xx | gateway_unavailable | provider_rate_limit | auth_error */
    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function isRateLimit(): bool
    {
        return $this->triggerType === self::TRIGGER_RATE_LIMIT;
    }

    public function isAuthError(): bool
    {
        return $this->triggerType === self::TRIGGER_AUTH;
    }

    /**
     * Falha sem resposta definitiva (timeout, conexão, 5xx, limite): o
     * gateway pode ter feito o que foi pedido — conferir antes de repetir.
     */
    public function isInconclusive(): bool
    {
        return in_array($this->triggerType, ['timeout', 'http_5xx', self::TRIGGER_RATE_LIMIT], true);
    }

    public function withHttpStatus(?int $status): self
    {
        $this->httpStatus = $status;

        return $this;
    }

    public static function timeout(string $gatewayCode, string $detail = ''): self
    {
        return new self(
            message: sprintf('[%s] Timeout na chamada ao gateway. %s', $gatewayCode, $detail),
            triggerType: 'timeout',
        );
    }

    public static function serverError(string $gatewayCode, int $httpStatus, string $body = ''): self
    {
        return new self(
            message: sprintf('[%s] Erro HTTP %d: %s', $gatewayCode, $httpStatus, mb_substr($body, 0, 500)),
            triggerType: 'http_5xx',
        );
    }

    public static function unavailable(string $gatewayCode, string $detail = ''): self
    {
        return new self(
            message: sprintf('[%s] Gateway indisponível. %s', $gatewayCode, $detail),
            triggerType: 'gateway_unavailable',
        );
    }

    /**
     * 429: limite da API atingido. $retryAfter = segundos até liberar
     * (RateLimit-Reset/Retry-After) — quem chama não repete antes disso
     * (https://docs.asaas.com/reference/rate-e-quota-limit).
     */
    public static function rateLimited(string $gatewayCode, ?int $retryAfter = null): self
    {
        $e = new self(
            message: sprintf('[%s] Rate limit atingido.%s', $gatewayCode, $retryAfter !== null ? " Tentar de novo em {$retryAfter}s." : ''),
            triggerType: self::TRIGGER_RATE_LIMIT,
        );
        $e->retryAfter = $retryAfter;
        $e->httpStatus = 429;

        return $e;
    }

    /** 401/403: credencial recusada (chave inválida/expirada/desabilitada, ambiente errado, IP fora da whitelist). */
    public static function authFailed(string $gatewayCode, int $httpStatus, string $body = ''): self
    {
        return (new self(
            message: sprintf('[%s] Credencial recusada (HTTP %d): %s', $gatewayCode, $httpStatus, mb_substr($body, 0, 500)),
            triggerType: self::TRIGGER_AUTH,
        ))->withHttpStatus($httpStatus);
    }

    public static function fromHttpStatus(string $gatewayCode, int $httpStatus, string $body = ''): self
    {
        if ($httpStatus === 429) {
            return self::rateLimited($gatewayCode);
        }

        if ($httpStatus >= 500) {
            return self::serverError($gatewayCode, $httpStatus, $body)->withHttpStatus($httpStatus);
        }

        return (new self(
            message: sprintf('[%s] Erro HTTP %d: %s', $gatewayCode, $httpStatus, mb_substr($body, 0, 500)),
            triggerType: 'gateway_unavailable',
        ))->withHttpStatus($httpStatus);
    }
}
