<?php

namespace App\Support\Billing;

use App\DTOs\Billing\CustomerDTO;
use App\Models\Entity;
use Stringable;

/**
 * Dados do pagador (a empresa) para o gateway, montados do cadastro da
 * Entity num só lugar: ativação (BillingSubscriptionOrchestrator), renovação
 * local (RenewSubscriptionJob) e nova tentativa (RetryFailedPaymentJob).
 *
 * Tudo sai como string (o atributo em memória pode ser um Stringable, ex.:
 * factory) e o endereço só vai quando há algum dado — cada gateway decide se
 * ele está completo o bastante (o boleto do PagBank/Pagar.me/Mercado Pago
 * exige rua, número, bairro, cidade, UF e CEP).
 */
final class BillingCustomer
{
    public static function customer(Entity $entity): CustomerDTO
    {
        return new CustomerDTO(
            entityId: (string) $entity->id,
            name: (string) $entity->name,
            email: self::string($entity->email),
            document: self::string($entity->national_registration),
            phone: self::phone($entity),
            externalReference: (string) $entity->id,
            metadata: self::chargeMetadata($entity),
            address: self::address($entity),
        );
    }

    /**
     * Metadata do CreateChargeDTO/CreateSubscriptionDTO com os dados do
     * pagador (os gateways tiram dado pessoal do que mandam como metadata).
     *
     * @return array<string, mixed>
     */
    public static function chargeMetadata(Entity $entity, array $extra = []): array
    {
        return array_merge(array_filter([
            'customer_name' => self::string($entity->name),
            'email'         => self::string($entity->email),
            'document'      => self::string($entity->national_registration),
            'phone'         => self::phone($entity),
            'address'       => self::address($entity),
        ], fn ($value) => $value !== null), $extra);
    }

    /**
     * @return array{zipcode: ?string, street: ?string, number: ?string, complement: ?string, district: ?string, city: ?string, state: ?string}|null
     */
    public static function address(Entity $entity): ?array
    {
        $zipcode = preg_replace('/\D/', '', (string) self::string($entity->zipcode)) ?: null;
        $state   = self::string($entity->state);

        $address = [
            'zipcode'    => $zipcode,
            'street'     => self::string($entity->address),
            'number'     => self::string($entity->number),
            'complement' => self::string($entity->complement),
            'district'   => self::string($entity->district),
            'city'       => self::string($entity->city),
            'state'      => $state !== null ? mb_strtoupper($state, 'UTF-8') : null,
        ];

        return array_filter($address, fn ($value) => $value !== null) === [] ? null : $address;
    }

    private static function phone(Entity $entity): ?string
    {
        return self::string($entity->cellphone) ?? self::string($entity->telephone);
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
