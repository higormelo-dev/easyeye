<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal, TissOperator, TissStatusHistory};
use App\Models\BillingClaim;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\{Builder as QueryBuilder, JoinClause};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leitura da conciliação de glosas como fila de trabalho (tela
 * Financial/Tiss/GlosasIndex): filtros, lista paginada pela ordem da próxima
 * ação, KPIs/contagem das abas/resumo por operadora em SQL sobre a mesma base
 * escopada e o detalhe com a linha do tempo. Tudo pela clínica (entity_id).
 *
 * Extraído do TissGlosasController (que ficou com as ações de recurso) — mesmo
 * comportamento, coberto por GlosaQueueTest/GlosaScaleTest/TissGlosasControllerTest.
 */
class GlosaQueueService
{
    /** Janela do card "Vencendo em N dias". */
    private const DUE_SOON_DAYS = 5;

    public const TAB_PENDING = 'pending';

    private const TAB_RESOLVED = 'resolved';

    private const TAB_ALL = 'all';

    public const TABS = [self::TAB_PENDING, self::TAB_RESOLVED, self::TAB_ALL];

    private const PENDING_STATUSES = [TissGlosaStatus::Open, TissGlosaStatus::Appealed];

    private const RESOLVED_STATUSES = [
        TissGlosaStatus::PartialReversed,
        TissGlosaStatus::Reversed,
        TissGlosaStatus::Maintained,
        TissGlosaStatus::Cancelled,
    ];

    /** Filtro "Recuperadas" (KPI Recuperado): revertidas total ou parcialmente. */
    private const STATUS_RECOVERED = 'recovered';

    private const RECOVERED_STATUSES = [TissGlosaStatus::PartialReversed, TissGlosaStatus::Reversed];

    /** Filtros de prazo dos KPIs "Vencidas" / "Vencendo em N dias" (só glosas Abertas). */
    public const DUE_OVERDUE = 'overdue';

    public const DUE_SOON = 'soon';

    /** Glosas por página da lista (mesmo tamanho das outras listas do financeiro). */
    public const PER_PAGE = 30;

    public const SEARCH_MAX = 100;

    private const TIMELINE_LIMIT = 100;

    /** Soma dos valores aceitos nos recursos Aceitos, limitada ao valor glosado. */
    private function recoveredAmount(TissGlosa $glosa): float
    {
        $accepted = (float) $glosa->appeals
            ->where('status', TissAppealStatus::Accepted)
            ->sum('accepted_amount');

        return min($accepted, (float) $glosa->amount);
    }

    /**
     * Linha da lista (e base do detalhe). `guide_number` é o nº da guia no
     * prestador (antes lia um atributo inexistente e saía sempre vazio);
     * `claim_code` é o código GUI da guia de faturamento, quando houver.
     *
     * @param array<string, string> $claimCodes guide_id => código GUI
     *
     * @return array<string, mixed>
     */
    private function glosaRow(TissGlosa $g, array $claimCodes = [], bool $detailed = false): array
    {
        return [
            'id'               => (string) $g->id,
            'identified_at'    => $g->identified_at?->toDateString(),
            'deadline'         => $g->deadline?->toDateString(),
            'resolved_at'      => $g->resolved_at?->toDateString(),
            'operator_name'    => $g->operator?->trade_name ?? $g->operator?->name,
            'guide_number'     => $g->guide?->guide_number_provider ?: $g->guide?->guide_number_operator,
            'claim_code'       => $claimCodes[(string) $g->guide_id] ?? null,
            'reason_code'      => $g->glosa_code,
            'reason_text'      => $g->glosa_description,
            'amount'           => (float) $g->amount,
            'recovered_amount' => $this->recoveredAmount($g),
            'status'           => $g->status->value,
            'status_label'     => $g->status->label(),
            'status_color'     => $g->status->color(),
            'is_actionable'    => $g->status->isActionable(),
            'appeals_count'    => $g->appeals->count(),
            'appeal_url'       => route('panel.financial.tiss.glosas.appeal', $g->id),
            'appeals'          => $g->appeals->map(fn (TissGlosaAppeal $a) => [
                'id'               => (string) $a->id,
                'appeal_number'    => $a->appeal_number,
                'status'           => $a->status->value,
                'status_label'     => $a->status->label(),
                'status_color'     => $a->status->color(),
                'can_be_submitted' => $a->status->canBeSubmitted(),
                'can_be_resolved'  => $a->status->canBeResolved(),
                'requested_amount' => (float) $a->requested_amount,
                'accepted_amount'  => (float) $a->accepted_amount,
                'submitted_at'     => $a->submitted_at?->toIso8601String(),
                'deadline'         => $a->deadline?->toDateString(),
                'submit_url'       => route('panel.financial.tiss.glosas.appeals.submit', $a->id),
                'resolve_url'      => route('panel.financial.tiss.glosas.appeals.resolve', $a->id),
                ...($detailed ? [
                    'created_at'   => $a->created_at?->toIso8601String(),
                    'resolved_at'  => $a->resolved_at?->toIso8601String(),
                    'reason'       => $a->reason,
                    'result_notes' => $a->result_notes,
                ] : []),
            ])->values(),
        ];
    }

