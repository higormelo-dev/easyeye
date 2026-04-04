<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class TissReturnParser
{
    /**
     * @return array{
     *   protocol_number:?string,
     *   batch_number:?string,
     *   status:string,
     *   glosas:array<int, array{
     *      code:string,
     *      description:?string,
     *      amount:float,
     *      guide_number_provider:?string,
     *      procedure_code:?string,
     *      tuss_code:?string
     *   }>
     * }
     */
    public function parse(string $xml): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();

        if (! $loaded) {
            return [
                'protocol_number' => null,
                'batch_number'    => null,
                'status'          => 'error',
                'glosas'          => [],
            ];
        }

        $xpath = new DOMXPath($dom);

        $protocol    = $this->firstValue($xpath, 'numeroProtocolo');
        $batchNumber = $this->firstValue($xpath, 'numeroLote');
        $statusRaw   = mb_strtolower(
            $this->firstValue($xpath, 'statusProtocolo')
            ?? $this->firstValue($xpath, 'situacaoProtocolo')
            ?? '',
        );

        $status = match (true) {
            str_contains($statusRaw, 'aceit')   => 'accepted',
            str_contains($statusRaw, 'rejeit')  => 'rejected',
            str_contains($statusRaw, 'process') => 'processing',
            default                             => 'received',
        };

        $glosaNodes = $xpath->query('//*[local-name()="glosa"]') ?: [];
        $glosas     = [];

        foreach ($glosaNodes as $node) {
            $code                = null;
            $description         = null;
            $amount              = null;
            $procedureCode       = null;
            $tussCode            = null;
            $guideNumberProvider = null;

            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $tag = $child->localName ?: $child->tagName;

                if ($tag === 'codigoGlosa') {
                    $code = trim($child->textContent);
                }

                if ($tag === 'descricaoGlosa') {
                    $description = trim($child->textContent);
                }

                if ($tag === 'valorGlosa') {
                    $amount = (float) str_replace(',', '.', trim($child->textContent));
                }
            }

            $guideNumberProvider = $this->evaluateString(
                $xpath,
                'string((ancestor::*[local-name()="guiaSP-SADT" or local-name()="guiaConsulta"][1]/*[local-name()="cabecalhoGuia"]/*[local-name()="numeroGuiaPrestador"])[1])',
                $node,
            );

            $procedureCode = $this->evaluateString(
                $xpath,
                'string((ancestor::*[local-name()="procedimentoExecutado"][1]/*[local-name()="codigoProcedimento"])[1])',
                $node,
            );

            $tussCode = $this->evaluateString(
                $xpath,
                'string((ancestor::*[local-name()="procedimentoExecutado"][1]/*[local-name()="codigoTabela"])[1])',
                $node,
            );

            if ($code !== null) {
                $glosas[] = [
                    'code'                  => $code,
                    'description'           => $description,
                    'amount'                => $amount ?? 0,
                    'guide_number_provider' => $guideNumberProvider,
                    'procedure_code'        => $procedureCode,
                    'tuss_code'             => $tussCode,
                ];
            }
        }

        return [
            'protocol_number' => $protocol,
            'batch_number'    => $batchNumber,
            'status'          => $status,
            'glosas'          => $glosas,
        ];
    }

    private function firstValue(DOMXPath $xpath, string $tag): ?string
    {
        return $this->evaluateString($xpath, sprintf('string((//*[local-name()="%s"])[1])', $tag));
    }

    private function evaluateString(DOMXPath $xpath, string $query, ?DOMNode $contextNode = null): ?string
    {
        $value = $xpath->evaluate($query, $contextNode);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
