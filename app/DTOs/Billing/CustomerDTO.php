<?php

namespace App\DTOs\Billing;

readonly class CustomerDTO
{
    public function __construct(
        public string $entityId,
        public string $name,
        public ?string $email,
        public ?string $document,
        public ?string $phone,
        public ?string $externalReference = null,
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'name'               => $this->name,
            'email'              => $this->email,
            'document'           => $this->document,
            'phone'              => $this->phone,
            'external_reference' => $this->externalReference,
            'metadata'           => $this->metadata,
        ];
    }
}
