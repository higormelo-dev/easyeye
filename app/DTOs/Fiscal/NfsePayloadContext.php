<?php

declare(strict_types=1);

namespace App\DTOs\Fiscal;

use Carbon\CarbonImmutable;

/**
 * Tudo que um builder precisa para montar o corpo da emissão — e nada mais. O builder é uma função pura deste contexto
 * (sem banco, sem relógio, sem configuração): a mesma entrada dá sempre o mesmo JSON, e é isso que os testes de fixture
 * conferem. Quem monta o contexto (SendNfseJob) congela o tomador na nota e lê o prestador da configuração.
 *
 * `prestador` = NfseSettings::prestador() (já tipado). `payer` = snapshot do tomador gravado na nota:
 *   name, document (canônico, com letras no CNPJ novo), document_type ('cnpj'|'cpf'), email?, phone? (10–11 dígitos),
 *   address? {logradouro, numero, complemento, bairro, uf, cep, codigo_municipio} — cada campo só se existir.
 */
final readonly class NfsePayloadContext
{
    /**
     * @param array<string, mixed> $prestador
     * @param array<string, mixed> $payer
     */
    public function __construct(
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $competence,
        public float $amount,
        public string $description,
        public array $prestador,
        public array $payer,
        public ?int $dpsSeries = null,
        public ?int $dpsNumber = null,
        /** Teto da discriminação do municipal (varia por prefeitura): `fiscal.limits.discriminacao`. O nacional corta nos 1000 da DPS. */
        public int $descriptionLimit = 2000,
    ) {
    }
}
