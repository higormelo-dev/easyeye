<?php

declare(strict_types=1);

namespace App\DTOs\Fiscal;

/**
 * O que a Focus sabe sobre a NFS-e de um município (GET /v2/municipios/{ibge}): se está habilitada, se dá para cancelar
 * por webservice e o que a prefeitura exige na emissão. `null` num booleano = a Focus não tem a informação (indefinido) —
 * quem decide trata `null` como "não sei", nunca como "não exige".
 *
 * `stale` = a Focus não respondeu e este é o último valor guardado (`fetchedAt` diz de quando).
 */
final readonly class NfseMunicipality
{
    public function __construct(
        public string $ibge,
        public ?string $name,
        public ?string $uf,
        public bool $enabled,
        public ?bool $requiresCertificate,
        public ?bool $hasHomologation,
        public ?bool $allowsCancellation,
        public ?string $provider,
        public ?bool $addressRequired,
        public ?bool $documentRequired,
        public ?bool $cnaeRequired,
        public ?bool $serviceItemRequired,
        public ?bool $municipalTaxCodeRequired,
        public ?string $status,
        public ?string $fetchedAt = null,
        public bool $stale = false,
    ) {
    }

    /** @param array<string, mixed> $data corpo de GET /v2/municipios/{ibge}; campos novos/ausentes são tolerados */
    public static function fromFocus(string $ibge, array $data, ?string $fetchedAt = null, bool $stale = false): self
    {
        $bool = static fn (string $key): ?bool => array_key_exists($key, $data) && $data[$key] !== null ? (bool) $data[$key] : null;
        $text = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;

        return new self(
            ibge: $ibge,
            name: $text('nome_municipio'),
            uf: $text('sigla_uf'),
            enabled: (bool) ($data['nfse_habilitada'] ?? false),
            requiresCertificate: $bool('requer_certificado_nfse'),
            hasHomologation: $bool('possui_ambiente_homologacao_nfse'),
            allowsCancellation: $bool('possui_cancelamento_nfse'),
            provider: $text('provedor_nfse'),
            addressRequired: $bool('endereco_obrigatorio_nfse'),
            documentRequired: $bool('cpf_cnpj_obrigatorio_nfse'),
            cnaeRequired: $bool('codigo_cnae_obrigatorio_nfse'),
            serviceItemRequired: $bool('item_lista_servico_obrigatorio_nfse'),
            municipalTaxCodeRequired: $bool('codigo_tributario_municipio_obrigatorio_nfse'),
            status: $text('status_nfse'),
            fetchedAt: $fetchedAt,
            stale: $stale,
        );
    }

    public function withStale(bool $stale): self
    {
        return new self(
            $this->ibge,
            $this->name,
            $this->uf,
            $this->enabled,
            $this->requiresCertificate,
            $this->hasHomologation,
            $this->allowsCancellation,
            $this->provider,
            $this->addressRequired,
            $this->documentRequired,
            $this->cnaeRequired,
            $this->serviceItemRequired,
            $this->municipalTaxCodeRequired,
            $this->status,
            $this->fetchedAt,
            $stale,
        );
    }

    /**
     * Formato da tela (props `municipality`): as flags com os nomes da Focus + o que identifica o município e a cache.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'codigo_municipio'                             => $this->ibge,
            'nome_municipio'                               => $this->name,
            'sigla_uf'                                     => $this->uf,
            'nfse_habilitada'                              => $this->enabled,
            'requer_certificado_nfse'                      => $this->requiresCertificate,
            'possui_ambiente_homologacao_nfse'             => $this->hasHomologation,
            'possui_cancelamento_nfse'                     => $this->allowsCancellation,
            'provedor_nfse'                                => $this->provider,
            'endereco_obrigatorio_nfse'                    => $this->addressRequired,
            'cpf_cnpj_obrigatorio_nfse'                    => $this->documentRequired,
            'codigo_cnae_obrigatorio_nfse'                 => $this->cnaeRequired,
            'item_lista_servico_obrigatorio_nfse'          => $this->serviceItemRequired,
            'codigo_tributario_municipio_obrigatorio_nfse' => $this->municipalTaxCodeRequired,
            'status_nfse'                                  => $this->status,
            'fetched_at'                                   => $this->fetchedAt,
            'stale'                                        => $this->stale,
        ];
    }
}
