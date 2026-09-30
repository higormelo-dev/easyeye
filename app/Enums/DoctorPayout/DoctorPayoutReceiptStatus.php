<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Situação do recebimento pela clínica do atendimento de um item de repasse
 * (rastreio somente leitura — DoctorPayoutReceiptTracer). Recebido = receita
 * PAGA no Fluxo de Caixa; guia "paga" sem lançamento não prova recebimento.
 */
enum DoctorPayoutReceiptStatus: string
{
    /** Exame de equipamento / procedimento fora do agendamento: sem cobrança própria para rastrear. */
    case NotLinked = 'not_linked';

    /** Nenhum lançamento nem guia ativos (ou só valores zerados/cancelados). */
    case NoCharge = 'no_charge';

    /** Só guia em rascunho: ainda não enviada ao convênio. */
    case ToBill = 'to_bill';

    /** Cobrado/faturado e nada recebido ainda. */
    case Awaiting = 'awaiting';

    /** Parte recebida (ex.: coparticipação) e ainda há valor a receber. */
    case Partial = 'partial';

    /** Nada mais a receber e algo recebido (glosa/diferença ficam nos valores). */
    case Received = 'received';

    /** Glosa total: nada recebido e nada mais a receber (salvo recurso). */
    case Denied = 'denied';

    /** Guia marcada como paga sem receita no caixa (dado legado): não prova recebimento. */
    case Unconfirmed = 'unconfirmed';

    public function label(): string
    {
        return __("financial_doctor_payouts.receipt_statuses.{$this->value}");
    }

    /**
     * A partir dos totais do atendimento, em centavos.
     *
     * @param int $billedCents   cobrado no balcão + faturado nas guias (antes da glosa)
     * @param int $glosaCents    glosa registrada nas guias
     * @param int $receivedCents receitas pagas no caixa (balcão + guias)
     * @param int $openCents     ainda a receber (balcão pendente + guias não pagas, líquido de glosa)
     * @param int $toBillCents   parte de $openCents em guias ainda em rascunho
     */
    public static function fromTotals(int $billedCents, int $glosaCents, int $receivedCents, int $openCents, int $toBillCents): self
    {
        if ($openCents > 0) {
            if ($receivedCents > 0) {
                return self::Partial;
            }

            return $openCents === $toBillCents ? self::ToBill : self::Awaiting;
        }

        return match (true) {
            $receivedCents > 0 => self::Received,
            $glosaCents > 0    => self::Denied,
            $billedCents > 0   => self::Unconfirmed,
            default            => self::NoCharge,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
