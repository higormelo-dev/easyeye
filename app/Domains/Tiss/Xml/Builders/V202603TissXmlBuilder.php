<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Xml\Builders;

use App\Domains\Tiss\DTOs\{TissBatchData, TissGuideData, TissGuideItemData};
use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Xml\Contracts\TissXmlBuilder;
use DOMDocument;
use DOMElement;

class V202603TissXmlBuilder implements TissXmlBuilder
{
    public function buildBatch(TissBatch $batch): string
    {
        $data = TissBatchData::fromModel($batch);

        $dom               = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $ansTiss = $dom->createElement('ansTISS');
        $dom->appendChild($ansTiss);

        $this->appendHeader($dom, $ansTiss, $data);
        $this->appendBatch($dom, $ansTiss, $data);

        return (string) $dom->saveXML();
    }

    private function appendHeader(DOMDocument $dom, DOMElement $root, TissBatchData $data): void
    {
        $header = $root->appendChild($dom->createElement('cabecalho'));
        $tx     = $header->appendChild($dom->createElement('identificacaoTransacao'));

        $tx->appendChild($dom->createElement('tipoTransacao', 'ENVIO_LOTE_GUIAS'));
        $tx->appendChild($dom->createElement('sequencialTransacao', $data->batchNumber));
        $tx->appendChild($dom->createElement('dataRegistroTransacao', now()->format('Y-m-d')));
        $tx->appendChild($dom->createElement('horaRegistroTransacao', now()->format('H:i:s')));

        $origin   = $header->appendChild($dom->createElement('origem'));
        $provider = $origin->appendChild($dom->createElement('identificacaoPrestador'));
        $provider->appendChild($dom->createElement('codigoPrestadorNaOperadora', $data->providerCode));

        $dest = $header->appendChild($dom->createElement('destino'));
        $dest->appendChild($dom->createElement('registroANS', $this->digitsOnly($data->operatorAnsCode)));

        $header->appendChild($dom->createElement('Padrao', $data->layoutVersion));
        $header->appendChild($dom->createElement('versaoPadrao', $data->versionCode));
    }

    private function appendBatch(DOMDocument $dom, DOMElement $root, TissBatchData $data): void
    {
        $providerToOperator = $root->appendChild($dom->createElement('prestadorParaOperadora'));
        $loteGuias          = $providerToOperator->appendChild($dom->createElement('loteGuias'));
        $loteGuias->appendChild($dom->createElement('numeroLote', $data->batchNumber));

        $guidesNode = $loteGuias->appendChild($dom->createElement('guiasTISS'));

        foreach ($data->guides as $guide) {
            if ($guide->guideType === TissGuideType::Consultation) {
                $this->appendConsultaGuide($dom, $guidesNode, $guide, $data);

                continue;
            }

            $this->appendSadtGuide($dom, $guidesNode, $guide, $data);
        }

        // hashLote: MD5 do conteúdo serializado do elemento guiasTISS (TISS 4.x obrigatório)
        $guidesXml = $dom->saveXML($guidesNode);
        $hashLote  = md5($guidesXml !== false ? $guidesXml : '');
        $loteGuias->appendChild($dom->createElement('hashLote', $hashLote));
    }

    private function appendConsultaGuide(
        DOMDocument $dom,
        DOMElement $parent,
        TissGuideData $guide,
        TissBatchData $batch,
    ): void {
        $node   = $parent->appendChild($dom->createElement('guiaConsulta'));
        $header = $node->appendChild($dom->createElement('cabecalhoGuia'));

        $header->appendChild($dom->createElement('registroANS', $this->digitsOnly($batch->operatorAnsCode)));
        $header->appendChild($dom->createElement('numeroGuiaPrestador', $guide->guideNumber));

        $beneficiary = $node->appendChild($dom->createElement('dadosBeneficiario'));
        $beneficiary->appendChild($dom->createElement('numeroCarteira', $guide->beneficiaryCard ?: 'SEM_CARTAO'));
        $beneficiary->appendChild($dom->createElement('nomeBeneficiario', $guide->beneficiaryName ?: 'PACIENTE NAO INFORMADO'));

        $service = $node->appendChild($dom->createElement('dadosAtendimento'));
        $service->appendChild($dom->createElement('tipoAtendimento', $guide->attendanceType));
        $service->appendChild($dom->createElement('indicacaoAcidente', $guide->accidentIndicator));
        $service->appendChild($dom->createElement('dataAtendimento', $guide->attendanceDate));

        if ($guide->clinicalIndication) {
            $service->appendChild($dom->createElement('indicacaoClinica', mb_substr($guide->clinicalIndication, 0, 500)));
        }

        $exec = $node->appendChild($dom->createElement('dadosExecutante'));
        $exec->appendChild($dom->createElement('codigoPrestadorNaOperadora', $batch->providerCode));

        $values = $node->appendChild($dom->createElement('valorInformado'));
        $values->appendChild($dom->createElement('valorTotal', $this->currency($guide->totalAmount)));
    }

