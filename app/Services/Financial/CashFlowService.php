<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\{CashEntryNature, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType};
use App\Exceptions\Financial\{CashPeriodClosedException, DuplicateCashEntryException};
use App\Models\{CashClose, FinancialCashEntry, Schedule};
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\{Builder as QueryBuilder, JoinClause};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashFlowService
{
    /**
     * Atributos de posse/vínculo definidos só pelos fluxos de sistema
     * (clínica, recebimento de agendamento/guia/compra). Um update nunca os
     * altera, venha de onde vier o array.
     */
    private const SYSTEM_MANAGED_ATTRIBUTES = ['entity_id', 'billing_claim_id', 'reference_type', 'reference_id'];

    public function __construct(
        private readonly ProcedurePriceService $procedurePrices,
    ) {
    }

    public function create(array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($data): FinancialCashEntry {
            $data['entity_id'] = (string) session('selected_entity_id');
            $data['active']    = $data['active'] ?? true;

            $this->assertDateNotClosed($data['entity_id'], $data['entry_date'] ?? null);

            return FinancialCashEntry::query()->create($data);
        });
    }

    /**
     * Cria um lançamento de caixa (entrada) vinculado a um agendamento,
     * disparado pela chegada do paciente. Impede duplicidade de lançamento
     * ativo para o mesmo agendamento.
     *
     * @throws DuplicateCashEntryException
     */
    public function createForSchedule(Schedule $schedule, array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($schedule, $data): FinancialCashEntry {
            // Trava o agendamento: 2 requests simultâneos (chegada registrada 2x)
            // não podem passar ambos no exists() abaixo antes de qualquer um
            // commitar o lançamento.
            $schedule = Schedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            // financialEntries() só conta lançamento da MESMA clínica do
            // agendamento: linha forjada de outra clínica não bloqueia aqui.
            $exists = $schedule->financialEntries()
                ->where('status', '!=', FinancialEntryStatus::Cancelled->value)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw new DuplicateCashEntryException(__('schedules.cash_entry_duplicate'));
            }

            $covenantId  = $data['covenant_id'] ?? $schedule->covenant_id;
            $procedureId = $data['procedure_id'] ?? null;

            // Co-participação quando o convênio é cobrável (faturado via guia);
            // caso contrário é recebimento de balcão (particular).
            $nature = $this->procedurePrices->isCharging($procedureId, $covenantId, (string) $schedule->entity_id)
                ? CashEntryNature::Copay->value
                : CashEntryNature::Desk->value;

            $payload = array_merge($data, [
                'type'           => FinancialEntryType::Income->value,
                'status'         => $data['status'] ?? FinancialEntryStatus::Paid->value,
                'nature'         => $nature,
                'reference_type' => CashEntryReferenceType::Schedule->value,
                'reference_id'   => $schedule->id,
                'patient_id'     => $data['patient_id'] ?? $schedule->patient_id,
                'doctor_id'      => $data['doctor_id'] ?? $schedule->doctor_id,
                'covenant_id'    => $covenantId,
            ]);

            return $this->create($payload);
        });
    }

    public function update(FinancialCashEntry $entry, array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($entry, $data): FinancialCashEntry {
            $this->assertNotLinkedToClaim($entry);

            $entityId = (string) $entry->entity_id;

            // Bloqueia se a data atual OU a nova data caírem em período fechado.
            $this->assertDateNotClosed($entityId, $entry->entry_date?->toDateString());

            if (array_key_exists('entry_date', $data)) {
                $this->assertDateNotClosed($entityId, $data['entry_date']);
            }

            $entry->update(Arr::except($data, self::SYSTEM_MANAGED_ATTRIBUTES));

            return $entry->fresh();
        });
    }

    // BUGFIX (revisao de seguranca): destroy() apagava o lançamento direto no controller,
    // sem checar se a data pertence a um período de caixa já fechado (o create/update
    // checam via assertDateNotClosed), permitindo corromper o snapshot do CashClose.
    public function delete(FinancialCashEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $this->assertNotLinkedToClaim($entry);

            $this->assertDateNotClosed((string) $entry->entity_id, $entry->entry_date?->toDateString());

            $entry->delete();
        });
    }

    /**
     * O recebimento de guia (BillingService::markClaimPaid) cria a entrada com
     * billing_claim_id. Antes a trava existia só na UI (botão desabilitado):
     * um PATCH/DELETE direto alterava ou apagava a receita e a guia ficava
     * "paga" sem entrada no caixa (conciliação, BI e relatórios divergindo).
     *
     * @throws ValidationException 422 com mensagem traduzida
     */
    private function assertNotLinkedToClaim(FinancialCashEntry $entry): void
    {
        if ($entry->billing_claim_id !== null) {
            throw ValidationException::withMessages([
                'billing_claim_id' => __('financial_cash_flow.locked_by_claim'),
            ]);
        }
    }

    /**
     * Garante que a data não pertence a um período de caixa fechado — sob o
     * lock de escrita do caixa da clínica (CashPeriodLock, mantido até o
     * COMMIT): um fechamento concorrente espera este lançamento terminar, ou
     * este lançamento espera o fechamento e então o enxerga aqui.
     *
     * @throws CashPeriodClosedException
     */
    private function assertDateNotClosed(string $entityId, mixed $date): void
    {
        if (empty($date)) {
            return;
        }

        CashPeriodLock::forWriting($entityId);

        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

        $closed = CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();

        if ($closed) {
            throw new CashPeriodClosedException(__('financial.cash_period_closed'));
        }
    }

    /**
     * Lançamentos da clínica no período com os filtros da tela de Fluxo de
     * Caixa — a MESMA consulta alimenta a listagem e overview(), então os
     * KPIs sempre batem com a tabela.
     *
     * Filtros (já normalizados pelo chamador): type, status, category_id e
     * search (descrição ou código FLC; curingas %, _ e \ tratados como texto).
     *
     * @param array{type?: ?string, status?: ?string, category_id?: ?string, search?: ?string} $filters
     */
    public function entriesQuery(string $entityId, string $from, string $to, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return FinancialCashEntry::query()
            ->where('financial_cash_entries.entity_id', $entityId)
            ->whereBetween('financial_cash_entries.entry_date', [$from, $to])
            ->whereNull('financial_cash_entries.deleted_at')
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('financial_cash_entries.type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('financial_cash_entries.status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $q, string $id) => $q->where('financial_cash_entries.category_id', $id))
            ->when($search !== '', fn (Builder $q) => $this->applySearch($q, $search));
    }

    /**
     * Indicadores da tela de Fluxo de Caixa com os MESMOS filtros da tabela;
     * cancelados sempre fora. Não substitui summary() (snapshot do fechamento,
     * PDF, relatórios e BI continuam nele).
     *
     *  - received / receivable: receitas pagas / pendentes
     *  - paid / payable: despesas pagas / pendentes
     *  - realized_balance: recebido − pago
     *  - projected_balance: realizado + a receber − a pagar
     *  - income_total / expense_total / entries_count: rodapé da tabela
     *
     * @param array{type?: ?string, status?: ?string, category_id?: ?string, search?: ?string} $filters
     *
     * @return array<string, float|int>
     */
    public function overview(string $entityId, string $from, string $to, array $filters = []): array
    {
        $income  = FinancialEntryType::Income->value;
        $expense = FinancialEntryType::Expense->value;
        $paid    = FinancialEntryStatus::Paid->value;
        $pending = FinancialEntryStatus::Pending->value;
        $sum     = 'COALESCE(SUM(CASE WHEN financial_cash_entries.type = ? AND financial_cash_entries.status = ? THEN financial_cash_entries.amount ELSE 0 END), 0)';

        $row = $this->entriesQuery($entityId, $from, $to, $filters)
            ->where('financial_cash_entries.status', '!=', FinancialEntryStatus::Cancelled->value)
            ->toBase()
            ->selectRaw('COUNT(*) AS entries_count')
            ->selectRaw("{$sum} AS received", [$income, $paid])
            ->selectRaw("{$sum} AS receivable", [$income, $pending])
            ->selectRaw("{$sum} AS paid", [$expense, $paid])
            ->selectRaw("{$sum} AS payable", [$expense, $pending])
            ->first();

        $received   = round((float) ($row->received ?? 0), 2);
        $receivable = round((float) ($row->receivable ?? 0), 2);
        $paidOut    = round((float) ($row->paid ?? 0), 2);
        $payable    = round((float) ($row->payable ?? 0), 2);
        $realized   = round($received - $paidOut, 2);

        return [
            'received'          => $received,
            'receivable'        => $receivable,
            'paid'              => $paidOut,
            'payable'           => $payable,
            'realized_balance'  => $realized,
            'projected_balance' => round($realized + $receivable - $payable, 2),
            'income_total'      => round($received + $receivable, 2),
            'expense_total'     => round($paidOut + $payable, 2),
            'entries_count'     => (int) ($row->entries_count ?? 0),
        ];
    }

    /** Texto digitado → literal num LIKE/ILIKE: \, % e _ deixam de ser curingas. */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Busca por descrição (sem diferenciar acento/caixa, como o resto do
     * painel — whereLikeUnaccent) ou pelo código FLC. O termo vai sempre como
     * parâmetro e já escapado.
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $pattern = '%' . self::escapeLike($term) . '%';

        $driver = $query->getModel()->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query->where(fn (Builder $q) => $q
                ->whereRaw('unaccent(financial_cash_entries.description) ILIKE unaccent(?)', [$pattern])
                ->orWhereRaw('financial_cash_entries.code ILIKE ?', [$pattern]));
        }

        // SQLite/SQL Server: a barra não é escape padrão do LIKE ("50%" e "a_b"
        // não achariam nada) — ESCAPE explícito. MySQL/MariaDB: a barra já é o
        // padrão (lá, ESCAPE '\' seria lido como aspa escapada).
        $escape = in_array($driver, ['mysql', 'mariadb'], true) ? '' : " ESCAPE '\\'";

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("LOWER(financial_cash_entries.description) LIKE LOWER(?){$escape}", [$pattern])
            ->orWhereRaw("LOWER(financial_cash_entries.code) LIKE LOWER(?){$escape}", [$pattern]));
    }

    public function summary(string $entityId, string $from, string $to): array
    {
        $base = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled');

        $income  = round((float) (clone $base)->where('type', 'income')->sum('amount'), 2);
        $expense = round((float) (clone $base)->where('type', 'expense')->sum('amount'), 2);
        $pending = round((float) (clone $base)->where('type', 'income')->where('status', 'pending')->sum('amount'), 2);

        return [
            'income'  => $income,
            'expense' => $expense,
            'balance' => round($income - $expense, 2),
            'pending' => $pending,
        ];
    }

    /**
     * "Por categoria" do relatório de fluxo de caixa, agregado no banco sobre
     * o período INTEIRO (a lista paginada não interfere): pagos + pendentes,
     * sem cancelados — a base do summary(). Agrupado por category_id + tipo
     * (nunca pelo nome), com a participação (%) no total do tipo. Categoria
     * excluída (soft delete) mantém a própria linha, sem nome.
     *
     * @return list<array{key: string, category_id: ?string, category: string, type: string, total: float, share: float}>
     */
    public function reportByCategory(string $entityId, string $from, string $to): array
    {
        $rows = $this->reportBaseQuery($entityId, $from, $to)
            ->leftJoin('financial_categories', function (JoinClause $join): void {
                $join->on('financial_categories.id', '=', 'financial_cash_entries.category_id')
                    ->whereNull('financial_categories.deleted_at');
            })
            ->groupBy('financial_cash_entries.category_id', 'financial_cash_entries.type', 'financial_categories.name')
            ->select([
                'financial_cash_entries.category_id AS category_id',
                'financial_cash_entries.type AS type',
                'financial_categories.name AS category_name',
            ])
            ->selectRaw('COALESCE(SUM(financial_cash_entries.amount), 0) AS total')
            ->get();

        $typeTotals = [];

        foreach ($rows as $row) {
            $typeTotals[(string) $row->type] = ($typeTotals[(string) $row->type] ?? 0.0) + (float) $row->total;
        }

        return $rows
            ->map(function (object $row) use ($typeTotals): array {
                $type      = (string) $row->type;
                $total     = round((float) $row->total, 2);
                $typeTotal = round((float) ($typeTotals[$type] ?? 0), 2);

                return [
                    'key'         => ($row->category_id ?? 'none') . ':' . $type,
                    'category_id' => $row->category_id !== null ? (string) $row->category_id : null,
                    'category'    => $row->category_name ?? __('financial_reports.no_category'),
                    'type'        => $type,
                    'total'       => $total,
                    'share'       => $typeTotal > 0 ? round(($total / $typeTotal) * 100, 1) : 0.0,
                ];
            })
            ->sortBy([['total', 'desc'], ['category', 'asc'], ['key', 'asc']])
            ->values()
            ->all();
    }

    /**
     * "Por dia" do relatório (ordem cronológica), agregado no banco sobre o
     * período inteiro: receitas, despesas, saldo do dia e saldo acumulado
     * desde o início do período. Mesma base do summary() (pagos + pendentes,
     * sem cancelados): o último acumulado = summary.balance.
     *
     * @return list<array{day: string, income: float, expense: float, balance: float, cumulative: float}>
     */
    public function reportByDay(string $entityId, string $from, string $to): array
    {
        $sum = 'COALESCE(SUM(CASE WHEN financial_cash_entries.type = ? THEN financial_cash_entries.amount ELSE 0 END), 0)';

        $rows = $this->reportBaseQuery($entityId, $from, $to)
            ->groupBy('financial_cash_entries.entry_date')
            ->orderBy('financial_cash_entries.entry_date')
            ->select('financial_cash_entries.entry_date AS day')
            ->selectRaw("{$sum} AS income", [FinancialEntryType::Income->value])
            ->selectRaw("{$sum} AS expense", [FinancialEntryType::Expense->value])
            ->get();

        $cumulative = 0.0;
        $days       = [];

        foreach ($rows as $row) {
            $income     = round((float) $row->income, 2);
            $expense    = round((float) $row->expense, 2);
            $balance    = round($income - $expense, 2);
            $cumulative = round($cumulative + $balance, 2);

            $days[] = [
                'day'        => substr((string) $row->day, 0, 10),
                'income'     => $income,
                'expense'    => $expense,
                'balance'    => $balance,
                'cumulative' => $cumulative,
            ];
        }

        return $days;
    }

    /** Base dos agregados do relatório: período inteiro da clínica, sem cancelados. */
    private function reportBaseQuery(string $entityId, string $from, string $to): QueryBuilder
    {
        return $this->entriesQuery($entityId, $from, $to)
            ->where('financial_cash_entries.status', '!=', FinancialEntryStatus::Cancelled->value)
            ->toBase();
    }
}
