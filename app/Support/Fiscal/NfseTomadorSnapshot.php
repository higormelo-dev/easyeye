<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Domains\Tiss\Support\IbgeUf;
use App\Models\{Entity, User};
use App\Rules\CpfCnpj;
use App\Support\BrazilianFormat;

/**
 * Congela os dados fiscais da clínica (o TOMADOR) no momento da emissão: a nota guarda este retrato em
 * `payer_snapshot` e o payload enviado sai dele, nunca da clínica "viva" — o cadastro pode mudar depois sem alterar o
 * que foi emitido (LGPD e auditoria fiscal). Não depende de sessão: roda em fila, comando e Manager.
 *
 *   name           razão social (≤ 115, o limite do padrão municipal)
 *   document       CPF/CNPJ canônico (letras mantidas: CNPJ alfanumérico, IN RFB 2.229/2024)
 *   document_type  'cnpj' | 'cpf'
 *   email          e-mail da clínica; sem ele, o do primeiro contato de cobrança com e-mail verificado (mesma regra da régua)
 *   phone          10–11 dígitos (DDD + número, sem DDI); omitido se não houver telefone brasileiro válido
 *   address        SÓ quando COMPLETO (logradouro, número, bairro, UF, CEP e IBGE); meio endereço não vai — a Focus recusa
 *                  endereço pela metade, e município que não exige endereço aceita a nota sem ele
 */
final class NfseTomadorSnapshot
{
    /** @return array<string, mixed> */
    public static function from(Entity $entity): array
    {
        $document = (string) BrazilianFormat::documentChars((string) $entity->national_registration);

        $snapshot = [
            'name'          => mb_substr(trim((string) $entity->name), 0, 115),
            'document'      => $document,
            'document_type' => strlen($document) === 11 ? 'cpf' : 'cnpj',
        ];

        if (($email = self::email($entity)) !== null) {
            $snapshot['email'] = $email;
        }

        if (($phone = self::phone($entity)) !== null) {
            $snapshot['phone'] = $phone;
        }

        if (($address = self::address($entity)) !== null) {
            $snapshot['address'] = $address;
        }

        return $snapshot;
    }

    /** Documento válido (CPF ou CNPJ com dígitos verificadores)? O snapshot em si não valida: a guarda de emissão já barrou. */
    public static function hasValidDocument(Entity $entity): bool
    {
        return CpfCnpj::isValid($entity->national_registration);
    }

    /**
     * O endereço da clínica está completo para a NFS-e? (mesma regra do snapshot). Quando não está, devolve os campos que
     * faltam, para a mensagem e o card "Clínicas sem IBGE".
     *
     * @return list<string> vazio = completo
     */
    public static function missingAddressFields(Entity $entity): array
    {
        $missing = [];

        foreach (['logradouro' => 'address', 'bairro' => 'district'] as $field => $column) {
            if (trim((string) $entity->{$column}) === '') {
                $missing[] = $field;
            }
        }

        if (strlen(BrazilianFormat::digits((string) $entity->zipcode)) !== 8) {
            $missing[] = 'cep';
        }

        if (IbgeUf::code($entity->state) === null) {
            $missing[] = 'uf';
        }

        if (preg_match('/^\d{7}$/', (string) $entity->ibge_code) !== 1) {
            $missing[] = 'codigo_municipio';
        }

        return $missing;
    }

    /** @return array<string, string>|null */
    private static function address(Entity $entity): ?array
    {
        if (self::missingAddressFields($entity) !== []) {
            return null;
        }

        $number     = trim((string) $entity->number);
        $complement = trim((string) $entity->complement);

        return array_filter([
            'logradouro' => mb_substr(trim((string) $entity->address), 0, 125),
            // Cadastro sem número é "S/N" no endereço brasileiro; nunca inventamos um número.
            'numero'           => mb_substr($number !== '' ? $number : 'S/N', 0, 10),
            'complemento'      => mb_substr($complement, 0, 60),
            'bairro'           => mb_substr(trim((string) $entity->district), 0, 60),
            'uf'               => strtoupper(trim((string) $entity->state)),
            'cep'              => BrazilianFormat::digits((string) $entity->zipcode),
            'codigo_municipio' => (string) $entity->ibge_code,
        ], static fn (string $value) => $value !== '');
    }

    private static function email(Entity $entity): ?string
    {
        $email = trim((string) $entity->email);

        if ($email === '') {
            $email = (string) User::query()
                ->whereNotNull('users.email_verified_at')
                ->whereHas('entityUsers', fn ($q) => $q->where('entity_id', $entity->id)->billingContacts())
                ->orderBy('users.id')
                ->value('users.email');
        }

        return $email !== '' ? mb_substr($email, 0, 80) : null;
    }

    private static function phone(Entity $entity): ?string
    {
        foreach ([$entity->cellphone, $entity->telephone] as $candidate) {
            $phone = BrazilianFormat::canonicalPhone((string) $candidate);

            if ($phone !== null && preg_match('/^\d{10,11}$/', $phone) === 1) {
                return $phone;
            }
        }

        return null;
    }
}
