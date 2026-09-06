<?php

namespace App\DTOs\Billing;

readonly class GatewayWebhookInputDTO
{
    public array $headers;

    public function __construct(
        public string $gatewayCode,
        array $headers,
        public string $body,
        public array $payload,
        public ?string $externalEventId = null,
        public ?string $signature = null,
        public ?string $receivedAt = null,
    ) {
        $this->headers = self::normalizeHeaders($headers);
    }

    /**
     * BUGFIX (revisao de seguranca, achado de follow-up): Symfony's
     * HeaderBag::all() (usado em WebhookController::__invoke()) sempre
     * devolve cada header como array, mesmo de valor único. Os 7 gateways de
     * pagamento (validateWebhookSignature) leem $payload->headers['x-...']
     * esperando string — is_string() dava false pra um array de 1 elemento,
     * então uma vez configurado webhook_secret, um webhook REAL e
     * corretamente assinado era rejeitado (quebra funcional, não brecha).
     * Normaliza aqui, uma vez, pros dois pontos de construção deste DTO
     * (WebhookIngestionService e ProcessWebhookEventService).
     */
    private static function normalizeHeaders(array $headers): array
    {
        return array_map(
            static fn ($value) => is_array($value) ? ($value[0] ?? null) : $value,
            $headers,
        );
    }
}
