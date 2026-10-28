<?php

namespace App\DTOs\Billing;

readonly class CreateChargeDTO
{
    public function __construct(
        public string $entityId,
        public string $invoiceId,
        public string $subscriptionId,
        public string $customerId,
        public float $amount,
        public string $currency,
        public string $description,
        public ?string $dueDate = null,
        public ?string $paymentMethod = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
        // Retentativa depois de falha sem resposta definitiva (timeout/5xx):
        // antes de criar, o gateway procura uma cobrança com a nossa
        // referência que não seja nenhuma destas (as que já conhecemos) e a
        // reaproveita — sem idempotência no gateway (Asaas), é o que evita a
        // cobrança em dobro (https://docs.asaas.com/docs/cobrança-duplicada-após-retry-sem-idempotência).
        // Também usado depois de um timeout/5xx da própria criação: as
        // conhecidas nunca são "a nova" (a vigente vencida tem a mesma
        // referência). Sempre informar.
        public ?array $knownChargeIds = null,
        // Procurar antes de criar (a tentativa anterior foi inconclusiva).
        // null = legado: procura quando knownChargeIds foi informado.
        public ?bool $lookupBeforeCreate = null,
    ) {
    }

    public function shouldLookupBeforeCreate(): bool
    {
        return $this->lookupBeforeCreate ?? $this->knownChargeIds !== null;
    }

    public function toArray(): array
    {
        return [
            'customer_id'    => $this->customerId,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'description'    => $this->description,
            'due_date'       => $this->dueDate,
            'payment_method' => $this->paymentMethod,
            'metadata'       => array_merge($this->metadata, [
                'entity_id'       => $this->entityId,
                'invoice_id'      => $this->invoiceId,
                'subscription_id' => $this->subscriptionId,
            ]),
        ];
    }
}
