<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\{FinancialEntryStatus, FinancialEntryType, PaymentMethod};
use App\Exceptions\Financial\CashPeriodClosedException;
use App\Models\{CashClose, FinancialCashEntry};
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fechamento de caixa por período. Calcula os totais via CashFlowService e
 * grava o CashClose, que passa a bloquear edições no intervalo.
 */
class CashClosingService
{
    /** Quantos fechamentos sobrepostos a prévia lista no aviso. */
    private const OVERLAP_SAMPLE = 5;

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
            // Lock EXCLUSIVO do caixa da clínica (CashPeriodLock): 2 fechamentos
            // simultâneos passam um atrás do outro (o 2º vê a sobreposição), e o
            // fechamento espera os lançamentos em andamento — os totais abaixo
            // já os incluem — e segura os novos até o COMMIT, quando eles
            // passam a ver o período fechado. Antes: FOR UPDATE na linha de
            // `entities` — só esperava quem já tinha feito o INSERT; quem já
            // tinha checado o período ficava preso no INSERT (FK) até o COMMIT
            // do fechamento e então gravava DENTRO do período fechado. E ainda
            // travava todo INSERT com FK para a clínica durante o fechamento.
            CashPeriodLock::forClosing($entityId);

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

    /**
     * Prévia do fechamento, SÓ LEITURA (sem CashPeriodLock: não trava o caixa
     * enquanto a tela é consultada). As chaves de summary() — o MESMO cálculo
     * gravado no snapshot por closePeriod(), incluindo pendentes — mais o
     * apoio à conferência: quantos lançamentos entram, o que fica pendente
     * (a receber e a pagar), totais por forma de pagamento e se o intervalo
     * cruza um fechamento ativo (o closePeriod continua recusando).
     *
     * @return array{
     *     income: float, expense: float, balance: float, pending: float,
     *     entries_count: int, pending_count: int, pending_income: float, pending_expense: float,
     *     by_payment_method: list<array{method: ?string, label: ?string, income: float, expense: float, count: int}>,
     *     overlaps: bool, overlapping_periods: list<array{period_start: string, period_end: string}>
     * }
     */
    public function preview(string $entityId, string $from, string $to): array
    {
        $summary = $this->cashFlow->summary($entityId, $from, $to);

        // Uma consulta agregada (forma × tipo × status) alimenta contagens,
        // pendentes e o quadro por forma de pagamento.
        $groups = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereNull('deleted_at')
            ->where('status', '!=', FinancialEntryStatus::Cancelled->value)
            ->toBase()
            ->select(['payment_method', 'type', 'status'])
            ->selectRaw('COUNT(*) AS entries')
            ->selectRaw('COALESCE(SUM(amount), 0) AS total')
            ->groupBy(['payment_method', 'type', 'status'])
            ->get();

        $entriesCount   = 0;
        $pendingCount   = 0;
        $pendingExpense = 0.0;
        $byMethod       = [];

        foreach ($groups as $group) {
            $count     = (int) $group->entries;
            $total     = (float) $group->total;
            $isPending = $group->status === FinancialEntryStatus::Pending->value;
            $isIncome  = $group->type === FinancialEntryType::Income->value;

            $entriesCount += $count;

            if ($isPending) {
                $pendingCount += $count;

                if (! $isIncome) {
                    $pendingExpense += $total;
                }
            }

            // Valor fora do enum (legado) cai junto com "não informada".
            $method = PaymentMethod::tryFrom((string) $group->payment_method)?->value;
            $key    = $method ?? '';

            $byMethod[$key] ??= ['method' => $method, 'income' => 0.0, 'expense' => 0.0, 'count' => 0];
            $byMethod[$key]['count'] += $count;
            $byMethod[$key][$isIncome ? 'income' : 'expense'] += $total;
        }

        $overlapping = $this->overlappingCloses($entityId, $from, $to);

        return $summary + [
            'entries_count'       => $entriesCount,
            'pending_count'       => $pendingCount,
            'pending_income'      => $summary['pending'],
            'pending_expense'     => round($pendingExpense, 2),
            'by_payment_method'   => $this->paymentMethodRows($byMethod),
            'overlaps'            => $overlapping !== [],
            'overlapping_periods' => $overlapping,
        ];
    }

    /**
     * Reabre o período: grava motivo, quem e quando e faz o soft delete na
     * MESMA transação (Auditable registra a atualização e a exclusão). Quem
     * pode reabrir (admin) e a clínica são garantidos por rota/controller; o
     * motivo é exigido aqui também, para qualquer chamador.
     *
     * @throws InvalidArgumentException motivo vazio
     */
    public function reopen(CashClose $cashClose, string $reason, ?string $userId): CashClose
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Cash close reopening requires a reason.');
        }

        return DB::transaction(function () use ($cashClose, $reason, $userId): CashClose {
            // Linha travada: duas reaberturas simultâneas não gravam dois
            // motivos — a segunda espera e não acha mais o fechamento ativo (404).
            $close = CashClose::query()
                ->whereKey($cashClose->getKey())
                ->where('entity_id', $cashClose->entity_id)
                ->lockForUpdate()
                ->firstOrFail();

            $close->update([
                'reopen_reason' => $reason,
                'reopened_by'   => $userId,
                'reopened_at'   => now(),
            ]);

            $close->delete();

            return $close;
        });
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

    /** @return list<array{period_start: string, period_end: string}> */
    private function overlappingCloses(string $entityId, string $from, string $to): array
    {
        return CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->orderBy('period_start')
            ->limit(self::OVERLAP_SAMPLE)
            ->get(['period_start', 'period_end'])
            ->map(fn (CashClose $close) => [
                'period_start' => (string) $close->period_start?->toDateString(),
                'period_end'   => (string) $close->period_end?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Linhas do quadro por forma de pagamento na ordem do enum, "não
     * informada" por último.
     *
     * @param array<string, array{method: ?string, income: float, expense: float, count: int}> $byMethod
     *
     * @return list<array{method: ?string, label: ?string, income: float, expense: float, count: int}>
     */
    private function paymentMethodRows(array $byMethod): array
    {
        $order = array_flip(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases()));

        uksort($byMethod, fn (string $a, string $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return array_values(array_map(fn (array $row) => [
            'method'  => $row['method'],
            'label'   => $row['method'] !== null ? PaymentMethod::from($row['method'])->label() : null,
            'income'  => round($row['income'], 2),
            'expense' => round($row['expense'], 2),
            'count'   => $row['count'],
        ], $byMethod));
    }
}
