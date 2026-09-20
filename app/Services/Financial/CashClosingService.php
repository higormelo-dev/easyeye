<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Exceptions\Financial\CashPeriodClosedException;
use App\Models\{CashClose, Entity};
use Illuminate\Support\Facades\DB;

/**
 * Fechamento de caixa por período. Calcula os totais via CashFlowService e
 * grava o CashClose, que passa a bloquear edições no intervalo.
 */
class CashClosingService
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
    ) {
    }

    public function closePeriod(
        string $entityId,
        string $from,
        string $to,
        ?string $userId = null,
        ?string $notes = null,
    ): CashClose {
        return DB::transaction(function () use ($entityId, $from, $to, $userId, $notes): CashClose {
            // Trava a linha da entidade como ponto de serialização: 2 fechamentos
            // simultâneos pra mesma clínica passam a fila um atrás do outro em vez
            // de ambos lerem "sem sobreposição" antes de qualquer um commitar.
            // Não há linha própria de "período" pra travar antes de existir — a
            // entidade é o único ancora estável disponível aqui.
            Entity::query()->whereKey($entityId)->lockForUpdate()->firstOrFail();

            if ($this->hasOverlap($entityId, $from, $to)) {
                throw new CashPeriodClosedException(__('financial.cash_period_overlap'));
            }

            $summary = $this->cashFlow->summary($entityId, $from, $to);

            return CashClose::query()->create([
                'entity_id'     => $entityId,
                'closed_by'     => $userId,
                'period_start'  => $from,
                'period_end'    => $to,
                'closed_at'     => now(),
                'total_income'  => $summary['income'],
                'total_expense' => $summary['expense'],
                'balance'       => $summary['balance'],
                'notes'         => $notes,
            ]);
        });
    }

    public function reopen(CashClose $cashClose): void
    {
        $cashClose->delete();
    }

    /** Há fechamento ativo que se sobrepõe ao intervalo informado? */
    private function hasOverlap(string $entityId, string $from, string $to): bool
    {
        return CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->exists();
    }
}
