<?php

use App\DTOs\Fiscal\NfsePayloadContext;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Contexto de payload da NFS-e (tests/Unit/Fiscal)
|--------------------------------------------------------------------------
| O mesmo contexto dos arquivos "golden" em tests/Fixtures/Fiscal. Incluído por `require_once`.
*/

const NFSE_GOLDEN_DESCRIPTION = 'Assinatura do plano Plano Pro (Mensal) — período 01/10/2026 a 31/10/2026 — fatura INV-001';

/** Prestador completo (a dona do SaaS) — o que NfseSettings::prestador() devolve configurado. */
function nfsePrestador(array $overrides = []): array
{
    return array_replace([
        'cnpj'                           => '11222333000181',
        'inscricao_municipal'            => '12345678',
        'codigo_municipio'               => '3550308',
        'cnae'                           => '6201501',
        'item_lista_servico'             => '01.07',
        'codigo_tributario_municipio'    => '62015',
        'codigo_tributacao_nacional_iss' => '010701',
        'tributacao_iss'                 => 1,
        'aliquota_iss'                   => 0.05,
        'iss_retido'                     => false,
        'natureza_operacao'              => '1',
        'optante_simples_nacional'       => true,
        'regime_especial_tributacao'     => null,
        'serie_dps'                      => 1,
        'proximo_numero_dps'             => 8,
    ], $overrides);
}

/** Tomador congelado na nota (snapshot): clínica com endereço completo. */
function nfsePayer(array $overrides = []): array
{
    return array_replace_recursive([
        'name'          => 'CLINICA OFTALMO TESTE',
        'document'      => '11444777000161',
        'document_type' => 'cnpj',
        'email'         => 'financeiro@clinica.test',
        'phone'         => '11999998888',
        'address'       => [
            'logradouro'       => 'AVENIDA PAULISTA',
            'numero'           => '1000',
            'complemento'      => 'SALA 12',
            'bairro'           => 'BELA VISTA',
            'uf'               => 'SP',
            'cep'              => '01310100',
            'codigo_municipio' => '3550308',
        ],
    ], $overrides);
}

function nfseContext(array $overrides = []): NfsePayloadContext
{
    $values = array_replace([
        'issuedAt'    => CarbonImmutable::parse('2026-10-18 14:30:00', 'America/Sao_Paulo'),
        'competence'  => CarbonImmutable::parse('2026-10-01', 'America/Sao_Paulo'),
        'amount'      => 199.9,
        'description' => NFSE_GOLDEN_DESCRIPTION,
        'prestador'   => nfsePrestador(),
        'payer'       => nfsePayer(),
        'dpsSeries'   => 1,
        'dpsNumber'   => 7,
    ], $overrides);

    return new NfsePayloadContext(...$values);
}

/** @return array<string, mixed> */
function nfseFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/../../../Fixtures/Fiscal/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}
