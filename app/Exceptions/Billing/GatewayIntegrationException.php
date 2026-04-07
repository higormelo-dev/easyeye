<?php

namespace App\Exceptions\Billing;

class GatewayIntegrationException extends BillingException
{
    private string $triggerType;

    public function __construct(string $message = '', string $triggerType = 'unknown', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->triggerType = $triggerType;
    }

    /** Tipo de gatilho para o FallbackGatewayService: timeout | http_5xx | gateway_unavailable | provider_rate_limit */
    public function getTriggerType(): string
    {
        return $this->triggerType;
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

    public static function rateLimited(string $gatewayCode): self
    {
        return new self(
            message: sprintf('[%s] Rate limit atingido.', $gatewayCode),
            triggerType: 'provider_rate_limit',
        );
    }

    public static function fromHttpStatus(string $gatewayCode, int $httpStatus, string $body = ''): self
    {
        if ($httpStatus === 429) {
            return self::rateLimited($gatewayCode);
        }

        if ($httpStatus >= 500) {
            return self::serverError($gatewayCode, $httpStatus, $body);
        }

        return new self(
            message: sprintf('[%s] Erro HTTP %d: %s', $gatewayCode, $httpStatus, mb_substr($body, 0, 500)),
            triggerType: 'gateway_unavailable',
        );
    }
}
