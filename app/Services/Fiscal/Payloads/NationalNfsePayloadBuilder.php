<?php

declare(strict_types=1);

namespace App\Services\Fiscal\Payloads;

use App\Contracts\Fiscal\NfsePayloadBuilder;
use App\DTOs\Fiscal\NfsePayloadContext;
use App\Support\BrazilianFormat;
use InvalidArgumentException;

/**
 * Corpo do POST /v2/nfsen (NFS-e nacional, DPS) — lista oficial de campos em
 * https://campos.focusnfe.com.br/nfse_nacional/EmissaoDPSXml.html. Os campos do tomador são PLANOS com sufixo `_tomador`.
 *
 * Obrigatórios da DPS que sempre vão: `data_emissao`, `serie_dps`, `numero_dps`, `data_competencia`, `emitente_dps`,
 * `codigo_municipio_emissora`, `cnpj_prestador`, `codigo_opcao_simples_nacional`, `regime_especial_tributacao`,
 * `cnpj_tomador`|`cpf_tomador`, `codigo_municipio_prestacao`, `codigo_tributacao_nacional_iss`, `descricao_servico`,
 * `valor_servico` e `tributacao_iss`. Os tipos seguem o exemplo oficial da Focus (inteiros nos códigos numéricos, texto
 * em CEP/telefone/documento, que podem começar em zero).
 *
 * Pontos de atenção:
 *  - `data_emissao` em UTC (AAAA-MM-DDThh:mm:ss+00:00), como a DPS pede; `data_competencia` é só a data;
 *  - o ISS aqui é em PERCENTUAL (`percentual_aliquota_relativa_municipio`: 5.00 = 5%), não fração como no municipal, e a
 *    retenção é `tipo_retencao_iss` (1 não retido, 2 retido pelo tomador);
 *  - `codigo_opcao_simples_nacional`: 1 não optante, 2 optante MEI, 3 optante ME/EPP. Optante + regime especial "5" (MEI
 *    no municipal) vira 2 — e, como o 5 do nacional é "profissional autônomo", o regime vai 0 nesse caso;
 *  - `regime_especial_tributacao`: a tabela do nacional é DIFERENTE da municipal (0 nenhum … 9 outros); sem valor
 *    configurado vai 0;
 *  - o prestador e o local da prestação são o município do prestador (o ISS é devido no estabelecimento prestador);
 *  - campos da Reforma Tributária (IBS/CBS) ficam de fora, como no exemplo oficial.
 */
