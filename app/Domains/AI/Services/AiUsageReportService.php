<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Support\ProviderErrorSanitizer;
use App\DTOs\AI\AiUsageFiltersData;
use App\Enums\AI\{AiProvider, AiRunStatus};
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\{Carbon, Collection};
use Illuminate\Support\Facades\DB;

/**
 * Manager → Uso de IA: custo e uso de IA de TODAS as clínicas (e da própria
 * plataforma) para a dona do SaaS — por período, ação, clínica, usuário e
 * provedor/modelo, com falhas e a lista das execuções.
 *
 * Fontes: ai_runs (execução: clínica, usuário, ação, situação, créditos) e
 * ai_run_provider_calls (chamada paga ao provedor: custo real em US$,
 * tokens, latência). Nada de conteúdo: prompt/resposta (input_summary,
 * final_output) vêm de prontuário e imagens e NUNCA são lidos aqui (LGPD —
 * minimização); só metadados de uso.
 *
 * Query builder puro (sem os escopos de tenant dos models): a visão é global
 * por definição e o acesso é barrado antes (Gate SaasOwnerFinancial).
 */
class AiUsageReportService
{
    /** Rankings de clínica e usuário mostram os maiores; o CSV tem tudo. */
    public const TOP_LIMIT = 15;

    public function __construct(
        private readonly AiUsdBrlRate $rates,
    ) {
    }

    /** @return array{rate: float, topped_up_at: ?string, is_fallback: bool, usd_per_credit: float} */
    public function rate(): array
    {
        $rate = $this->rates->current();

        return [
            'rate'           => $rate['rate'],
            'topped_up_at'   => $rate['topped_up_at']?->toDateString(),
            'is_fallback'    => $rate['is_fallback'],
            'usd_per_credit' => $this->usdPerCredit(),
        ];
    }

    // ── Indicadores ─────────────────────────────────────────────────────────

    /** @return array{current: array<string, mixed>, previous: array<string, mixed>, delta: array<string, ?float>} */
    public function kpis(AiUsageFiltersData $filters): array
    {
        $rate     = $this->rates->rate();
        $current  = $this->totals($filters, $rate);
        $previous = $this->totals($filters->previousPeriod(), $rate);

        return [
            'current'  => $current,
            'previous' => $previous,
            'delta'    => [
                'runs'         => $this->deltaPct($current['runs'], $previous['runs']),
                'cost_brl'     => $this->deltaPct($current['cost_brl'], $previous['cost_brl']),
                'credits'      => $this->deltaPct($current['credits'], $previous['credits']),
                'revenue_brl'  => $this->deltaPct($current['revenue_brl'], $previous['revenue_brl']),
                'margin_brl'   => $this->deltaPct($current['margin_brl'], $previous['margin_brl']),
                'avg_cost_brl' => $this->deltaPct($current['avg_cost_brl'], $previous['avg_cost_brl']),
                // Taxa de falha compara em pontos percentuais, não em %.
                'failure_rate_pp' => $previous['runs'] > 0 || $current['runs'] > 0
                    ? round($current['failure_rate'] - $previous['failure_rate'], 1)
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function totals(AiUsageFiltersData $filters, float $rate): array
    {
        $runs = $this->runs($filters)
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END), 0) as failed', [AiRunStatus::Failed->value])
            ->selectRaw('COALESCE(SUM(r.consumed_credits), 0) as credits')
            ->selectRaw('COUNT(DISTINCT r.entity_id) as entities')
            ->selectRaw('COUNT(DISTINCT r.requested_by) as users')
            ->first();

        $calls = $this->calls($filters)
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN c.status = ? THEN 1 ELSE 0 END), 0) as failed_calls', ['failed'])
            ->selectRaw('COALESCE(SUM(c.raw_cost_usd), 0) as cost_usd')
            ->selectRaw('COALESCE(SUM(c.input_tokens), 0) as tokens_in')
            ->selectRaw('COALESCE(SUM(c.output_tokens), 0) as tokens_out')
            ->first();

