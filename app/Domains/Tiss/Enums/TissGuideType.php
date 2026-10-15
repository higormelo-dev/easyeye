<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissGuideType: string
{
    case Consultation     = 'consultation';
    case Sadt             = 'sadt';
    case InpatientRequest = 'inpatient_request';
    case OpmeAttachment   = 'opme_attachment';

    public function xmlTag(): string
    {
        return match ($this) {
            self::Consultation     => 'guiaConsulta',
            self::Sadt             => 'guiaSP-SADT',
            self::InpatientRequest => 'solicitacaoInternacao',
            self::OpmeAttachment   => 'anexoSolicitacaoOPME',
        };
    }

    /**
     * Guia que viaja dentro de `loteGuias` (ctm_guiaLote só aceita consulta,
     * SP-SADT, resumo de internação, honorários e odonto). A solicitação de
     * internação e o anexo de OPME são mensagens AVULSAS (ver isSurgicalRequest()).
     */
    public function isBatchable(): bool
    {
        return match ($this) {
            self::Consultation, self::Sadt => true,
            self::InpatientRequest, self::OpmeAttachment => false,
        };
    }

    /** Pedido do módulo cirúrgico: mensagem própria, uma transmissão por guia, sem lote. */
    public function isSurgicalRequest(): bool
    {
        return ! $this->isBatchable();
    }

    /** `tipoTransacao` do cabeçalho da mensagem avulsa (null para o que vai em lote). */
    public function transactionType(): ?string
    {
        return match ($this) {
            self::Consultation, self::Sadt => null,
            self::InpatientRequest => 'SOLICITACAO_PROCEDIMENTOS',
            self::OpmeAttachment   => 'ENVIO_ANEXO',
        };
    }

    /** `document_type` do XML guardado em tiss_xml_documents. */
    public function documentType(): string
    {
        return $this->isBatchable() ? 'batch_submission' : $this->value;
    }
}
