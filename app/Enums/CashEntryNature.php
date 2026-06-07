<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Natureza do lançamento de caixa — distingue a origem do dinheiro para que
 * os relatórios não confundam recebimento de balcão com recebimento de guia.
 *
 *  - General : lançamento avulso/manual (fluxo de caixa direto).
 *  - Desk    : recebido no balcão na chegada (atendimento particular).
 *  - Copay   : co-participação do paciente num atendimento de convênio
 *              (o valor do convênio é faturado à parte via guia).
 *  - Covenant: recebimento de uma guia/claim do convênio (BillingService).
 */
enum CashEntryNature: string
{
    case General  = 'general';
    case Desk     = 'desk';
    case Copay    = 'copay';
    case Covenant = 'covenant';

    public function label(): string
    {
        return match ($this) {
            self::General  => __('financial.nature_general'),
            self::Desk     => __('financial.nature_desk'),
            self::Copay    => __('financial.nature_copay'),
            self::Covenant => __('financial.nature_covenant'),
        };
    }

    /** Recebido diretamente do paciente (balcão), seja particular ou co-participação. */
    public function isFromPatient(): bool
    {
        return in_array($this, [self::Desk, self::Copay], true);
    }
}
