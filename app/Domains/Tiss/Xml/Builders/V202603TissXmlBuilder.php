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
    private const NAMESPACE_URI = 'http://www.ans.gov.br/padroes/tiss/schemas';

    public function buildBatch(TissBatch $batch): string
    {
        $data = TissBatchData::fromModel($batch);

        $dom               = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Namespace declarado só na raiz (xmlns=... vira o namespace padrão
        // pra toda a árvore quando o XML é reparseado — não precisa repetir
        // createElementNS em cada elemento filho; confirmado com
        // DOMDocument::schemaValidate() contra o XSD oficial).
        $root = $dom->createElementNS(self::NAMESPACE_URI, 'mensagemTISS');
        $dom->appendChild($root);

        $this->appendHeader($dom, $root, $data);
        $this->appendBatch($dom, $root, $data);
        $this->appendEpilogo($dom, $root);

        return (string) $dom->saveXML();
    }

    private function appendHeader(DOMDocument $dom, DOMElement $root, TissBatchData $data): void
    {
        $header = $root->appendChild($dom->createElement('cabecalho'));
        $tx     = $header->appendChild($dom->createElement('identificacaoTransacao'));

        $tx->appendChild($dom->createElement('tipoTransacao', 'ENVIO_LOTE_GUIAS'));
        $tx->appendChild($dom->createElement('sequencialTransacao', $this->shortIdentifier($data->batchNumber)));
        $tx->appendChild($dom->createElement('dataRegistroTransacao', now()->format('Y-m-d')));
        $tx->appendChild($dom->createElement('horaRegistroTransacao', now()->format('H:i:s')));

        // origem (choice: identificacaoPrestador OU registroANS) — dentro de
        // identificacaoPrestador, ct_prestadorIdentificacao é OUTRO choice
        // (CNPJ, CPF ou codigoPrestadorNaOperadora, nunca mais de um). Usamos
        // codigoPrestadorNaOperadora (já é nosso identificador estável de
        // contrato); não cabe CNPJ aqui sem trocar de estratégia por completo.
        $origin         = $header->appendChild($dom->createElement('origem'));
        $identification = $origin->appendChild($dom->createElement('identificacaoPrestador'));
        $identification->appendChild($dom->createElement('codigoPrestadorNaOperadora', $data->providerCode));

        $dest = $header->appendChild($dom->createElement('destino'));
        $dest->appendChild($dom->createElement('registroANS', $this->digitsOnly($data->operatorAnsCode)));

        // dm_versao não aceita zero à esquerda ("4.03.00", não "04.03.00").
        // versaoPadrao não existe em cabecalhoTransacao — foi removido, não
        // só reordenado (confirmado por schemaValidate() real).
        $header->appendChild($dom->createElement('Padrao', $this->stripLeadingVersionZero($data->layoutVersion)));
    }

    private function appendBatch(DOMDocument $dom, DOMElement $root, TissBatchData $data): void
    {
        $providerToOperator = $root->appendChild($dom->createElement('prestadorParaOperadora'));
        $loteGuias          = $providerToOperator->appendChild($dom->createElement('loteGuias'));
        $loteGuias->appendChild($dom->createElement('numeroLote', $this->shortIdentifier($data->batchNumber)));

        $guidesNode = $loteGuias->appendChild($dom->createElement('guiasTISS'));

        foreach ($data->guides as $guide) {
            if ($guide->guideType === TissGuideType::Consultation) {
                $this->appendConsultaGuide($dom, $guidesNode, $guide, $data);

                continue;
            }

            $this->appendSadtGuide($dom, $guidesNode, $guide, $data);
        }

        // ctm_guiaLote (tipo real de loteGuias) só tem numeroLote + guiasTISS.
        // Não existe hashLote aqui — o hash de mensagem de verdade é o
        // epilogo, no nível da mensagemTISS (appendEpilogo()).
    }

    private function appendEpilogo(DOMDocument $dom, DOMElement $root): void
    {
        $bodyXml = $dom->saveXML($root);
        $hash    = md5($bodyXml !== false ? $bodyXml : '');

        $epilogo = $root->appendChild($dom->createElement('epilogo'));
        $epilogo->appendChild($dom->createElement('hash', $hash));
    }

    private function appendConsultaGuide(
        DOMDocument $dom,
        DOMElement $parent,
        TissGuideData $guide,
        TissBatchData $batch,
    ): void {
        $node   = $parent->appendChild($dom->createElement('guiaConsulta'));
        $header = $node->appendChild($dom->createElement('cabecalhoConsulta'));

        $header->appendChild($dom->createElement('registroANS', $this->digitsOnly($batch->operatorAnsCode)));
        $header->appendChild($dom->createElement('numeroGuiaPrestador', $guide->guideNumber));

        // ct_beneficiarioDados (4.03): nomeBeneficiario foi removido na
        // v4.00.00. Só numeroCarteira + atendimentoRN (obrigatório).
        $beneficiary = $node->appendChild($dom->createElement('dadosBeneficiario'));
        $beneficiary->appendChild($dom->createElement('numeroCarteira', $guide->beneficiaryCard ?: 'SEM_CARTAO'));
        $beneficiary->appendChild($dom->createElement('atendimentoRN', $guide->atendimentoRN));

        // ctm_consultaGuia: contratadoExecutante ESTENDE ct_contratadoDados e
        // leva o CNES aninhado dentro dele (diferente da guia SP-SADT, onde
        // CNES é irmão de contratadoExecutante dentro de dadosExecutante).
        $executor = $this->appendContratadoDados($dom, $node, 'contratadoExecutante', $batch);

        if (filled($batch->providerCnes)) {
            $executor->appendChild($dom->createElement('CNES', $batch->providerCnes));
        }

        $this->appendProfessional($dom, $node, 'profissionalExecutante', $guide, $batch);

        $node->appendChild($dom->createElement('indicacaoAcidente', $guide->accidentIndicator));

        $attendance = $node->appendChild($dom->createElement('dadosAtendimento'));
        $attendance->appendChild($dom->createElement('regimeAtendimento', $guide->regimeAtendimento));
        $attendance->appendChild($dom->createElement('dataAtendimento', $guide->attendanceDate));
        $attendance->appendChild($dom->createElement('tipoConsulta', $guide->tipoConsulta));

        $procedure = $attendance->appendChild($dom->createElement('procedimento'));
        $firstItem = $guide->items[0] ?? null;
        $procedure->appendChild($dom->createElement('codigoTabela', $firstItem?->tableCode ?? '22'));
        $procedure->appendChild($dom->createElement('codigoProcedimento', $firstItem?->tussCode ?? ''));
        $procedure->appendChild($dom->createElement('valorProcedimento', $this->currency($firstItem?->totalAmount ?? $guide->totalAmount)));

        // ctm_consultaGuia não tem campo de indicação clínica (CID) — isso só
        // existe em dadosSolicitacao da guia SP-SADT. O CID que a clínica
        // registra pra pré-validação anti-glosa é regra de negócio interna,
        // não um campo real do XML de guia de consulta.

        // Lateralidade (OD/OE/AO) não é campo estruturado em nenhuma guia
        // TISS. "observacao" (opcional, st_texto500, irmã de dadosAtendimento)
        // é o único canal do schema pra texto livre no nível da guia —
        // guiaConsulta é o único tipo de guia que este sistema hoje emite
        // (BillingService sempre cria guide_type=consultation), então é
        // aqui que a lateralidade precisa aparecer de fato, não em
        // descricaoProcedimento (que só existe em guiaSP-SADT).
        if (filled($firstItem?->eyeSide)) {
            $node->appendChild($dom->createElement('observacao', "Lateralidade: {$firstItem->eyeSide}"));
        }
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
        $beneficiary->appendChild($dom->createElement('atendimentoRN', $guide->atendimentoRN));

        // dadosSolicitante (ctm_sp-sadtGuia): contratadoSolicitante +
        // nomeContratadoSolicitante (nome do CONTRATADO/clínica, obrigatório)
        // + profissionalSolicitante — mesma limitação de dado do executante.
        $solicitante = $node->appendChild($dom->createElement('dadosSolicitante'));
        $this->appendContratadoDados($dom, $solicitante, 'contratadoSolicitante', $batch);
        $solicitante->appendChild($dom->createElement('nomeContratadoSolicitante', $batch->providerName ?: 'PRESTADOR NAO INFORMADO'));

        $this->appendProfessional($dom, $solicitante, 'profissionalSolicitante', $guide, $batch);

        $solicitacao = $node->appendChild($dom->createElement('dadosSolicitacao'));
        $solicitacao->appendChild($dom->createElement('dataSolicitacao', $guide->attendanceDate));
        $solicitacao->appendChild($dom->createElement('caraterAtendimento', $guide->caraterAtendimentoSadt));

        if ($guide->clinicalIndication) {
            $solicitacao->appendChild($dom->createElement('indicacaoClinica', mb_substr($guide->clinicalIndication, 0, 500)));
        }

        // ctm_sp-sadtGuia: dadosExecutante é SEQUENCE de contratadoExecutante
        // (ct_contratadoDados puro, sem extensão) + CNES como IRMÃO — não
        // aninhado, ao contrário da guia de consulta.
        $exec = $node->appendChild($dom->createElement('dadosExecutante'));
        $this->appendContratadoDados($dom, $exec, 'contratadoExecutante', $batch);

        if (filled($batch->providerCnes)) {
            $exec->appendChild($dom->createElement('CNES', $batch->providerCnes));
        }

        $attendance = $node->appendChild($dom->createElement('dadosAtendimento'));
        $attendance->appendChild($dom->createElement('tipoAtendimento', $guide->tipoAtendimentoSadt));
        $attendance->appendChild($dom->createElement('indicacaoAcidente', $guide->accidentIndicator));
        $attendance->appendChild($dom->createElement('regimeAtendimento', $guide->regimeAtendimento));

        if ($guide->items !== []) {
            $procedures = $node->appendChild($dom->createElement('procedimentosExecutados'));

            foreach ($guide->items as $index => $item) {
                $this->appendSadtProcedure($dom, $procedures, $item, $index + 1);
            }
        }

        $valorTotal = $node->appendChild($dom->createElement('valorTotal'));
        $valorTotal->appendChild($dom->createElement('valorTotalGeral', $this->currency($guide->totalAmount)));
    }

    private function appendSadtProcedure(DOMDocument $dom, DOMElement $parent, TissGuideItemData $item, int $sequentialNumber): void
    {
        // ct_procedimentoExecutadoSadt (tipo real usado por guiaSP-SADT) —
        // não tem centroConsumo (esse campo só existe em
        // ct_procedimentoExecutado/Int/Outras, usados por honorário/
        // internação, não por SP-SADT ambulatorial).
        $proc = $parent->appendChild($dom->createElement('procedimentoExecutado'));
        $proc->appendChild($dom->createElement('sequencialItem', (string) $sequentialNumber));
        $proc->appendChild($dom->createElement('dataExecucao', $item->executionDate ?: now()->toDateString()));

        $procedimento = $proc->appendChild($dom->createElement('procedimento'));
        $procedimento->appendChild($dom->createElement('codigoTabela', $item->tableCode));
        $procedimento->appendChild($dom->createElement('codigoProcedimento', $item->tussCode));
        $procedimento->appendChild($dom->createElement('descricaoProcedimento', $this->descriptionWithEyeSide($item)));

        $proc->appendChild($dom->createElement('quantidadeExecutada', (string) max(1, (int) $item->quantity)));
        // reducaoAcrescimo é obrigatório (percentual de ajuste sobre a
        // tabela) — não modelamos negociação de tabela por item hoje, "0"
        // representa sem ajuste.
        $proc->appendChild($dom->createElement('reducaoAcrescimo', '0.00'));
        $proc->appendChild($dom->createElement('valorUnitario', $this->currency($item->unitAmount)));
        $proc->appendChild($dom->createElement('valorTotal', $this->currency($item->totalAmount)));
    }

    /**
     * ct_contratadoDados é um CHOICE (codigoPrestadorNaOperadora, cpfContratado
     * ou cnpjContratado) — usamos codigoPrestadorNaOperadora, nosso
     * identificador estável de contrato. CNES (quando existe) é anexado pelo
     * caller na posição certa: aninhado (guia de consulta) ou irmão (SP-SADT)
     * — a estrutura difere entre os dois tipos de guia.
     */
    private function appendContratadoDados(DOMDocument $dom, DOMElement $parent, string $elementName, TissBatchData $batch): DOMElement
    {
        $contratado = $parent->appendChild($dom->createElement($elementName));
        $contratado->appendChild($dom->createElement('codigoPrestadorNaOperadora', $batch->providerCode));

        return $contratado;
    }

    /**
     * ct_contratadoProfissionalDados: nomeProfissional é opcional, mas
     * conselhoProfissional/numeroConselhoProfissional/UF/CBOS são
     * obrigatórios TODOS JUNTOS quando o elemento existe. Só emite o bloco
     * completo quando temos o número do conselho (doctors.record); sem ele,
     * emite só o nome (elemento fica incompleto pra validação, mas isola o
     * erro exatamente no dado que falta, em vez de omitir tudo).
     */
    private function appendProfessional(
        DOMDocument $dom,
        DOMElement $parent,
        string $elementName,
        TissGuideData $guide,
        TissBatchData $batch,
    ): void {
        if (blank($guide->doctorName)) {
            return;
        }

        $professional = $parent->appendChild($dom->createElement($elementName));
        $professional->appendChild($dom->createElement('nomeProfissional', $guide->doctorName));

        if (blank($guide->doctorCouncilNumber)) {
            return;
        }

        // dm_conselhoProfissional "06" = CRM (Conselho Regional de Medicina)
        // — seguro pra esta clínica, todo "doctor" aqui é médico (rótulo
        // "CRM" já é fixo no próprio formulário de cadastro).
        $professional->appendChild($dom->createElement('conselhoProfissional', '06'));
        $professional->appendChild($dom->createElement('numeroConselhoProfissional', $guide->doctorCouncilNumber));

        // UF do conselho não é capturada por médico — usa o estado da
        // clínica como aproximação (na prática, quase sempre a mesma UF de
        // registro do profissional que atende ali). dm_UF é o código IBGE
        // numérico (35=SP), não a sigla — confirmado por schemaValidate()
        // real rejeitando "SP" com a lista de códigos válida.
        $ibgeUf = $this->ibgeStateCode($batch->providerState);

        if ($ibgeUf !== null) {
            $professional->appendChild($dom->createElement('UF', $ibgeUf));
        }

        $professional->appendChild($dom->createElement('CBOS', $guide->doctorCbo ?: (string) config('tiss.defaults.cbo_oftalmologista', '225265')));
    }

    /**
     * dm_UF é o código IBGE numérico da UF (35=SP, 33=RJ...), não a sigla —
     * Entity.state guarda a sigla ("SP"). Sem correspondência (sigla
     * desconhecida/estrangeira), retorna null e o campo UF fica de fora
     * (mais seguro que emitir um código inválido).
     */
    private function ibgeStateCode(?string $stateAbbreviation): ?string
    {
        static $codes = [
            'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
            'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27', 'SE' => '28', 'BA' => '29',
            'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35',
            'PR' => '41', 'SC' => '42', 'RS' => '43',
            'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
        ];

        return $codes[mb_strtoupper((string) $stateAbbreviation)] ?? null;
    }

    private function currency(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * TISS não tem campo estruturado de lateralidade (OD/OE/AO) — nem
     * "lateral" existe fora de odontologia. Único canal aceito pelo schema é
     * texto livre, então anexamos na descrição. descricaoProcedimento é
     * st_texto150; truncamos a base pra sempre caber o sufixo.
     */
    private function descriptionWithEyeSide(TissGuideItemData $item): string
    {
        if (blank($item->eyeSide)) {
            return $item->description;
        }

        $suffix = " ({$item->eyeSide})";

        return mb_substr($item->description, 0, 150 - mb_strlen($suffix)) . $suffix;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    /**
     * dm_versao não aceita zero à esquerda no primeiro grupo ("4.03.00", não
     * "04.03.00") — layout_version no banco fica com zero por ser o formato
     * de exibição humano já usado no resto do projeto (UI, TissVersion).
     */
    private function stripLeadingVersionZero(string $value): string
    {
        return preg_replace('/^0+(?=\d)/', '', $value) ?: $value;
    }

    /**
     * st_texto12 (numeroLote, sequencialTransacao) limita a 12 caracteres —
     * nosso batch_number ("LOT-202604-0001") tem 15. Mantém só os
     * caracteres alfanuméricos finais (onde está o dígito que desambigua
     * lotes do mesmo mês), truncados a 12; não muda o batch_number
     * armazenado, só a representação usada neste XML. Redesenhar o formato
     * de numeração pra caber nativamente em 12 chars é decisão maior, fora
     * do escopo desta correção estrutural.
     */
    private function shortIdentifier(string $value): string
    {
        $alphanumeric = preg_replace('/[^A-Za-z0-9]/', '', $value) ?: $value;

        return mb_substr($alphanumeric, -12);
    }
}
