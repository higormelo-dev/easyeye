<?php

declare(strict_types=1);

namespace App\Services\Fiscal\Payloads;

use App\Contracts\Fiscal\NfsePayloadBuilder;
use App\DTOs\Fiscal\NfsePayloadContext;
use App\Support\BrazilianFormat;

/**
 * Corpo do POST /v2/nfse (NFS-e municipal) — campos e limites do schema NFSeRequest da Focus
 * (https://doc.focusnfe.com.br/reference/emitir_nfse).
 *
 *  - `data_emissao`: ISO 8601 com o fuso do contexto (a Focus descarta a hora em municípios que não a usam);
 *  - `servico.aliquota` em FRAÇÃO (0.05 = 5%); `base_calculo` = valor dos serviços;
 *  - `servico.codigo_municipio` = município do PRESTADOR: o ISS é devido no estabelecimento prestador (LC 116/2003,
 *    art. 3º), e a clínica só entra no endereço do tomador;
 *  - tomador: CPF ou CNPJ (nunca os dois), razão social ≤ 115, e-mail ≤ 80, telefone 10–11 dígitos e endereço só com
 *    os campos que existem (a prefeitura pode ou não exigi-lo);
 *  - o que não existe é omitido — nunca string vazia nem null.
 *
 * Propriedades que a Focus passe a aceitar não entram aqui sem decisão: este builder é a lista fechada do que o EasyEye
 * envia.
 */
final class MunicipalNfsePayloadBuilder implements NfsePayloadBuilder
{
    public function build(NfsePayloadContext $context): array
    {
        $prestador = $context->prestador;

        $payload = [
            'data_emissao'             => $context->issuedAt->toIso8601String(),
            'natureza_operacao'        => $this->naturezaOperacao($prestador['natureza_operacao'] ?? null),
            'optante_simples_nacional' => (bool) ($prestador['optante_simples_nacional'] ?? false),
        ];

        $regime = (string) ($prestador['regime_especial_tributacao'] ?? '');

        if (preg_match('/^[1-6]$/', $regime) === 1) {
            $payload['regime_especial_tributacao'] = $regime;
        }

        $payload['prestador'] = $this->prestador($prestador);
        $payload['tomador']   = $this->tomador($context->payer);
        $payload['servico']   = $this->servico($context);

        return $payload;
    }

    /** @return array<string, string> */
    private function prestador(array $prestador): array
    {
        return array_filter([
            'cnpj'                => (string) BrazilianFormat::documentChars((string) ($prestador['cnpj'] ?? '')),
            'inscricao_municipal' => trim((string) ($prestador['inscricao_municipal'] ?? '')),
            'codigo_municipio'    => preg_replace('/\D/', '', (string) ($prestador['codigo_municipio'] ?? '')),
        ], static fn (string $value) => $value !== '');
    }

    /** @return array<string, mixed> */
    private function tomador(array $payer): array
    {
        $type = ($payer['document_type'] ?? 'cnpj') === 'cpf' ? 'cpf' : 'cnpj';

        $tomador = [
            $type          => (string) BrazilianFormat::documentChars((string) ($payer['document'] ?? '')),
            'razao_social' => mb_substr(trim((string) ($payer['name'] ?? '')), 0, 115),
        ];

        $email = trim((string) ($payer['email'] ?? ''));

        if ($email !== '') {
            $tomador['email'] = mb_substr($email, 0, 80);
        }

        $phone = BrazilianFormat::digits((string) ($payer['phone'] ?? ''));

        if (preg_match('/^\d{10,11}$/', $phone) === 1) {
            $tomador['telefone'] = $phone;
        }

        $address = $this->endereco((array) ($payer['address'] ?? []));

        if ($address !== []) {
            $tomador['endereco'] = $address;
        }

        return $tomador;
    }

    /** @return array<string, string> */
    private function endereco(array $address): array
    {
        $cep  = BrazilianFormat::digits((string) ($address['cep'] ?? ''));
        $ibge = BrazilianFormat::digits((string) ($address['codigo_municipio'] ?? ''));
        $uf   = strtoupper(trim((string) ($address['uf'] ?? '')));

        return array_filter([
            'logradouro'       => mb_substr(trim((string) ($address['logradouro'] ?? '')), 0, 125),
            'numero'           => mb_substr(trim((string) ($address['numero'] ?? '')), 0, 10),
            'complemento'      => mb_substr(trim((string) ($address['complemento'] ?? '')), 0, 60),
            'bairro'           => mb_substr(trim((string) ($address['bairro'] ?? '')), 0, 60),
            'codigo_municipio' => strlen($ibge) === 7 ? $ibge : '',
            'uf'               => strlen($uf) === 2 ? $uf : '',
            'cep'              => strlen($cep) === 8 ? $cep : '',
        ], static fn (string $value) => $value !== '');
    }

    /** @return array<string, mixed> */
    private function servico(NfsePayloadContext $context): array
    {
        $prestador = $context->prestador;
        $amount    = round($context->amount, 2);
        $aliquota  = round((float) ($prestador['aliquota_iss'] ?? 0), 4);

        $servico = [
            'valor_servicos'     => $amount,
            'iss_retido'         => (bool) ($prestador['iss_retido'] ?? false),
            'item_lista_servico' => trim((string) ($prestador['item_lista_servico'] ?? '')),
            'discriminacao'      => mb_substr(trim($context->description), 0, max(1, $context->descriptionLimit)),
            'codigo_municipio'   => preg_replace('/\D/', '', (string) ($prestador['codigo_municipio'] ?? '')),
        ];

        $taxCode = trim((string) ($prestador['codigo_tributario_municipio'] ?? ''));

        if ($taxCode !== '') {
            $servico['codigo_tributario_municipio'] = $taxCode;
        }

        $cnae = BrazilianFormat::digits((string) ($prestador['cnae'] ?? ''));

        if (strlen($cnae) === 7) {
            $servico['codigo_cnae'] = $cnae;
        }

        if ($aliquota > 0) {
            $servico['aliquota']     = $aliquota;
            $servico['base_calculo'] = $amount;
        }

        return $servico;
    }

    /** "1" a "6" (tributação no município … exigibilidade suspensa); padrão da Focus: "1". */
    private function naturezaOperacao(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[1-6]$/', $value) === 1 ? $value : '1';
    }
}