    /**
     * Operadoras com glosa na clínica (opções do filtro e validação do id).
     *
     * @return Collection<int, array{id: string, name: string}>
     */
    public function operatorOptions(string $entityId): Collection
    {
        return TissOperator::query()
            ->whereIn('id', TissGlosa::query()->forEntity($entityId)->whereNotNull('operator_id')->select('operator_id'))
            ->get(['id', 'name', 'trade_name'])
            ->map(fn (TissOperator $o) => ['id' => (string) $o->id, 'name' => (string) ($o->trade_name ?: $o->name)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** Status aceito na aba (ou "recovered"); qualquer outro valor = sem filtro. */
    public function statusFilter(string $value, string $tab): ?string
    {
        return in_array($value, $this->tabStatusValues($tab), true) ? $value : null;
    }

    /** @return list<string> */
    private function tabStatusValues(string $tab): array
    {
        $values = fn (array $statuses): array => array_map(fn (TissGlosaStatus $s) => $s->value, $statuses);

        return match ($tab) {
            self::TAB_PENDING  => $values(self::PENDING_STATUSES),
            self::TAB_RESOLVED => [...$values(self::RESOLVED_STATUSES), self::STATUS_RECOVERED],
            default            => [...$values(TissGlosaStatus::cases()), self::STATUS_RECOVERED],
        };
    }

    /** @return array<string, list<array{value: string, label: string}>> opções do filtro de status por aba */
    public function statusOptions(): array
    {
        $label = fn (string $value): string => $value === self::STATUS_RECOVERED
            ? __('financial_glosas.status_recovered_option')
            : TissGlosaStatus::from($value)->label();

        return collect(self::TABS)
            ->mapWithKeys(fn (string $tab) => [
                $tab => array_map(fn (string $value) => ['value' => $value, 'label' => $label($value)], $this->tabStatusValues($tab)),
            ])
            ->all();
    }

    /**
     * Base de toda consulta da tela: clínica + operadora + busca.
     *
     * @return Builder<TissGlosa>
     */
    private function contextQuery(string $entityId, ?string $operatorId, string $search): Builder
    {
        return TissGlosa::query()
            ->forEntity($entityId)
            ->when($operatorId !== null, fn (Builder $q) => $q->where('tiss_glosas.operator_id', $operatorId))
            ->when($search !== '', fn (Builder $q) => $this->applySearch($q, $entityId, $search));
    }

    /**
     * Busca por nº da guia (prestador/operadora), código GUI da guia de
     * faturamento, código ou texto do motivo e nº do recurso (REC). O termo é
     * literal: %, _ e \ são escapados (senão "50%" casaria qualquer coisa);
     * cada subconsulta também é escopada pela clínica.
     *
     * @param Builder<TissGlosa> $query
     *
     * @return Builder<TissGlosa>
     */
    private function applySearch(Builder $query, string $entityId, string $search): Builder
    {
        $pattern = '%' . addcslashes($search, '\\%_') . '%';

        return $query->where(function (Builder $w) use ($entityId, $pattern): void {
            $w->whereRaw('tiss_glosas.glosa_code ILIKE ?', [$pattern])
                ->orWhereRaw('unaccent(tiss_glosas.glosa_description) ILIKE unaccent(?)', [$pattern])
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                    ->from('tiss_guides')
                    ->whereColumn('tiss_guides.id', 'tiss_glosas.guide_id')
                    ->where('tiss_guides.entity_id', $entityId)
                    ->whereNull('tiss_guides.deleted_at')
                    ->where(fn (QueryBuilder $g) => $g
                        ->whereRaw('tiss_guides.guide_number_provider ILIKE ?', [$pattern])
                        ->orWhereRaw('tiss_guides.guide_number_operator ILIKE ?', [$pattern])))
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                    ->from('billing_claims')
                    ->whereColumn('billing_claims.tiss_guide_id', 'tiss_glosas.guide_id')
                    ->where('billing_claims.entity_id', $entityId)
                    ->whereNull('billing_claims.deleted_at')
                    ->whereRaw('billing_claims.code ILIKE ?', [$pattern]))
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                    ->from('tiss_glosa_appeals')
                    ->whereColumn('tiss_glosa_appeals.glosa_id', 'tiss_glosas.id')
                    ->where('tiss_glosa_appeals.entity_id', $entityId)
                    ->whereNull('tiss_glosa_appeals.deleted_at')
                    ->whereRaw('tiss_glosa_appeals.appeal_number ILIKE ?', [$pattern]));
        });
    }

