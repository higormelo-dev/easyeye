<?php

namespace App\Services\Billing;

use App\DTOs\Billing\GatewayWebhookInputDTO;
use App\Exceptions\Billing\GatewayUnauthorizedException;
use App\Models\Billing\WebhookEvent;
use App\Support\Billing\PayloadSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
            // Chave de idempotência de cada gateway (mesma notificação = mesma
            // chave; pago, estorno e chargeback do mesmo recurso, chaves diferentes).
            externalEventId: $gateway->webhookEventKey($payload),
            signature: $this->extractSignature($headers),
            receivedAt: now()->toIso8601String(),
        );

        if (! $gateway->validateWebhookSignature($dto)) {
            throw new GatewayUnauthorizedException("Assinatura do webhook inválida para gateway [{$gatewayCode}].");
        }

        $eventHash = hash('sha256', $gatewayCode . '|' . $body);

        try {
            $event = DB::transaction(fn () => WebhookEvent::query()->create([
                'gateway_code'      => $gatewayCode,
                'external_event_id' => $dto->externalEventId,
                'event_type'        => Arr::get($payload, 'event') ?? Arr::get($payload, 'type'),
                'event_hash'        => $eventHash,
                'signature'         => $dto->signature,
                // Sem os headers com segredo (Basic Auth, token do Asaas…).
                'headers' => PayloadSanitizer::storableHeaders($headers),
                // Sem dado pessoal do pagador (nome, documento, e-mail,
                // telefone, endereço, cartão): só o que o reprocessamento usa.
                // A assinatura já foi conferida acima no corpo bruto.
                'payload'        => PayloadSanitizer::cleanPersonal($payload),
                'status'         => 'received',
                'received_at'    => now(),
                'correlation_id' => $correlationId,
            ]));
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
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