    private function appendSadtGuide(
        DOMDocument $dom,
        DOMElement $parent,
        TissGuideData $guide,
        TissBatchData $batch,
    ): void {
        $node   = $parent->appendChild($dom->createElement('guiaSP-SADT'));
        $header = $node->appendChild($dom->createElement('cabecalhoGuia'));

        $header->appendChild($dom->createElement('registroANS', $this->digitsOnly($batch->operatorAnsCode)));
        $header->appendChild($dom->createElement('numeroGuiaPrestador', $guide->guideNumber));

        $beneficiary = $node->appendChild($dom->createElement('dadosBeneficiario'));
        $beneficiary->appendChild($dom->createElement('numeroCarteira', $guide->beneficiaryCard ?: 'SEM_CARTAO'));
        $beneficiary->appendChild($dom->createElement('nomeBeneficiario', $guide->beneficiaryName ?: 'PACIENTE NAO INFORMADO'));

        // dadosSolicitante — obrigatório na SP/SADT
        $solicitante = $node->appendChild($dom->createElement('dadosSolicitante'));
        $solicitante->appendChild($dom->createElement('nomeSolicitante', $guide->doctorName ?: 'MEDICO NAO INFORMADO'));

        if ($guide->doctorCbo) {
            $solicitante->appendChild($dom->createElement('codigoCBO', $guide->doctorCbo));
        }

        // dadosSolicitacao — obrigatório na SP/SADT
        $solicitacao = $node->appendChild($dom->createElement('dadosSolicitacao'));
        $solicitacao->appendChild($dom->createElement('dataSolicitacao', $guide->attendanceDate));

        if ($guide->clinicalIndication) {
            $solicitacao->appendChild($dom->createElement('indicacaoClinica', mb_substr($guide->clinicalIndication, 0, 500)));
        }

        $attendance = $node->appendChild($dom->createElement('dadosAtendimento'));
        $attendance->appendChild($dom->createElement('tipoAtendimento', $guide->attendanceType));
        $attendance->appendChild($dom->createElement('indicacaoAcidente', $guide->accidentIndicator));
        $attendance->appendChild($dom->createElement('dataAtendimento', $guide->attendanceDate));

        $exec = $node->appendChild($dom->createElement('dadosExecutante'));
        $exec->appendChild($dom->createElement('codigoPrestadorNaOperadora', $batch->providerCode));

        foreach ($guide->items as $item) {
            $this->appendSadtProcedure($dom, $node, $item);
        }
    }

    private function appendSadtProcedure(DOMDocument $dom, DOMElement $guideNode, TissGuideItemData $item): void
    {
        $proc = $guideNode->appendChild($dom->createElement('procedimentoExecutado'));
        $proc->appendChild($dom->createElement('codigoTabela', $item->tableCode));
        $proc->appendChild($dom->createElement('codigoProcedimento', $item->tussCode));
        $proc->appendChild($dom->createElement('descricaoProcedimento', $item->description));
        $proc->appendChild($dom->createElement('quantidadeExecutada', $this->quantity($item->quantity)));
        $proc->appendChild($dom->createElement('valorUnitario', $this->currency($item->unitAmount)));
        $proc->appendChild($dom->createElement('valorTotal', $this->currency($item->totalAmount)));

        if ($item->executionDate) {
            $proc->appendChild($dom->createElement('dataExecucao', $item->executionDate));
        }

        if ($item->authorizationNumber) {
            $proc->appendChild($dom->createElement('autorizacao', $item->authorizationNumber));
        }
    }

    private function currency(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function quantity(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }
}
