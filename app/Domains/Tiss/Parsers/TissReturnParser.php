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

    /**
     * Resposta de AUTORIZAÇÃO da operadora. Dois envelopes (XSD TISS 4.03, `operadoraParaPrestador`):
     *
     *  - solicitação de internação: `autorizacaoServicos`/`situacaoAutorizacao` com
     *    `autorizacaoInternacao`, `autorizacaoServico` ou a mensagem de erro;
     *  - anexo de OPME: `recebimentoAnexo` → `protocoloRecebimentoAnexo` → `anexosClinicos` →
     *    `anexoOPME` (itens em `servicosAutorizadosOPME`), ou `recebimentoAnexo` → `mensagemErro`.
     *    Os outros anexos (quimioterapia, radioterapia, situação inicial) não são autorização
     *    cirúrgica.
     *
     * Devolve null quando o XML não é uma resposta de autorização (retorno de lote, recibo de
     * protocolo...). Aditivo: `parse()` e seu formato não mudam.
     *
     * statusSolicitacao (dm_statusSolicitacao): 1 autorizado, 2 em análise, 3 negado,
     * 4 aguardando justificativa técnica, 5 aguardando documentação, 6 cancelada,
     * 7 autorizado parcialmente.
     *
     * @return array{
     *   kind: string,
     *   guide_number_provider: ?string,
     *   guide_number_operator: ?string,
     *   password: ?string,
     *   authorization_date: ?string,
     *   valid_until: ?string,
     *   status_code: ?string,
     *   status: ?string,
     *   denial_codes: list<string>,
     *   denial_descriptions: list<string>,
     *   observation: ?string,
     *   authorized_services: list<array{code: ?string, requested: int, authorized: int}>,
     *   error: ?array{code: ?string, description: ?string}
     * }|null
     */
    public function parseAuthorization(string $xml): ?array
    {
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);

        $container = $xpath->query('//*[local-name()="autorizacaoServicos" or local-name()="situacaoAutorizacao"]')?->item(0)
            // Anexo de OPME: a decisão mora em `anexoOPME`; a recusa da mensagem, em `recebimentoAnexo/mensagemErro`.
            ?? $xpath->query('//*[local-name()="anexoOPME"]')?->item(0)
            ?? $xpath->query('//*[local-name()="recebimentoAnexo"][./*[local-name()="mensagemErro"]]')?->item(0);

        if ($container === null) {
            return null;
        }

        $value = fn (string $tag): ?string => $this->evaluateString($xpath, sprintf('string((.//*[local-name()="%s"])[1])', $tag), $container);

        $errorNode = $xpath->query('./*[local-name()="mensagemErro"]', $container)?->item(0);

        if ($errorNode !== null) {
            return $this->authorizationShape(kind: 'error', error: [
                'code'        => $this->evaluateString($xpath, 'string((.//*[local-name()="codigoGlosa"])[1])', $errorNode),
                'description' => $this->evaluateString($xpath, 'string((.//*[local-name()="descricaoGlosa"])[1])', $errorNode),
            ]);
        }

        $statusCode = $value('statusSolicitacao');

        // Sem situação e sem dados de autorização: não é uma resposta de autorização.
        if ($statusCode === null && ! ($xpath->query('.//*[local-name()="dadosAutorizacao"]', $container)?->length > 0)) {
            return null;
        }

        $services = [];

        foreach ($xpath->query('.//*[local-name()="servicoAutorizado" or local-name()="servicosAutorizadosOPME" or local-name()="opmeAutorizada"]', $container) ?: [] as $node) {
            $services[] = [
                'code'       => $this->evaluateString($xpath, 'string((.//*[local-name()="codigoProcedimento"])[1])', $node),
                'requested'  => (int) $this->evaluateString($xpath, 'string((.//*[local-name()="quantidadeSolicitada"])[1])', $node),
                'authorized' => (int) $this->evaluateString($xpath, 'string((.//*[local-name()="quantidadeAutorizada"])[1])', $node),
            ];
        }

        $codes        = [];
        $descriptions = [];

        foreach ($xpath->query('.//*[local-name()="motivosNegativa"]//*[local-name()="codigoGlosa"]', $container) ?: [] as $node) {
            $codes[] = trim($node->textContent);
        }

        foreach ($xpath->query('.//*[local-name()="motivosNegativa"]//*[local-name()="descricaoGlosa"]', $container) ?: [] as $node) {
            $descriptions[] = trim($node->textContent);
        }

        return $this->authorizationShape(
            kind: 'authorization',
            guideProvider: $value('numeroGuiaPrestador'),
            guideOperator: $value('numeroGuiaOperadora'),
            password: $value('senha'),
            date: $value('dataAutorizacao'),
            validUntil: $value('dataValidadeSenha'),
            statusCode: $statusCode,
            status: match ($statusCode) {
                '1'     => 'authorized',
                '2'     => 'in_analysis',
                '3'     => 'denied',
                '4'     => 'awaiting_justification',
                '5'     => 'awaiting_documents',
                '6'     => 'cancelled',
                '7'     => 'partially_authorized',
                default => null,
            },
            codes: array_values(array_filter($codes, fn ($c) => $c !== '')),
            descriptions: array_values(array_filter($descriptions, fn ($d) => $d !== '')),
            // No anexo de OPME a observação do recibo fica no envelope (`protocoloRecebimentoAnexo`), fora de `anexoOPME`.
            observation: $value('observacao') ?? $this->evaluateString($xpath, 'string((ancestor::*[local-name()="protocoloRecebimentoAnexo"][1]/*[local-name()="observacao"])[1])', $container),
            services: $services,
        );
    }

    /**
     * @param list<string>                                                $codes
     * @param list<string>                                                $descriptions
     * @param list<array{code: ?string, requested: int, authorized: int}> $services
     * @param array{code: ?string, description: ?string}|null             $error
     *
     * @return array<string, mixed>
     */
    private function authorizationShape(
        string $kind,
        ?string $guideProvider = null,
        ?string $guideOperator = null,
        ?string $password = null,
        ?string $date = null,
        ?string $validUntil = null,
        ?string $statusCode = null,
        ?string $status = null,
        array $codes = [],
        array $descriptions = [],
        ?string $observation = null,
        array $services = [],
        ?array $error = null,
    ): array {
        return [
            'kind'                  => $kind,
            'guide_number_provider' => $guideProvider,
            'guide_number_operator' => $guideOperator,
            'password'              => $password,
            'authorization_date'    => $date,
            'valid_until'           => $validUntil,
            'status_code'           => $statusCode,
            'status'                => $status,
            'denial_codes'          => $codes,
            'denial_descriptions'   => $descriptions,
            'observation'           => $observation,
            'authorized_services'   => $services,
            'error'                 => $error,
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