    /**
     * Pendentes: Abertas/Recorridas de qualquer data. Resolvidas/Todas: pelo
     * período (data de identificação).
     *
     * @param Builder<TissGlosa> $query
     *
     * @return Builder<TissGlosa>
     */
    private function applyTab(Builder $query, string $tab, string $from, string $to): Builder
    {
        if ($tab === self::TAB_PENDING) {
            return $query->whereIn('tiss_glosas.status', array_map(fn (TissGlosaStatus $s) => $s->value, self::PENDING_STATUSES));
        }

        $query->whereBetween('tiss_glosas.identified_at', [$from, $to]);

        return $tab === self::TAB_RESOLVED
            ? $query->whereIn('tiss_glosas.status', array_map(fn (TissGlosaStatus $s) => $s->value, self::RESOLVED_STATUSES))
            : $query;
    }

    /**
     * Lista filtrada da aba atual (sem ordenação).
     *
     * @param array{tab: string, from: string, to: string, status: ?string, operator_id: ?string, due: ?string, search: string} $filters
     *
     * @return Builder<TissGlosa>
     */
    private function listQuery(string $entityId, array $filters): Builder
    {
        $query = $this->applyTab(
            $this->contextQuery($entityId, $filters['operator_id'], $filters['search']),
            $filters['tab'],
            $filters['from'],
            $filters['to'],
        );

        if ($filters['status'] === self::STATUS_RECOVERED) {
            $query->whereIn('tiss_glosas.status', array_map(fn (TissGlosaStatus $s) => $s->value, self::RECOVERED_STATUSES));
        } elseif ($filters['status'] !== null) {
            $query->where('tiss_glosas.status', $filters['status']);
        }

        if ($filters['due'] !== null) {
            $today = now()->toDateString();

            $query->where('tiss_glosas.status', TissGlosaStatus::Open->value)->whereNotNull('tiss_glosas.deadline');

            $filters['due'] === self::DUE_OVERDUE
                ? $query->where('tiss_glosas.deadline', '<', $today)
                : $query->whereBetween('tiss_glosas.deadline', [$today, now()->addDays(self::DUE_SOON_DAYS)->toDateString()]);
        }

        return $query;
    }