final class NationalNfsePayloadBuilder implements NfsePayloadBuilder
{
    /** @throws InvalidArgumentException série/número da DPS ausentes (o contador reserva os dois na criação da nota) */
    public function build(NfsePayloadContext $context): array
    {
        if ($context->dpsSeries === null || $context->dpsNumber === null) {
            throw new InvalidArgumentException('A DPS exige série e número.');
        }

        $prestador = $context->prestador;
        $ibge      = (int) preg_replace('/\D/', '', (string) ($prestador['codigo_municipio'] ?? ''));
        $aliquota  = round((float) ($prestador['aliquota_iss'] ?? 0) * 100, 2);
        $simples   = $this->opcaoSimplesNacional($prestador);

        $payload = [
            'data_emissao'                  => $context->issuedAt->utc()->format('Y-m-d\TH:i:sP'),
            'serie_dps'                     => $context->dpsSeries,
            'numero_dps'                    => $context->dpsNumber,
            'data_competencia'              => $context->competence->format('Y-m-d'),
            'emitente_dps'                  => 1,
            'codigo_municipio_emissora'     => $ibge,
            'cnpj_prestador'                => (string) BrazilianFormat::documentChars((string) ($prestador['cnpj'] ?? '')),
            'codigo_opcao_simples_nacional' => $simples,
            'regime_especial_tributacao'    => $simples === 2 ? 0 : $this->regimeEspecial($prestador['regime_especial_tributacao'] ?? null),
        ];

        $payload += $this->tomador($context->payer);

        $payload += [
            'codigo_municipio_prestacao'     => $ibge,
            'codigo_tributacao_nacional_iss' => preg_replace('/\D/', '', (string) ($prestador['codigo_tributacao_nacional_iss'] ?? '')),
        ];

        // cTribMun tem 3 dígitos; o código tributário configurado serve ao padrão municipal e só vai se couber.
        $municipalCode = trim((string) ($prestador['codigo_tributario_municipio'] ?? ''));

        if (preg_match('/^\d{3}$/', $municipalCode) === 1) {
            $payload['codigo_tributacao_municipal_iss'] = $municipalCode;
        }

        $payload += [
            'descricao_servico' => mb_substr(trim($context->description), 0, 1000),
            'valor_servico'     => round($context->amount, 2),
            'tributacao_iss'    => $this->tributacaoIss($prestador['tributacao_iss'] ?? null),
            'tipo_retencao_iss' => ($prestador['iss_retido'] ?? false) ? 2 : 1,
        ];

        if ($aliquota > 0) {
            $payload['percentual_aliquota_relativa_municipio'] = $aliquota;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function tomador(array $payer): array
    {
        $type = ($payer['document_type'] ?? 'cnpj') === 'cpf' ? 'cpf' : 'cnpj';

        $tomador = [
            "{$type}_tomador"      => (string) BrazilianFormat::documentChars((string) ($payer['document'] ?? '')),
            'razao_social_tomador' => mb_substr(trim((string) ($payer['name'] ?? '')), 0, 150),
        ];

        $address = (array) ($payer['address'] ?? []);
        $ibge    = BrazilianFormat::digits((string) ($address['codigo_municipio'] ?? ''));
        $cep     = BrazilianFormat::digits((string) ($address['cep'] ?? ''));

        $optional = [
            'codigo_municipio_tomador' => strlen($ibge) === 7 ? $ibge : '',
            'cep_tomador'              => strlen($cep) === 8 ? $cep : '',
            'logradouro_tomador'       => mb_substr(trim((string) ($address['logradouro'] ?? '')), 0, 255),
            'numero_tomador'           => mb_substr(trim((string) ($address['numero'] ?? '')), 0, 60),
            'complemento_tomador'      => mb_substr(trim((string) ($address['complemento'] ?? '')), 0, 156),
            'bairro_tomador'           => mb_substr(trim((string) ($address['bairro'] ?? '')), 0, 60),
        ];

        $phone = BrazilianFormat::digits((string) ($payer['phone'] ?? ''));

        if (preg_match('/^\d{6,20}$/', $phone) === 1) {
            $optional['telefone_tomador'] = $phone;
        }

        $email = trim((string) ($payer['email'] ?? ''));

        if ($email !== '') {
            $optional['email_tomador'] = mb_substr($email, 0, 80);
        }

        return $tomador + array_filter($optional, static fn (string $value) => $value !== '');
    }

    /**
     * Situação perante o Simples Nacional (DPS `opSimpNac`): 1 não optante; 2 optante MEI; 3 optante ME/EPP. A configuração
     * é um booleano (`optante_simples_nacional`) e o MEI se declara pelo regime especial 5 ("MEI - Simples Nacional" na
     * tabela do municipal) — optante com regime 5 é MEI (2); optante sem ele é ME/EPP (3).
     *
     * @param array<string, mixed> $prestador
     */
    private function opcaoSimplesNacional(array $prestador): int
    {
        if (! ($prestador['optante_simples_nacional'] ?? false)) {
            return 1;
        }

        return (string) ($prestador['regime_especial_tributacao'] ?? '') === '5' ? 2 : 3;
    }

    /** 0 a 6 e 9 (tabela do nacional); sem valor válido, 0 = nenhum. */
    private function regimeEspecial(mixed $value): int
    {
        $value = (string) $value;

        return preg_match('/^[0-69]$/', $value) === 1 ? (int) $value : 0;
    }

    /** 1 tributável, 2 imunidade, 3 exportação, 4 não incidência; padrão 1. */
    private function tributacaoIss(mixed $value): int
    {
        $value = (int) $value;

        return $value >= 1 && $value <= 4 ? $value : 1;
    }
}
