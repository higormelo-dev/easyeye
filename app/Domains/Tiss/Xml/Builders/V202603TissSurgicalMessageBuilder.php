<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Xml\Builders;

use App\Domains\Tiss\DTOs\{SurgicalGuideData, SurgicalGuideItemData};
use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Xml\Contracts\TissSurgicalMessageBuilder;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * TISS 4.03.00 — mensagens avulsas do módulo cirúrgico.
 *
 *  - Solicitação de internação: `prestadorParaOperadora/solicitacaoProcedimento/
 *    solicitacaoInternacao` (ctm_internacaoSolicitacaoGuia), `tipoTransacao`
 *    SOLICITACAO_PROCEDIMENTOS. NÃO entra em `loteGuias` (ctm_guiaLote só aceita
 *    SP-SADT, resumo de internação, honorários, consulta e odonto).
 *  - Anexo de OPME: `prestadorParaOperadora/loteAnexos/AnexosGuiasTISS/
 *    anexoSolicitacaoOPME` (ctm_anexoSolicitacaoOPME), `tipoTransacao` ENVIO_ANEXO.
 *
 * Todo elemento OBRIGATÓRIO do XSD é emitido mesmo sem dado (vazio): o validador
 * contra o XSD aponta exatamente o que falta, em vez de um elemento sumir em
 * silêncio. Nada aqui usa o relógio fora do cabeçalho (data/hora da transação e o
 * hash do epílogo — os únicos nós que TissXmlContentFingerprint ignora), então a
 * mesma guia regerada dá o mesmo conteúdo.
 */
class V202603TissSurgicalMessageBuilder implements TissSurgicalMessageBuilder
{
    private const NAMESPACE_URI = 'http://www.ans.gov.br/padroes/tiss/schemas';

    public function build(TissGuide $guide): string
    {
        $type = $guide->guide_type;

        if (! $type->isSurgicalRequest()) {
            throw new InvalidArgumentException(sprintf('Tipo de guia "%s" não é uma mensagem avulsa.', $type->value));
        }

        $data = SurgicalGuideData::fromGuide($guide);

        $dom               = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NAMESPACE_URI, 'mensagemTISS');
        $dom->appendChild($root);

        $this->appendHeader($dom, $root, $data, (string) $type->transactionType());

        $providerToOperator = $this->node($dom, $root, 'prestadorParaOperadora');

        match ($type) {
            TissGuideType::InpatientRequest => $this->appendInpatientRequest($dom, $providerToOperator, $data),
            TissGuideType::OpmeAttachment   => $this->appendOpmeAttachment($dom, $providerToOperator, $data),
            default                         => throw new InvalidArgumentException('Tipo de guia sem builder avulso.'),
        };

        $bodyXml = $dom->saveXML($root);
        $epilogo = $this->node($dom, $root, 'epilogo');
        $this->text($dom, $epilogo, 'hash', md5($bodyXml !== false ? $bodyXml : ''));

