<?php

namespace App\DTOs\Billing;

readonly class HostedCheckoutResultDTO
{
    public function __construct(
        public bool $success,
        public ?string $externalCheckoutId,
        public ?string $url,
        public ?string $status = null,
        public ?int $minutesToExpire = null,
        public array $rawResponse = [],
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        // O gateway recusou o cliente anexado (cadastro incompleto) e a sessão abriu SEM ele: a página coleta os dados.
        public bool $customerOmitted = false,
        // Códigos (sem PII) do erro que levou a abrir sem o cliente.
        public array $omittedReasons = [],
    ) {
    }
}
