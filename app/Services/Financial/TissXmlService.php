<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\BillingBatch;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;

class TissXmlService
{
    public function generate(BillingBatch $batch): string
    {
        $batch->loadMissing([
            'entity',
            'covenant',
            'claims.patient.person',
            'claims.doctor',
        ]);

        $dom               = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $ansTiss = $dom->createElement('ansTISS');
        $dom->appendChild($ansTiss);

        $this->appendHeader($dom, $ansTiss, $batch);
        $this->appendLoteGuias($dom, $ansTiss, $batch);

        $relativePath = sprintf(
            'private/tiss/%s/%s.xml',
            $batch->entity_id,
            mb_strtolower($batch->code),
        );

        Storage::disk('local')->put($relativePath, $dom->saveXML());

        return $relativePath;
    }

    private function appendHeader(DOMDocument $dom, DOMElement $root, BillingBatch $batch): void
    {
        $cabecalho              = $root->appendChild($dom->createElement('cabecalho'));
        $identificacaoTransacao = $cabecalho->appendChild($dom->createElement('identificacaoTransacao'));
        $identificacaoTransacao->appendChild($dom->createElement('tipoTransacao', 'ENVIO_LOTE_GUIAS'));
        $identificacaoTransacao->appendChild($dom->createElement('sequencialTransacao', $batch->code));
        $identificacaoTransacao->appendChild($dom->createElement('dataRegistroTransacao', now()->format('Y-m-d')));
        $identificacaoTransacao->appendChild($dom->createElement('horaRegistroTransacao', now()->format('H:i:s')));

        $origem                 = $cabecalho->appendChild($dom->createElement('origem'));
        $identificacaoPrestador = $origem->appendChild($dom->createElement('identificacaoPrestador'));
        $identificacaoPrestador->appendChild($dom->createElement('codigoPrestadorNaOperadora', $batch->entity->code ?? ''));
        $identificacaoPrestador->appendChild($dom->createElement('cnpjPrestador', $this->digitsOnly((string) ($batch->entity->national_registration ?? ''))));

        $destino = $cabecalho->appendChild($dom->createElement('destino'));
        $destino->appendChild($dom->createElement('registroANS', $this->digitsOnly((string) ($batch->covenant->ans_registry ?? ''))));

        $cabecalho->appendChild($dom->createElement('Padrao', $batch->tiss_layout_version));
        $cabecalho->appendChild($dom->createElement('versaoPadrao', $batch->tiss_version));
    }

    private function appendLoteGuias(DOMDocument $dom, DOMElement $root, BillingBatch $batch): void
    {
        $prestadorParaOperadora = $root->appendChild($dom->createElement('prestadorParaOperadora'));
        $loteGuias              = $prestadorParaOperadora->appendChild($dom->createElement('loteGuias'));
        $loteGuias->appendChild($dom->createElement('numeroLote', $batch->code));

        $guiasTiss = $loteGuias->appendChild($dom->createElement('guiasTISS'));

        foreach ($batch->claims as $claim) {
            $guia = $guiasTiss->appendChild($dom->createElement('guiaSP-SADT'));

            $cabecalhoGuia = $guia->appendChild($dom->createElement('cabecalhoGuia'));
            $cabecalhoGuia->appendChild($dom->createElement('registroANS', $this->digitsOnly((string) ($batch->covenant->ans_registry ?? ''))));
            $cabecalhoGuia->appendChild($dom->createElement('numeroGuiaPrestador', $claim->guide_number ?: $claim->code));

            $beneficiario = $guia->appendChild($dom->createElement('dadosBeneficiario'));
            $beneficiario->appendChild($dom->createElement('numeroCarteira', (string) ($claim->patient?->card_number ?? 'SEM_CARTAO')));
            $beneficiario->appendChild($dom->createElement('nomeBeneficiario', $claim->patient?->person?->full_name ?? 'PACIENTE NAO INFORMADO'));

            $executante = $guia->appendChild($dom->createElement('dadosExecutante'));
            $executante->appendChild($dom->createElement('codigoPrestadorNaOperadora', $batch->entity->code ?? ''));

            $atendimento = $guia->appendChild($dom->createElement('dadosAtendimento'));
            $atendimento->appendChild($dom->createElement('dataAtendimento', $claim->attendance_date?->format('Y-m-d') ?? now()->format('Y-m-d')));

            $procedimento = $guia->appendChild($dom->createElement('procedimentoExecutado'));
            $procedimento->appendChild($dom->createElement('codigoTabela', '22')); // TUSS
            $procedimento->appendChild($dom->createElement('codigoProcedimento', $claim->tuss_code ?: '10101012'));
            $procedimento->appendChild($dom->createElement('descricaoProcedimento', $claim->procedure_description ?: 'CONSULTA OFTALMOLOGICA'));
            $procedimento->appendChild($dom->createElement('quantidadeExecutada', (string) $claim->quantity));
            $procedimento->appendChild($dom->createElement('valorUnitario', number_format((float) $claim->unit_price, 2, '.', '')));
            $procedimento->appendChild($dom->createElement('valorTotal', number_format((float) $claim->amount, 2, '.', '')));
        }
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
