<?php

namespace App\DTOs\Billing;

/**
 * Resultado do health check do gateway. $status: ok | not_configured |
 * auth_error (401/403 — chave inválida, desabilitada ou expirada) |
 * environment_mismatch (chave de um ambiente na URL do outro) |
 * rate_limited | unreachable (timeout/5xx) | config_only (só conferiu a
 * configuração, sem chamada à API).
 */
readonly class GatewayHealthDTO
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_AUTH_ERROR = 'auth_error';

    public const STATUS_ENVIRONMENT_MISMATCH = 'environment_mismatch';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUS_CONFIG_ONLY = 'config_only';

    public function __construct(
        public bool $healthy,
        public ?int $httpStatus = null,
        public ?string $message = null,
        public ?int $latencyMs = null,
        public ?string $status = null,
        public array $details = [],
    ) {
    }

    /** Problema que o time precisa resolver (alerta ao manager). */
    public function needsAttention(): bool
    {
        return in_array($this->status, [self::STATUS_AUTH_ERROR, self::STATUS_ENVIRONMENT_MISMATCH, self::STATUS_NOT_CONFIGURED], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'healthy'     => $this->healthy,
            'status'      => $this->status ?? ($this->healthy ? self::STATUS_OK : self::STATUS_UNREACHABLE),
            'http_status' => $this->httpStatus,
            'message'     => $this->message,
            'latency_ms'  => $this->latencyMs,
            'details'     => $this->details,
        ];
    }
}
