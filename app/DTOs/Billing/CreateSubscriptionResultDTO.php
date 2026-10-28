<?php

namespace App\DTOs\Billing;

readonly class CreateSubscriptionResultDTO
{
    /**
     * @param int|null $httpStatus status HTTP da resposta de falha: 5xx (ou
     *                             nulo) não garante que o gateway não criou a
     *                             recorrência; 4xx garante
     */
    public function __construct(
        public bool $success,
        public ?string $externalSubscriptionId,
        public ?string $externalCustomerId,
        public ?string $status,
        public ?array $rawResponse,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
    ) {
    }
}
