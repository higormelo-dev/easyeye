<?php

namespace App\DTOs\Billing;

readonly class CustomerDTO
{
    /**
     * $address: endereço do cadastro da empresa (chaves zipcode, street,
     * number, complement, district, city, state — CEP só dígitos, UF em
     * sigla), null sem nenhum dado. Usado pelos gateways que pedem endereço
     * no boleto (Pagar.me, Mercado Pago, PagBank).
     */
    public function __construct(
        public string $entityId,
        public string $name,
        public ?string $email,
        public ?string $document,
        public ?string $phone,
        public ?string $externalReference = null,
        public array $metadata = [],
        public ?array $address = null,
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
            'address'            => $this->address,
            'metadata'           => $this->metadata,
        ];
    }
}
