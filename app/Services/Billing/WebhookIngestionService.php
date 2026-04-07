<?php

namespace App\Services\Billing;

use App\DTOs\Billing\GatewayWebhookInputDTO;
use App\Exceptions\Billing\GatewayUnauthorizedException;
use App\Models\Billing\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

class WebhookIngestionService
{
    public function __construct(
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly CorrelationIdService $correlationIdService,
        private readonly BillingLogService $billingLogService,
    ) {
    }

    public function ingest(string $gatewayCode, array $headers, string $body, ?string $correlationId = null): WebhookEvent
    {
        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];
        $correlationId ??= $this->correlationIdService->resolve();

        $gateway = $this->gatewayRegistry->get($gatewayCode);

        $dto = new GatewayWebhookInputDTO(
            gatewayCode: $gatewayCode,
            headers: $headers,
            body: $body,
            payload: $payload,
            externalEventId: $this->extractExternalEventId($payload),
            signature: $this->extractSignature($headers),
            receivedAt: now()->toIso8601String(),
        );

        if (! $gateway->validateWebhookSignature($dto)) {
            throw new GatewayUnauthorizedException("Assinatura do webhook inválida para gateway [{$gatewayCode}].");
        }

        $eventHash = hash('sha256', $gatewayCode . '|' . $body);

        try {
            $event = WebhookEvent::query()->create([
                'gateway_code'      => $gatewayCode,
                'external_event_id' => $dto->externalEventId,
                'event_type'        => Arr::get($payload, 'event') ?? Arr::get($payload, 'type'),
                'event_hash'        => $eventHash,
                'signature'         => $dto->signature,
                'headers'           => $headers,
                'payload'           => $payload,
                'status'            => 'received',
                'received_at'       => now(),
                'correlation_id'    => $correlationId,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $event = WebhookEvent::query()
                    ->where('gateway_code', $gatewayCode)
                    ->where(function ($q) use ($dto, $eventHash): void {
                        if ($dto->externalEventId) {
                            $q->where('external_event_id', $dto->externalEventId);
                        }
                        $q->orWhere('event_hash', $eventHash);
                    })
                    ->firstOrFail();
            } else {
                throw $e;
            }
        }

        $this->billingLogService->log(
            level: 'info',
            message: 'Webhook recebido.',
            context: [
                'gateway'           => $gatewayCode,
                'external_event_id' => $dto->externalEventId,
                'webhook_event_id'  => $event->id,
            ],
            entityId: $event->entity_id,
            webhookEvent: $event,
            gatewayCode: $gatewayCode,
            correlationId: $correlationId,
        );

        return $event;
    }

    private function extractExternalEventId(array $payload): ?string
    {
        $paths = ['id', 'event_id', 'data.id', 'resource.id'];

        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractSignature(array $headers): ?string
    {
        $keys = ['x-signature', 'X-Signature', 'x-hub-signature', 'X-Hub-Signature'];

        foreach ($keys as $key) {
            $value = $headers[$key] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