        $totalRuns = (int) $runs->runs;

        return [
            ...$this->money((float) $calls->cost_usd, (int) $runs->credits, $rate),
            'runs'              => $totalRuns,
            'failed'            => (int) $runs->failed,
            'failure_rate'      => $this->pct((int) $runs->failed, $totalRuns),
            'calls'             => (int) $calls->calls,
            'failed_calls'      => (int) $calls->failed_calls,
            'call_failure_rate' => $this->pct((int) $calls->failed_calls, (int) $calls->calls),
            'tokens_in'         => (int) $calls->tokens_in,
            'tokens_out'        => (int) $calls->tokens_out,
            'entities'          => (int) $runs->entities,
            'users'             => (int) $runs->users,
            'avg_cost_brl'      => $totalRuns > 0 ? round((float) $calls->cost_usd * $rate / $totalRuns, 4) : 0.0,
        ];
    }

    // ── Série no tempo ──────────────────────────────────────────────────────

    /** @return array{granularity: string, points: list<array<string, mixed>>} */
    public function series(AiUsageFiltersData $filters): array
    {
        $rate = $this->rates->rate();

        $runs = $this->runs($filters)
            ->selectRaw('DATE(r.created_at) as day')
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END), 0) as failed', [AiRunStatus::Failed->value])
            ->groupByRaw('DATE(r.created_at)')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $costs = $this->calls($filters)
            ->selectRaw('DATE(r.created_at) as day')
            ->selectRaw('COALESCE(SUM(c.raw_cost_usd), 0) as cost_usd')
            ->groupByRaw('DATE(r.created_at)')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        // Dias sem uso entram zerados (o gráfico mostra a lacuna, não esconde).
        $granularity = $filters->granularity();
        $points      = [];

        for ($day = $filters->from->copy()->startOfDay(); $day->lte($filters->to); $day->addDay()) {
            $date = $day->toDateString();
            $key  = $granularity === 'month' ? substr($date, 0, 7) : $date;

            $points[$key] ??= ['key' => $key, 'runs' => 0, 'failed' => 0, 'cost_usd' => 0.0];
            $points[$key]['runs'] += (int) ($runs[$date]->runs ?? 0);
            $points[$key]['failed'] += (int) ($runs[$date]->failed ?? 0);
            $points[$key]['cost_usd'] += (float) ($costs[$date]->cost_usd ?? 0);
        }

        return [
            'granularity' => $granularity,
            'points'      => array_values(array_map(fn (array $point) => [
                ...$point,
                'cost_usd' => round($point['cost_usd'], 6),
                'cost_brl' => round($point['cost_usd'] * $rate, 2),
            ], $points)),
        ];
    }

    // ── Rankings ────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> por ação (workflow), maior custo primeiro */
    public function byWorkflow(AiUsageFiltersData $filters): array
    {
        return $this->grouped($filters, ['r.workflow'])
            ->map(fn (array $row) => [
                ...$row,
                'workflow' => $row['keys'][0],
                'label'    => $this->workflowLabel((string) $row['keys'][0]),
            ])
            ->map(fn (array $row) => $this->withoutKeys($row))
            ->values()
            ->all();
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} por clínica (a plataforma aparece como uso interno) */
    public function byEntity(AiUsageFiltersData $filters): array
    {
        $rows  = $this->grouped($filters, ['r.entity_id']);
        $names = DB::table('entities')
            ->whereIn('id', $rows->take(self::TOP_LIMIT)->map(fn ($row) => $row['keys'][0])->all())
            ->get(['id', 'name', 'is_client'])
            ->keyBy('id');

        return [
            'rows' => $rows->take(self::TOP_LIMIT)
                ->map(fn (array $row) => $this->withoutKeys([
                    ...$row,
                    'entity_id'   => (string) $row['keys'][0],
                    'name'        => $names[$row['keys'][0]]->name ?? '—',
                    'is_internal' => isset($names[$row['keys'][0]]) && ! $names[$row['keys'][0]]->is_client,
                ]))
                ->values()
                ->all(),
            'total' => $rows->count(),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} por usuário em cada clínica */
    public function byUser(AiUsageFiltersData $filters): array
    {
        $rows = $this->grouped($filters, ['r.requested_by', 'r.entity_id']);
        $top  = $rows->take(self::TOP_LIMIT);

        $users    = DB::table('users')->whereIn('id', $top->map(fn ($row) => $row['keys'][0])->unique()->all())->pluck('name', 'id');
        $entities = DB::table('entities')->whereIn('id', $top->map(fn ($row) => $row['keys'][1])->unique()->all())->get(['id', 'name', 'is_client'])->keyBy('id');

        return [
            'rows' => $top
                ->map(fn (array $row) => $this->withoutKeys([
                    ...$row,
                    'user_id'     => (string) $row['keys'][0],
                    'name'        => $users[$row['keys'][0]] ?? '—',
                    'entity_id'   => (string) $row['keys'][1],
                    'entity_name' => $entities[$row['keys'][1]]->name ?? '—',
                    'is_internal' => isset($entities[$row['keys'][1]]) && ! $entities[$row['keys'][1]]->is_client,
                ]))
                ->values()
                ->all(),
            'total' => $rows->count(),
        ];
    }

    /** @return list<array<string, mixed>> por provedor e modelo: chamadas, falhas, custo, latência, tokens */
    public function byProvider(AiUsageFiltersData $filters): array
    {
        $rate = $this->rates->rate();

        return $this->calls($filters)
            ->select(['c.provider', 'c.model'])
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN c.status = ? THEN 1 ELSE 0 END), 0) as failed_calls', ['failed'])
            ->selectRaw('COALESCE(SUM(CASE WHEN c.status = ? THEN 1 ELSE 0 END), 0) as skipped_calls', ['skipped'])
            ->selectRaw('COALESCE(SUM(c.raw_cost_usd), 0) as cost_usd')
            ->selectRaw('AVG(c.latency_ms) as avg_latency_ms')
            ->selectRaw('COALESCE(SUM(c.input_tokens), 0) as tokens_in')
            ->selectRaw('COALESCE(SUM(c.output_tokens), 0) as tokens_out')
            ->groupBy('c.provider', 'c.model')
            ->get()
            ->map(fn ($row) => [
                'provider'          => (string) $row->provider,
                'provider_label'    => $this->providerLabel((string) $row->provider),
                'model'             => (string) $row->model,
                'calls'             => (int) $row->calls,
                'failed_calls'      => (int) $row->failed_calls,
                'skipped_calls'     => (int) $row->skipped_calls,
                'call_failure_rate' => $this->pct((int) $row->failed_calls, (int) $row->calls),
                'cost_usd'          => round((float) $row->cost_usd, 6),
                'cost_brl'          => round((float) $row->cost_usd * $rate, 2),
                'avg_latency_ms'    => $row->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
                'tokens_in'         => (int) $row->tokens_in,
                'tokens_out'        => (int) $row->tokens_out,
            ])
            ->sortBy([['cost_usd', 'desc'], ['calls', 'desc']])
            ->values()
            ->all();
    }

    // ── Execuções ───────────────────────────────────────────────────────────

    /** @param 'created_at'|'cost' $sort */
    public function runsPage(AiUsageFiltersData $filters, string $sort, string $direction, int $perPage = 20): LengthAwarePaginator
    {
        $rate      = $this->rates->rate();
        $paginator = $this->runsQuery($filters, $sort, $direction)->paginate($perPage)->withQueryString();
        $providers = $this->providersByRun(collect($paginator->items())->pluck('id')->all());

        return $paginator->through(fn ($row) => $this->runRow($row, $providers, $rate));
    }

    /**
     * Todas as execuções do recorte (exportação), mais recentes primeiro, em
     * lotes — o CSV é escrito enquanto lê, sem carregar o período inteiro na
     * memória. Ordem com desempate por id: os lotes não repetem nem pulam linhas.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function exportRuns(AiUsageFiltersData $filters, int $chunk = 500): Generator
    {
        $rate = $this->rates->rate();
        $page = 1;

        do {
            $rows      = $this->runsQuery($filters, 'created_at', 'desc')->forPage($page++, $chunk)->get();
            $providers = $this->providersByRun($rows->pluck('id')->all());

            foreach ($rows as $row) {
                yield $this->runRow($row, $providers, $rate);
            }
        } while ($rows->count() === $chunk);
    }

    /** @return array<string, mixed>|null metadados da execução + chamadas aos provedores (nunca prompt/resposta) */
    public function runDetail(string $runId): ?array
    {
        $run = DB::table('ai_runs as r')
            ->leftJoin('entities as e', 'e.id', '=', 'r.entity_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.requested_by')
            ->leftJoin('users as a', 'a.id', '=', 'r.approved_by')
            ->where('r.id', $runId)
            ->first([
                'r.id', 'r.workflow', 'r.mode', 'r.risk_level', 'r.status', 'r.created_at', 'r.updated_at',
                'r.approved_at', 'r.cancelled_at', 'r.parent_run_id', 'r.error_message',
                'r.estimated_credits', 'r.reserved_credits', 'r.consumed_credits',
                'e.id as entity_id', 'e.name as entity_name', 'e.is_client',
                'u.name as user_name', 'a.name as approver_name',
            ]);

        if ($run === null) {
            return null;
        }

        $rate  = $this->rates->rate();
        $calls = DB::table('ai_run_provider_calls')
            ->where('ai_run_id', $runId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['role', 'provider', 'model', 'status', 'input_tokens', 'output_tokens', 'reasoning_tokens',
                'latency_ms', 'raw_cost_usd', 'error_message', 'created_at']);

        $costUsd = (float) $calls->sum(fn ($call) => (float) $call->raw_cost_usd);

        return [
            'id'             => (string) $run->id,
            'created_at'     => $this->iso($run->created_at),
            'updated_at'     => $this->iso($run->updated_at),
            'approved_at'    => $this->iso($run->approved_at),
            'cancelled_at'   => $this->iso($run->cancelled_at),
            'workflow'       => (string) $run->workflow,
            'workflow_label' => $this->workflowLabel((string) $run->workflow),
            'mode'           => (string) $run->mode,
            'mode_label'     => $this->label('ai.mode_' . $run->mode, (string) $run->mode),
            'risk_level'     => (string) $run->risk_level,
            'status'         => (string) $run->status,
            'status_label'   => $this->label('ai.status_' . $run->status, (string) $run->status),
            'is_escalation'  => $run->parent_run_id !== null,
            // error_message do run já é o texto genérico (AiRunExecutionService::doctorSafeReason).
            'error'             => $run->error_message,
            'entity_id'         => $run->entity_id !== null ? (string) $run->entity_id : null,
            'entity_name'       => $run->entity_name,
            'is_internal'       => $run->entity_id !== null && ! $run->is_client,
            'user_name'         => $run->user_name,
            'approver_name'     => $run->approver_name,
            'estimated_credits' => (int) $run->estimated_credits,
            'reserved_credits'  => (int) $run->reserved_credits,
            'consumed_credits'  => (int) $run->consumed_credits,
            'cost_usd'          => round($costUsd, 6),
            'cost_brl'          => round($costUsd * $rate, 2),
            'calls'             => $calls->map(fn ($call) => [
                'role'             => (string) $call->role,
                'role_label'       => $this->label('manager_ai_usage.roles.' . $call->role, (string) $call->role),
                'provider'         => (string) $call->provider,
                'provider_label'   => $this->providerLabel((string) $call->provider),
                'model'            => (string) $call->model,
                'status'           => (string) $call->status,
                'status_label'     => $this->label('manager_ai_usage.call_statuses.' . $call->status, (string) $call->status),
                'tokens_in'        => (int) $call->input_tokens,
                'tokens_out'       => (int) $call->output_tokens,
                'tokens_reasoning' => (int) $call->reasoning_tokens,
                'latency_ms'       => $call->latency_ms !== null ? (int) $call->latency_ms : null,
                'cost_usd'         => $call->raw_cost_usd !== null ? round((float) $call->raw_cost_usd, 6) : null,
                'cost_brl'         => $call->raw_cost_usd !== null ? round((float) $call->raw_cost_usd * $rate, 2) : null,
                // Mensagem do provedor pode trazer chave/URL/corpo cru — só a versão saneada.
                'error' => $call->status === 'failed'
                    ? ProviderErrorSanitizer::sanitize($call->error_message, __('manager_ai_usage.call_error_generic'))
                    : null,
                'created_at' => $this->iso($call->created_at),
            ])->all(),
        ];
    }

    // ── Opções dos filtros ──────────────────────────────────────────────────

    /** @return array{entities: list<array<string, mixed>>, workflows: list<array<string, string>>, providers: list<array<string, string>>, statuses: list<array<string, string>>} */
    public function filterOptions(): array
    {
        $entities = DB::table('entities')
            ->whereIn('id', DB::table('ai_runs')->select('entity_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name', 'is_client'])
            ->map(fn ($entity) => ['value' => (string) $entity->id, 'label' => (string) $entity->name, 'is_internal' => ! $entity->is_client])
            ->all();

        $workflows = DB::table('ai_runs')->distinct()->pluck('workflow')
            ->map(fn ($workflow) => ['value' => (string) $workflow, 'label' => $this->workflowLabel((string) $workflow)])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'entities'  => $entities,
            'workflows' => $workflows,
            'providers' => array_map(fn (AiProvider $p) => ['value' => $p->value, 'label' => $this->providerLabel($p->value)], AiProvider::cases()),
            'statuses'  => array_map(fn (AiRunStatus $s) => ['value' => $s->value, 'label' => $this->label('ai.status_' . $s->value, $s->value)], AiRunStatus::cases()),
        ];
    }

    public function userName(string $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('name');
    }

    // ── Base das consultas ──────────────────────────────────────────────────

    /** Execuções do recorte. */
    private function runs(AiUsageFiltersData $filters): Builder
    {
        return $this->applyRunFilters(DB::table('ai_runs as r'), $filters)
            ->when($filters->provider, fn (Builder $q, string $provider) => $q->whereExists(
                fn (Builder $sub) => $sub->selectRaw('1')
                    ->from('ai_run_provider_calls as pc')
                    ->whereColumn('pc.ai_run_id', 'r.id')
                    ->where('pc.provider', $provider),
            ));
    }

    /** Chamadas aos provedores das execuções do recorte. */
    private function calls(AiUsageFiltersData $filters): Builder
    {
        return $this->applyRunFilters(
            DB::table('ai_run_provider_calls as c')->join('ai_runs as r', 'r.id', '=', 'c.ai_run_id'),
            $filters,
        )->when($filters->provider, fn (Builder $q, string $provider) => $q->where('c.provider', $provider));
    }

    private function applyRunFilters(Builder $query, AiUsageFiltersData $filters): Builder
    {
        return $query
            ->whereBetween('r.created_at', [$filters->from, $filters->to])
            ->when($filters->entityId, fn (Builder $q, string $id) => $q->where('r.entity_id', $id))
            ->when($filters->userId, fn (Builder $q, string $id) => $q->where('r.requested_by', $id))
            ->when($filters->workflow, fn (Builder $q, string $workflow) => $q->where('r.workflow', $workflow))
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('r.status', $status));
    }

    /**
     * Execuções + custo por execução (subconsulta agregada só das execuções
     * do recorte), clínica e usuário.
     */
    private function runsQuery(AiUsageFiltersData $filters, string $sort, string $direction): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $perRun = DB::table('ai_run_provider_calls as c')
            ->whereIn('c.ai_run_id', $this->runs($filters)->select('r.id'))
            ->when($filters->provider, fn (Builder $q, string $provider) => $q->where('c.provider', $provider))
            ->groupBy('c.ai_run_id')
            ->select('c.ai_run_id')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN c.status = ? THEN 1 ELSE 0 END), 0) as failed_calls', ['failed'])
            ->selectRaw('COALESCE(SUM(c.raw_cost_usd), 0) as cost_usd');

        $query = $this->runs($filters)
            ->leftJoinSub($perRun, 'pr', 'pr.ai_run_id', '=', 'r.id')
            ->leftJoin('entities as e', 'e.id', '=', 'r.entity_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.requested_by')
            ->select([
                'r.id', 'r.created_at', 'r.workflow', 'r.mode', 'r.status', 'r.consumed_credits',
                'r.entity_id', 'e.name as entity_name', 'e.is_client', 'r.requested_by', 'u.name as user_name',
            ])
            ->selectRaw('COALESCE(pr.calls, 0) as calls')
            ->selectRaw('COALESCE(pr.failed_calls, 0) as failed_calls')
            ->selectRaw('COALESCE(pr.cost_usd, 0) as cost_usd');

        if ($sort === 'cost') {
            $query->orderByRaw('COALESCE(pr.cost_usd, 0) ' . $direction);
        } else {
            $query->orderBy('r.created_at', $direction);
        }

        // Desempate estável (paginação e lotes da exportação não repetem/pulam linhas).
        return $query->orderBy('r.id', $direction);
    }

    /**
     * Agregados por chave: execuções (contagem, falhas, créditos) e custo das
     * chamadas, ordenados por custo e depois por quantidade.
     *
     * @param list<string> $columns colunas de ai_runs (alias r)
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function grouped(AiUsageFiltersData $filters, array $columns): Collection
    {
        $rate = $this->rates->rate();
        $key  = fn ($row) => implode('|', array_map(fn (string $column) => (string) $row->{$this->alias($column)}, $columns));

        $runs = $this->runs($filters)
            ->select(array_map(fn (string $column) => "{$column} as {$this->alias($column)}", $columns))
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END), 0) as failed', [AiRunStatus::Failed->value])
            ->selectRaw('COALESCE(SUM(r.consumed_credits), 0) as credits')
            ->groupBy($columns)
            ->get();

        $costs = $this->calls($filters)
            ->select(array_map(fn (string $column) => "{$column} as {$this->alias($column)}", $columns))
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(c.raw_cost_usd), 0) as cost_usd')
            ->groupBy($columns)
            ->get()
            ->keyBy($key);

        return $runs
            ->map(function ($row) use ($columns, $costs, $key, $rate) {
                $cost    = $costs[$key($row)] ?? null;
                $costUsd = (float) ($cost->cost_usd ?? 0);
                $runs    = (int) $row->runs;

                return [
                    'keys'         => array_map(fn (string $column) => $row->{$this->alias($column)}, $columns),
                    'runs'         => $runs,
                    'failed'       => (int) $row->failed,
                    'failure_rate' => $this->pct((int) $row->failed, $runs),
                    'calls'        => (int) ($cost->calls ?? 0),
                    ...$this->money($costUsd, (int) $row->credits, $rate),
                    'avg_cost_brl' => $runs > 0 ? round($costUsd * $rate / $runs, 4) : 0.0,
                ];
            })
            ->sortBy([['cost_usd', 'desc'], ['runs', 'desc']])
            ->values();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Custo (US$/R$), créditos e receita estimada (créditos consumidos ×
     * valor do crédito — mesma regra da tela Créditos de IA) e margem.
     *
     * @return array<string, mixed>
     */
    private function money(float $costUsd, int $credits, float $rate): array
    {
        $revenueUsd = $credits * $this->usdPerCredit();
        $costBrl    = round($costUsd * $rate, 2);
        $revenueBrl = round($revenueUsd * $rate, 2);
        $marginBrl  = round($revenueBrl - $costBrl, 2);

        return [
            'cost_usd'    => round($costUsd, 6),
            'cost_brl'    => $costBrl,
            'credits'     => $credits,
            'revenue_usd' => round($revenueUsd, 4),
            'revenue_brl' => $revenueBrl,
            'margin_brl'  => $marginBrl,
            'margin_pct'  => $revenueBrl > 0 ? round($marginBrl / $revenueBrl * 100, 1) : null,
        ];
    }

    /**
     * @param array<string, list<string>> $providers
     *
     * @return array<string, mixed>
     */
    private function runRow(object $row, array $providers, float $rate): array
    {
        $costUsd = (float) $row->cost_usd;

        return [
            'id'             => (string) $row->id,
            'created_at'     => $this->iso($row->created_at),
            'workflow'       => (string) $row->workflow,
            'workflow_label' => $this->workflowLabel((string) $row->workflow),
            'mode'           => (string) $row->mode,
            'mode_label'     => $this->label('ai.mode_' . $row->mode, (string) $row->mode),
            'status'         => (string) $row->status,
            'status_label'   => $this->label('ai.status_' . $row->status, (string) $row->status),
            'entity_id'      => (string) $row->entity_id,
            'entity_name'    => $row->entity_name ?? '—',
            'is_internal'    => $row->entity_name !== null && ! $row->is_client,
            'user_id'        => (string) $row->requested_by,
            'user_name'      => $row->user_name ?? '—',
            'credits'        => (int) $row->consumed_credits,
            'calls'          => (int) $row->calls,
            'failed_calls'   => (int) $row->failed_calls,
            'providers'      => array_map(fn (string $provider) => $this->providerLabel($provider), $providers[(string) $row->id] ?? []),
            'cost_usd'       => round($costUsd, 6),
            'cost_brl'       => round($costUsd * $rate, 2),
        ];
    }

    /**
     * @param list<string> $runIds
     *
     * @return array<string, list<string>>
     */
    private function providersByRun(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        return DB::table('ai_run_provider_calls')
            ->whereIn('ai_run_id', $runIds)
            ->orderBy('provider')
            ->distinct()
            ->get(['ai_run_id', 'provider'])
            ->groupBy('ai_run_id')
            ->map(fn (Collection $calls) => $calls->pluck('provider')->map(fn ($p) => (string) $p)->unique()->values()->all())
            ->all();
    }

    private function workflowLabel(string $workflow): string
    {
        return $this->label('ai.workflow_' . $workflow, $workflow);
    }

    private function providerLabel(string $provider): string
    {
        return $this->label('ai.providers.' . $provider, $provider);
    }

    /** Tradução, ou o valor cru quando não há (ação nova ainda sem rótulo). */
    private function label(string $key, string $fallback): string
    {
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $fallback;
    }

    private function alias(string $column): string
    {
        return 'g_' . str_replace('.', '_', $column);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withoutKeys(array $row): array
    {
        unset($row['keys']);

        return $row;
    }

    private function usdPerCredit(): float
    {
        return (float) config('ai.pricing.usd_per_credit', 0.01);
    }

    private function pct(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    private function deltaPct(float|int $current, float|int $previous): ?float
    {
        if (abs((float) $previous) < 0.005) {
            return null;
        }

        return round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 1);
    }

    private function iso(mixed $value): ?string
    {
        return $value !== null ? Carbon::parse((string) $value)->toIso8601String() : null;
    }
}