        return (string) $dom->saveXML();
    }

    private function appendHeader(DOMDocument $dom, DOMElement $root, SurgicalGuideData $data, string $transactionType): void
    {
        $header = $this->node($dom, $root, 'cabecalho');
        $tx     = $this->node($dom, $header, 'identificacaoTransacao');

        $this->text($dom, $tx, 'tipoTransacao', $transactionType);
        $this->text($dom, $tx, 'sequencialTransacao', $this->shortIdentifier($data->guideNumber));
        $this->text($dom, $tx, 'dataRegistroTransacao', now()->format('Y-m-d'));
        $this->text($dom, $tx, 'horaRegistroTransacao', now()->format('H:i:s'));

        $origin = $this->node($dom, $header, 'origem');
        $ident  = $this->node($dom, $origin, 'identificacaoPrestador');
        $this->text($dom, $ident, 'codigoPrestadorNaOperadora', $data->providerCode);

        $dest = $this->node($dom, $header, 'destino');
        $this->text($dom, $dest, 'registroANS', $this->digitsOnly($data->operatorAnsCode));

        // dm_versao não aceita zero à esquerda ("4.03.00", não "04.03.00").
        $this->text($dom, $header, 'Padrao', $this->stripLeadingVersionZero($data->layoutVersion));
    }

    // ── Solicitação de internação ──────────────────────────────────────────

    private function appendInpatientRequest(DOMDocument $dom, DOMElement $parent, SurgicalGuideData $data): void
    {
        $request = $this->node($dom, $this->node($dom, $parent, 'solicitacaoProcedimento'), 'solicitacaoInternacao');

        $this->text($dom, $request, 'registroANS', $this->digitsOnly($data->operatorAnsCode));
        $this->text($dom, $request, 'numeroGuiaPrestador', $data->guideNumber);
        // dm_etapasAutorizacao: 1 = solicitação de autorização prévia.
        $this->text($dom, $request, 'tipoEtapaAutorizacao', '1');

        $this->appendBeneficiary($dom, $request, $data);

        $requester = $this->node($dom, $request, 'identificacaoSolicitante');
        $this->text($dom, $this->node($dom, $requester, 'dadosDoContratado'), 'codigoPrestadorNaOperadora', $data->providerCode);
        $this->appendProfessionalWithCouncil($dom, $this->node($dom, $requester, 'dadosProfissionalContratado'), $data);

        $hospital = $this->node($dom, $request, 'dadosHospitalSolicitado');
        $this->text($dom, $hospital, 'codigoIndicadonaOperadora', (string) $data->hospital['operator_code']);
        $this->text($dom, $hospital, 'nomeContratadoIndicado', $this->limit((string) $data->hospital['name'], 70));
        $this->text($dom, $hospital, 'dataSugeridaInternacao', (string) $data->suggestedDate);

        $stay = $this->node($dom, $request, 'dadosInternacao');
        $this->text($dom, $stay, 'caraterAtendimento', $data->urgencyCode);
        $this->text($dom, $stay, 'tipoInternacao', $data->inpatientTypeCode);
        $this->text($dom, $stay, 'regimeInternacao', $data->regimeCode);
        $this->text($dom, $stay, 'qtDiariasSolicitadas', (string) max(0, min(99, $data->daysRequested)));
        $this->text($dom, $stay, 'indicadorOPME', $data->opme ? 'S' : 'N');
        // Quimioterapia/radioterapia não fazem parte do módulo cirúrgico.
        $this->text($dom, $stay, 'indicadorQuimio', 'N');
        $this->text($dom, $stay, 'indicacaoClinica', $this->limit((string) $data->clinicalIndication, 500));

        $hypotheses = $this->node($dom, $request, 'hipotesesDiagnosticas');

        foreach (array_slice($data->diagnoses, 0, 4) as $cid) {
            $this->text($dom, $hypotheses, 'diagnosticoCID', $cid);
        }

        // dm_indicadorAcidente: 9 = não acidente.
        $this->text($dom, $hypotheses, 'indicadorAcidente', '9');

        foreach ($data->items as $item) {
            $requested = $this->node($dom, $request, 'procedimentosSolicitados');
            $this->appendProcedure($dom, $this->node($dom, $requested, 'procedimento'), $item);
            $this->text($dom, $requested, 'quantidadeSolicitada', (string) min(999, $item->quantity));
        }

        $this->text($dom, $request, 'dataSolicitacao', $data->requestDate);

        if (filled($data->observation)) {
            $this->text($dom, $request, 'observacao', $this->limit((string) $data->observation, 1000));
        }
    }

    // ── Anexo de OPME ──────────────────────────────────────────────────────

    private function appendOpmeAttachment(DOMDocument $dom, DOMElement $parent, SurgicalGuideData $data): void
    {
        $batch = $this->node($dom, $parent, 'loteAnexos');
        $this->text($dom, $batch, 'numeroLote', $this->shortIdentifier($data->guideNumber));

        $attachment = $this->node($dom, $this->node($dom, $batch, 'AnexosGuiasTISS'), 'anexoSolicitacaoOPME');

        $header = $this->node($dom, $attachment, 'cabecalhoAnexo');
        $this->text($dom, $header, 'registroANS', $this->digitsOnly($data->operatorAnsCode));
        $this->text($dom, $header, 'numeroGuiaAnexo', $this->limit($data->guideNumber, 20));
        $this->text($dom, $header, 'numeroGuiaReferenciada', $this->limit((string) $data->referencedGuideNumber, 20));

        if (filled($data->operatorGuideNumber)) {
            $this->text($dom, $header, 'numeroGuiaOperadora', $this->limit((string) $data->operatorGuideNumber, 20));
        }

        $this->text($dom, $header, 'dataSolicitacao', $data->requestDate);

        if (filled($data->password)) {
            $this->text($dom, $header, 'senha', $this->limit((string) $data->password, 20));
        }

        if (filled($data->authorizationDate)) {
            $this->text($dom, $header, 'dataAutorizacao', (string) $data->authorizationDate);
        }

        $this->appendBeneficiary($dom, $attachment, $data);

        // ctm_anexoSolicitante: telefone é OBRIGATÓRIO (st_texto11).
        $requester = $this->node($dom, $attachment, 'profissionalSolicitante');
        $this->text($dom, $requester, 'nomeProfissional', $this->limit((string) $data->professional['name'], 70));
        $this->text($dom, $requester, 'telefoneProfissional', $this->limit($this->digitsOnly((string) $data->professional['phone']), 11));

        $this->text($dom, $attachment, 'justificativaTecnica', $this->limit((string) $data->justification, 1000));

        if (filled($data->specification)) {
            $this->text($dom, $attachment, 'especificacaoMaterial', $this->limit((string) $data->specification, 500));
        }

        $requested = $this->node($dom, $attachment, 'opmeSolicitadas');

        foreach ($data->items as $item) {
            $line = $this->node($dom, $requested, 'opmeSolicitada');
            $this->appendProcedure($dom, $this->node($dom, $line, 'identificacaoOPME'), $item);
            $this->text($dom, $line, 'opcaoFabricante', (string) $item->manufacturerOption);
            $this->text($dom, $line, 'quantidadeSolicitada', (string) min(999, $item->quantity));

            if ($item->unitAmount > 0) {
                $this->text($dom, $line, 'valorSolicitado', $this->currency($item->unitAmount * $item->quantity));
            }

            if (filled($item->anvisaRegistration)) {
                $this->text($dom, $line, 'registroANVISA', $this->limit((string) $item->anvisaRegistration, 15));
            }

            if (filled($item->manufacturerRef)) {
                $this->text($dom, $line, 'codigoRefFabricante', $this->limit((string) $item->manufacturerRef, 60));
            }

            if (filled($item->operatingAuthorization)) {
                $this->text($dom, $line, 'autorizacaoFuncionamento', $this->limit((string) $item->operatingAuthorization, 30));
            }
        }

        if (filled($data->observation)) {
            $this->text($dom, $attachment, 'Observacao', $this->limit((string) $data->observation, 500));
        }
    }

    // ── Blocos comuns ──────────────────────────────────────────────────────

    /** ct_beneficiarioDados (4.03): sem nome — só carteira + atendimentoRN. */
    private function appendBeneficiary(DOMDocument $dom, DOMElement $parent, SurgicalGuideData $data): void
    {
        $beneficiary = $this->node($dom, $parent, 'dadosBeneficiario');
        $this->text($dom, $beneficiary, 'numeroCarteira', $this->limit((string) $data->beneficiaryCard, 20));
        $this->text($dom, $beneficiary, 'atendimentoRN', 'N');
    }

    /** ct_contratadoProfissionalDados: conselho, número, UF e CBOS são obrigatórios JUNTOS. */
    private function appendProfessionalWithCouncil(DOMDocument $dom, DOMElement $node, SurgicalGuideData $data): void
    {
        $professional = $data->professional;

        if (filled($professional['name'])) {
            $this->text($dom, $node, 'nomeProfissional', $this->limit((string) $professional['name'], 70));
        }

        $this->text($dom, $node, 'conselhoProfissional', $professional['council_code']);
        $this->text($dom, $node, 'numeroConselhoProfissional', $this->limit((string) $professional['council_number'], 15));
        $this->text($dom, $node, 'UF', (string) $professional['uf']);
        $this->text($dom, $node, 'CBOS', (string) $professional['cbos']);
    }

    /** ct_procedimentoDados: tabela, código (st_texto10) e descrição (st_texto150). */
    private function appendProcedure(DOMDocument $dom, DOMElement $node, SurgicalGuideItemData $item): void
    {
        $this->text($dom, $node, 'codigoTabela', $item->tableCode);
        $this->text($dom, $node, 'codigoProcedimento', $this->limit($item->code, 10));
        $this->text($dom, $node, 'descricaoProcedimento', $this->limit($item->description, 150));
    }

    // ── Utilitários ────────────────────────────────────────────────────────

    private function node(DOMDocument $dom, DOMElement $parent, string $name): DOMElement
    {
        $node = $dom->createElement($name);
        $parent->appendChild($node);

        return $node;
    }

    /** Texto em nó próprio: o valor é ESCAPADO (createElement($n, $v) quebra com "&"). */
    private function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $node = $dom->createElement($name);
        $node->appendChild($dom->createTextNode($this->clean($value)));
        $parent->appendChild($node);
    }

    /** Sem caracteres de controle (inválidos em XML 1.0) e sem espaços nas pontas. */
    private function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr($this->clean($value), 0, $max);
    }

    private function currency(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function stripLeadingVersionZero(string $value): string
    {
        return preg_replace('/^0+(?=\d)/', '', $value) ?: $value;
    }

    /** st_texto12 (numeroLote, sequencialTransacao): só alfanuméricos finais, até 12. */
    private function shortIdentifier(string $value): string
    {
        $alphanumeric = preg_replace('/[^A-Za-z0-9]/', '', $value) ?: $value;

        return mb_substr($alphanumeric, -12);
    }
}