    /**
     * Página da lista (PER_PAGE), já na ordem da aba. Os links das páginas
     * levam os filtros da URL (withQueryString), menos o `detail` — link direto
     * do painel lateral: trocar de página não reabre o detalhe.
     *
     * @param array{tab: string, from: string, to: string, status: ?string, operator_id: ?string, due: ?string, search: string} $filters
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function glosaPage(string $entityId, array $filters): LengthAwarePaginator
    {
        $query = $this->listQuery($entityId, $filters)
            ->with(['operator', 'guide', 'appeals' => fn ($q) => $q->orderBy('created_at')]);

        if ($filters['tab'] === self::TAB_PENDING) {
            $this->orderByQueue($query);
        } else {
            $query->orderByDesc('tiss_glosas.identified_at')->orderByDesc('tiss_glosas.id');
        }

        // `page` inválido (texto, negativo) já cai na 1 no Laravel.
        /** @var LengthAwarePaginator<int, TissGlosa> $page */
        $page = (clone $query)->paginate(self::PER_PAGE);

        // Página além da última (ex.: a última glosa da página foi resolvida e o
        // back() voltou para ?page=N): mostra a última em vez de lista vazia.
        if ($page->isEmpty() && $page->currentPage() > $page->lastPage()) {
            $page = (clone $query)->paginate(self::PER_PAGE, ['*'], 'page', $page->lastPage());
        }

        $claimCodes = $this->claimCodes($entityId, $page->getCollection());

