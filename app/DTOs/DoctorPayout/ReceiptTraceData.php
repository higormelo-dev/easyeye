<?php

declare(strict_types=1);

namespace App\DTOs\DoctorPayout;

use App\Enums\DoctorPayout\DoctorPayoutReceiptStatus;

/**
 * Rastreio de recebimento de um item de repasse, em centavos: valores do
 * ATENDIMENTO (unidade de cobrança = agendamento $unitId). Procedimentos
 * iguais pareados com o mesmo agendamento (ex.: OD e OE) mostram o
 * atendimento inteiro, com $sharedBy = quantos o dividem — quem soma deve
 * contar cada $unitId uma vez.
 *
 * differenceCents = cobrado − glosa − recebido − a receber: ≠ 0 quando a
 * guia foi paga a menor sem glosa registrada (ou marcada paga sem
 * lançamento no caixa).
 */
final readonly class ReceiptTraceData
{
    /**
     * @param int                                                                    $position posição do item entre os que dividem o atendimento (ordem do rateio)
     * @param list<array{id: string, date: string, amount_cents: int, kind: string}> $receipts recebimentos considerados (só quando pedidos — retrato da parcela)
     */
    public function __construct(
        public DoctorPayoutReceiptStatus $status,
        public int $billedCents = 0,
        public int $glosaCents = 0,
        public int $receivedCents = 0,
        public int $openCents = 0,
        public int $differenceCents = 0,
        public ?string $unitId = null,
        public int $sharedBy = 1,
        public int $position = 0,
        public array $receipts = [],
        public int $actManualCents = 0,
    ) {
    }

    /**
     * Recebimento manual alocado ao PRÓPRIO ato (não ao atendimento) — soma à
     * parte do ato, sem entrar no rateio do atendimento.
     *
     * @param list<array{id: string, date: string, amount_cents: int, kind: string}> $receipts
     */
    public function withActManual(int $cents, array $receipts): self
    {
        return new self(
            status: $this->status,
            billedCents: $this->billedCents,
            glosaCents: $this->glosaCents,
            receivedCents: $this->receivedCents,
            openCents: $this->openCents,
            differenceCents: $this->differenceCents,
            unitId: $this->unitId,
            sharedBy: $this->sharedBy,
            position: $this->position,
            receipts: [...$this->receipts, ...$receipts],
            actManualCents: $this->actManualCents + $cents,
        );
    }

    /** Líquido esperado do atendimento: faturado − glosa (nunca negativo). */
    public function expectedCents(): int
    {
        return max(0, $this->billedCents - $this->glosaCents);
    }

    public static function notLinked(): self
    {
        return new self(DoctorPayoutReceiptStatus::NotLinked);
    }

    public function isLinked(): bool
    {
        return $this->status !== DoctorPayoutReceiptStatus::NotLinked;
    }

    /**
     * Valores em reais; nulos quando o item não tem cobrança própria.
     *
     * @return array{status: string, billed: ?float, glosa: ?float, received: ?float, open: ?float, difference: ?float, shared_by: int}
     */
    public function toArray(): array
    {
        $money = fn (int $cents): ?float => $this->isLinked() ? $cents / 100 : null;

        return [
            'status'     => $this->status->value,
            'billed'     => $money($this->billedCents),
            'glosa'      => $money($this->glosaCents),
            'received'   => $money($this->receivedCents),
            'open'       => $money($this->openCents),
            'difference' => $money($this->differenceCents),
            'shared_by'  => $this->sharedBy,
        ];
    }
}