        return $page
            ->through(fn (TissGlosa $g) => $this->glosaRow($g, $claimCodes))
            ->withQueryString()
            // null some da query string dos links (http_build_query ignora null).
            ->appends('detail', null);
    }

    /**
     * Fila pelo prazo da próxima ação, vencidas primeiro e sem prazo por
     * último: Aberta → prazo para recorrer; Recorrida com recurso enviado/em
     * análise → prazo de resposta da operadora (senão, o prazo da glosa).
     *
     * @param Builder<TissGlosa> $query
     */
    private function orderByQueue(Builder $query): void
    {
        $appealDeadline = DB::table('tiss_glosa_appeals')
            ->select('tiss_glosa_appeals.deadline')
            ->whereColumn('tiss_glosa_appeals.glosa_id', 'tiss_glosas.id')
            ->whereColumn('tiss_glosa_appeals.entity_id', 'tiss_glosas.entity_id')
            ->whereIn('tiss_glosa_appeals.status', [TissAppealStatus::Submitted->value, TissAppealStatus::InAnalysis->value])
            ->whereNull('tiss_glosa_appeals.deleted_at')
            ->orderByDesc('tiss_glosa_appeals.created_at')
            ->limit(1);

        $dueDate  = sprintf('CASE WHEN tiss_glosas.status = ? THEN COALESCE((%s), tiss_glosas.deadline) ELSE tiss_glosas.deadline END', $appealDeadline->toSql());
        $bindings = [TissGlosaStatus::Appealed->value, ...$appealDeadline->getBindings()];

        $query->orderByRaw("CASE WHEN ({$dueDate}) IS NULL THEN 1 ELSE 0 END", $bindings)
            ->orderByRaw($dueDate, $bindings)
            ->orderBy('tiss_glosas.identified_at')
            ->orderBy('tiss_glosas.id');
    }

    /**
     * Código GUI da guia de faturamento de cada guia TISS da lista (1 consulta).
     *
     * @param Collection<int, TissGlosa> $glosas
     *
     * @return array<string, string> guide_id => código
     */
    private function claimCodes(string $entityId, Collection $glosas): array
    {
        $guideIds = $glosas->pluck('guide_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();

        if ($guideIds === []) {
            return [];
        }

        return BillingClaim::query()
            ->where('entity_id', $entityId)
            ->whereIn('tiss_guide_id', $guideIds)
            ->orderBy('created_at')
            ->pluck('code', 'tiss_guide_id')
            ->mapWithKeys(fn ($code, $guideId) => [(string) $guideId => (string) $code])
            ->all();
    }

    /**
     * KPIs numa única consulta agregada sobre a base da tela (clínica +
     * operadora filtrada; a busca não entra, como antes): fila (em aberto,
     * recorridas, vencidas, vencendo em N dias) de qualquer data e totais do
     * período (glosado, quantidade, recuperado). Só entram as linhas que algum
     * KPI usa: as da fila ou as do período.
     *
     * Recuperado = valor ACEITO nos recursos Aceitos, limitado ao valor glosado
     * (LEAST) — não o amount inteiro da glosa parcialmente revertida: R$ 1.000
     * revertida em R$ 200 conta R$ 200.
     *
     * @return array<string, float|int>
     */
    public function summary(string $entityId, ?string $operatorId, string $from, string $to): array
    {
        $open      = TissGlosaStatus::Open->value;
        $appealed  = TissGlosaStatus::Appealed->value;
        $today     = now()->toDateString();
        $soonUntil = now()->addDays(self::DUE_SOON_DAYS)->toDateString();
        $inPeriod  = 'tiss_glosas.identified_at BETWEEN ? AND ?';

        $row = $this->contextQuery($entityId, $operatorId, '')
            ->toBase()
            ->leftJoinSub($this->acceptedAppealsQuery($entityId), 'accepted_appeals', 'accepted_appeals.glosa_id', '=', 'tiss_glosas.id')
            ->where(fn (QueryBuilder $q) => $q
                ->whereIn('tiss_glosas.status', [$open, $appealed])
                ->orWhereBetween('tiss_glosas.identified_at', [$from, $to]))
            ->selectRaw(implode(', ', [
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? THEN tiss_glosas.amount ELSE 0 END), 0) AS open_amount',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? THEN 1 ELSE 0 END), 0) AS open_count',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? THEN tiss_glosas.amount ELSE 0 END), 0) AS appealed_amount',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? THEN 1 ELSE 0 END), 0) AS appealed_count',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? AND tiss_glosas.deadline < ? THEN tiss_glosas.amount ELSE 0 END), 0) AS overdue_amount',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? AND tiss_glosas.deadline < ? THEN 1 ELSE 0 END), 0) AS overdue_count',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? AND tiss_glosas.deadline BETWEEN ? AND ? THEN tiss_glosas.amount ELSE 0 END), 0) AS due_soon_amount',
                'COALESCE(SUM(CASE WHEN tiss_glosas.status = ? AND tiss_glosas.deadline BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS due_soon_count',
                "COALESCE(SUM(CASE WHEN {$inPeriod} THEN tiss_glosas.amount ELSE 0 END), 0) AS period_amount",
                "COALESCE(SUM(CASE WHEN {$inPeriod} THEN 1 ELSE 0 END), 0) AS period_count",
                "COALESCE(SUM(CASE WHEN {$inPeriod} THEN LEAST(COALESCE(accepted_appeals.accepted_total, 0), tiss_glosas.amount) ELSE 0 END), 0) AS recovered_amount",
            ]), [
                $open, $open, $appealed, $appealed,
                $open, $today, $open, $today,
                $open, $today, $soonUntil, $open, $today, $soonUntil,
                $from, $to, $from, $to, $from, $to,
            ])
            ->first();

        return [
            'open'           => round((float) ($row->open_amount ?? 0), 2),
            'open_count'     => (int) ($row->open_count ?? 0),
            'appealed'       => round((float) ($row->appealed_amount ?? 0), 2),
            'appealed_count' => (int) ($row->appealed_count ?? 0),
            'overdue'        => round((float) ($row->overdue_amount ?? 0), 2),
            'overdue_count'  => (int) ($row->overdue_count ?? 0),
            'due_soon'       => round((float) ($row->due_soon_amount ?? 0), 2),
            'due_soon_count' => (int) ($row->due_soon_count ?? 0),
            'due_soon_days'  => self::DUE_SOON_DAYS,
            // Período (data de identificação).
            'total'                => round((float) ($row->period_amount ?? 0), 2),
            'count'                => (int) ($row->period_count ?? 0),
            'recovered'            => round((float) ($row->recovered_amount ?? 0), 2),
            'appeal_response_days' => (int) config('tiss.appeal_response_deadline_days', 60),
        ];
    }

    /**
     * Soma do valor aceito nos recursos Aceitos (não excluídos) de cada glosa
     * da clínica — base do "Recuperado".
     */
    private function acceptedAppealsQuery(string $entityId): QueryBuilder
    {
        return DB::table('tiss_glosa_appeals')
            ->select('tiss_glosa_appeals.glosa_id')
            ->selectRaw('SUM(tiss_glosa_appeals.accepted_amount) AS accepted_total')
            ->where('tiss_glosa_appeals.entity_id', $entityId)
            ->where('tiss_glosa_appeals.status', TissAppealStatus::Accepted->value)
            ->whereNull('tiss_glosa_appeals.deleted_at')
            ->groupBy('tiss_glosa_appeals.glosa_id');
    }

    /**
     * Quantidade por aba com os mesmos filtros de contexto (operadora e busca),
     * numa consulta só: cada aba é um SUM(CASE) com a MESMA regra do applyTab
     * (Pendentes: Abertas/Recorridas de qualquer data; Resolvidas e Todas: pelo
     * período).
     *
     * @param array{tab: string, from: string, to: string, status: ?string, operator_id: ?string, due: ?string, search: string} $filters
     *
     * @return array<string, int>
     */
    public function tabCounts(string $entityId, array $filters): array
    {
        $pending  = $this->statusValues(self::PENDING_STATUSES);
        $resolved = $this->statusValues(self::RESOLVED_STATUSES);
        $inPeriod = 'tiss_glosas.identified_at BETWEEN ? AND ?';

        $row = $this->contextQuery($entityId, $filters['operator_id'], $filters['search'])
            ->toBase()
            ->where(fn (QueryBuilder $q) => $q
                ->whereIn('tiss_glosas.status', $pending)
                ->orWhereBetween('tiss_glosas.identified_at', [$filters['from'], $filters['to']]))
            ->selectRaw(implode(', ', [
                sprintf('COALESCE(SUM(CASE WHEN tiss_glosas.status IN (%s) THEN 1 ELSE 0 END), 0) AS pending_count', $this->placeholders($pending)),
                sprintf("COALESCE(SUM(CASE WHEN {$inPeriod} AND tiss_glosas.status IN (%s) THEN 1 ELSE 0 END), 0) AS resolved_count", $this->placeholders($resolved)),
                "COALESCE(SUM(CASE WHEN {$inPeriod} THEN 1 ELSE 0 END), 0) AS all_count",
            ]), [
                ...$pending,
                $filters['from'], $filters['to'], ...$resolved,
                $filters['from'], $filters['to'],
            ])
            ->first();

        return [
            self::TAB_PENDING  => (int) ($row->pending_count ?? 0),
            self::TAB_RESOLVED => (int) ($row->resolved_count ?? 0),
            self::TAB_ALL      => (int) ($row->all_count ?? 0),
        ];
    }

    /**
     * Resumo por convênio/operadora das glosas do período, agrupado no banco.
     *
     * Agrupa pelo id da operadora (homônimas — várias "UNIMED ..." — não se
     * fundem numa linha), como o filtro da tela, o relatório e o BI. Operadora
     * excluída não empresta o nome (vira "Sem convênio", como antes).
     *
     * @return list<array{id: string, name: string, total: float, open: float, count: int}>
     */
    public function byOperator(string $entityId, ?string $operatorId, string $from, string $to): array
    {
        $noCovenant = __('financial_glosas.no_covenant');

        return $this->contextQuery($entityId, $operatorId, '')
            ->whereBetween('tiss_glosas.identified_at', [$from, $to])
            ->toBase()
            ->leftJoin('tiss_operators', fn (JoinClause $join) => $join
                ->on('tiss_operators.id', '=', 'tiss_glosas.operator_id')
                ->whereNull('tiss_operators.deleted_at'))
            ->groupBy('tiss_glosas.operator_id', 'tiss_operators.trade_name', 'tiss_operators.name')
            ->select(['tiss_glosas.operator_id', 'tiss_operators.trade_name', 'tiss_operators.name'])
            ->selectRaw('SUM(tiss_glosas.amount) AS total_amount')
            ->selectRaw('SUM(CASE WHEN tiss_glosas.status = ? THEN tiss_glosas.amount ELSE 0 END) AS open_amount', [TissGlosaStatus::Open->value])
            ->selectRaw('COUNT(*) AS glosa_count')
            ->orderByDesc('open_amount')
            ->orderByDesc('total_amount')
            ->orderBy('tiss_glosas.operator_id')
            ->get()
            ->map(fn (object $row) => [
                'id'    => (string) ($row->operator_id ?? ''),
                'name'  => $row->trade_name ?? $row->name ?? $noCovenant,
                'total' => round((float) $row->total_amount, 2),
                'open'  => round((float) $row->open_amount, 2),
                'count' => (int) $row->glosa_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param list<TissGlosaStatus> $statuses
     *
     * @return list<string>
     */
    private function statusValues(array $statuses): array
    {
        return array_map(fn (TissGlosaStatus $s) => $s->value, $statuses);
    }

    /** "?, ?, ?" para um IN com bindings. */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Detalhe do painel lateral: guia (paciente só pelo nome), motivo
     * completo, recursos e linha do tempo. Glosa inexistente ou de outra
     * clínica → `missing` (sem dado algum; não aborta, para a recarga parcial
     * não virar redirect com erro).
     *
     * @return array<string, mixed>
     */
    public function glosaDetail(string $entityId, string $glosaId): array
    {
        $glosa = TissGlosa::query()
            ->forEntity($entityId)
            ->with(['operator', 'guide.patient.person', 'appeals' => fn ($q) => $q->orderBy('created_at')])
            ->whereKey($glosaId)
            ->first();

        if ($glosa === null) {
            return ['missing' => true];
        }

        $guide = $glosa->guide;
        $claim = $guide === null ? null : BillingClaim::query()
            ->where('entity_id', $entityId)
            ->where('tiss_guide_id', $guide->id)
            ->orderBy('created_at')
            ->first(['id', 'code']);

        return [
            ...$this->glosaRow($glosa, $claim === null ? [] : [(string) $guide->id => (string) $claim->code], detailed: true),
            'resolution_notes' => $glosa->resolution_notes,
            'guide'            => $guide === null ? null : [
                'provider_number' => $guide->guide_number_provider,
                'operator_number' => $guide->guide_number_operator,
                'claim_code'      => $claim?->code,
                'attendance_date' => $guide->attendance_date?->toDateString(),
                'patient_name'    => $guide->patient?->person?->full_name ?? $guide->beneficiary_name,
                'total_amount'    => (float) $guide->total_amount,
            ],
            'timeline' => $this->timeline($entityId, $glosa),
        ];
    }

    /**
     * Histórico TISS da glosa e dos recursos dela (tiss_status_histories),
     * escopado pela clínica. O `reason` é o texto de auditoria gravado.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(string $entityId, TissGlosa $glosa): array
    {
        $appeals = $glosa->appeals->keyBy(fn (TissGlosaAppeal $a) => (string) $a->id);

        return TissStatusHistory::query()
            ->forEntity($entityId)
            ->where(function (Builder $q) use ($glosa, $appeals): void {
                $q->where(fn (Builder $g) => $g->where('context_type', 'glosa')->where('context_id', (string) $glosa->id));

                if ($appeals->isNotEmpty()) {
                    $q->orWhere(fn (Builder $a) => $a->where('context_type', 'glosa_appeal')->whereIn('context_id', $appeals->keys()->all()));
                }
            })
            ->orderBy('changed_at')
            ->orderBy('created_at')
            ->limit(self::TIMELINE_LIMIT)
            ->get()
            ->map(function (TissStatusHistory $h) use ($appeals): array {
                $isAppeal = $h->context_type === 'glosa_appeal';

                return [
                    'id'             => (string) $h->id,
                    'changed_at'     => ($h->changed_at ?? $h->created_at)?->toIso8601String(),
                    'context'        => $isAppeal ? 'appeal' : 'glosa',
                    'appeal_number'  => $isAppeal ? $appeals->get((string) $h->context_id)?->appeal_number : null,
                    'previous_label' => $this->historyStatusLabel($isAppeal, $h->previous_status),
                    'current_status' => $h->current_status,
                    'current_label'  => $this->historyStatusLabel($isAppeal, $h->current_status),
                    'reason'         => $h->reason,
                ];
            })
            ->values()
            ->all();
    }

    private function historyStatusLabel(bool $isAppeal, ?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $enum = $isAppeal ? TissAppealStatus::tryFrom($status) : TissGlosaStatus::tryFrom($status);

        return $enum?->label() ?? $status;
    }
}
