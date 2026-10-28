<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal, TissOperator};
use App\Http\Controllers\Financial\TissGlosasController;
use App\Models\Entity;
use Illuminate\Support\{Arr, Carbon, Collection};
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * Conciliação de glosas em escala (Fase 4): lista paginada e KPIs, contagem
 * das abas e resumo por operadora calculados com agregados SQL. Os números
 * precisam ser os MESMOS do cálculo antigo em memória — reimplementado aqui
 * (gscOld*) — sobre um fixture variado: todos os status, prazos vencidos /
 * vencendo / fora da janela / sem prazo, operadoras homônimas, operadora
 * excluída, aceito acima do valor glosado, recurso excluído, glosa excluída,
 * glosa sem data e outra clínica.
 */

function gscOperator(string $name, ?string $tradeName = null): TissOperator
{
    return TissOperator::query()->create([
        'ans_code'   => (string) random_int(100000, 999999),
        'name'       => $name,
        'trade_name' => $tradeName,
        'active'     => true,
    ]);
}

/** @param array<string, mixed> $attributes */
function gscGlosa(Entity $entity, TissOperator $operator, array $attributes = []): TissGlosa
{
    return TissGlosa::query()->create(array_merge([
        'entity_id'         => $entity->id,
        'operator_id'       => $operator->id,
        'status'            => TissGlosaStatus::Open->value,
        'glosa_code'        => '3099',
        'glosa_description' => 'Procedimento não autorizado',
        'amount'            => 100,
        'identified_at'     => '2026-09-10',
        'deadline'          => '2026-09-25',
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function gscAppeal(TissGlosa $glosa, array $attributes = []): TissGlosaAppeal
{
    static $sequence = 0;
    $sequence++;

    return TissGlosaAppeal::query()->create(array_merge([
        'entity_id'        => $glosa->entity_id,
        'glosa_id'         => $glosa->id,
        'appeal_number'    => sprintf('REC-209909-%05d', $sequence),
        'status'           => TissAppealStatus::Accepted->value,
        'reason'           => 'Cobertura contratual válida.',
        'requested_amount' => $glosa->amount,
        'accepted_amount'  => 0,
        'submitted_at'     => '2026-09-12 10:00:00',
        'deadline'         => '2026-11-11',
    ], $attributes));
}

/**
 * "Hoje" = 2026-09-15; período padrão = setembro/2026.
 *
 * @return array<string, TissOperator>
 */
function gscSeedFixture(Entity $ours): array
{
    $unimedA  = gscOperator('UNIMED');
    $unimedB  = gscOperator('UNIMED'); // homônima: mesmo nome, outra operadora
    $bradesco = gscOperator('BRADESCO SAUDE S.A.', 'Bradesco Saúde');
    $extinct  = gscOperator('OPERADORA EXTINTA');

    $appealed = TissGlosaStatus::Appealed->value;

    // Fila (Abertas): vencida, vence hoje, no limite dos 5 dias, fora da janela, sem prazo, valor zero.
    gscGlosa($ours, $unimedA, ['amount' => 100.10, 'identified_at' => '2026-09-10', 'deadline' => '2026-09-12']);
    gscGlosa($ours, $unimedB, ['amount' => 200.20, 'identified_at' => '2026-08-01', 'deadline' => '2026-09-15', 'glosa_description' => 'Duplicidade de cobrança']);
    gscGlosa($ours, $bradesco, ['amount' => 50.05, 'identified_at' => '2026-09-14', 'deadline' => '2026-09-20']);
    gscGlosa($ours, $bradesco, ['amount' => 30, 'identified_at' => '2026-09-14', 'deadline' => '2026-09-21', 'glosa_description' => 'Duplicidade de cobrança']);
    gscGlosa($ours, $unimedA, ['amount' => 10, 'identified_at' => null, 'deadline' => null]);
    gscGlosa($ours, $bradesco, ['amount' => 0, 'identified_at' => '2026-09-11', 'deadline' => '2026-09-16']);

    // Recorridas: prazo da glosa vencido, mas recorrida não conta como vencida.
    $appealedNow = gscGlosa($ours, $unimedA, ['status' => $appealed, 'amount' => 300.33, 'identified_at' => '2026-09-05', 'deadline' => '2026-09-01']);
    gscAppeal($appealedNow, ['status' => TissAppealStatus::Submitted->value]);
    $appealedOld = gscGlosa($ours, $bradesco, ['status' => $appealed, 'amount' => 70, 'identified_at' => '2026-07-20']);
    gscAppeal($appealedOld, ['status' => TissAppealStatus::Opened->value, 'submitted_at' => null, 'deadline' => null]);

    // Resolvidas: recuperado = soma dos aceitos, limitada ao valor glosado.
    $partial = gscGlosa($ours, $unimedB, ['status' => TissGlosaStatus::PartialReversed->value, 'amount' => 1000, 'identified_at' => '2026-09-03', 'deadline' => null]);
    gscAppeal($partial, ['accepted_amount' => 200]);
    gscAppeal($partial, ['accepted_amount' => 50.50]);
    gscAppeal($partial, ['status' => TissAppealStatus::Rejected->value]);

    $over = gscGlosa($ours, $unimedA, ['status' => TissGlosaStatus::Reversed->value, 'amount' => 100, 'identified_at' => '2026-09-04']);
    gscAppeal($over, ['accepted_amount' => 250]); // legado: aceito > glosado

    // Aceito abaixo do glosado: um recurso excluído contado por engano mudaria o número (não fica escondido pelo teto).
    $withDeletedAppeal = gscGlosa($ours, $bradesco, ['status' => TissGlosaStatus::PartialReversed->value, 'amount' => 80, 'identified_at' => '2026-09-06', 'glosa_description' => 'Duplicidade de cobrança']);
    gscAppeal($withDeletedAppeal, ['accepted_amount' => 30]);
    gscAppeal($withDeletedAppeal, ['accepted_amount' => 40])->delete(); // recurso excluído não conta

    $maintained = gscGlosa($ours, $extinct, ['status' => TissGlosaStatus::Maintained->value, 'amount' => 40, 'identified_at' => '2026-09-07']);
    gscAppeal($maintained, ['status' => TissAppealStatus::Rejected->value]);

    gscGlosa($ours, $unimedB, ['status' => TissGlosaStatus::Cancelled->value, 'amount' => 60, 'identified_at' => '2026-09-30']); // último dia do período
    gscGlosa($ours, $unimedA, ['status' => TissGlosaStatus::Reversed->value, 'amount' => 90, 'identified_at' => '2026-10-01']); // fora do período

    // Glosa excluída: fora de tudo.
    gscGlosa($ours, $unimedA, ['amount' => 999, 'identified_at' => '2026-09-10', 'deadline' => '2026-09-12'])->delete();

    // Outra clínica, mesmas operadoras: nunca entra.
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    gscGlosa($other, $unimedA, ['amount' => 5000, 'identified_at' => '2026-09-10', 'deadline' => '2026-09-12']);
    $theirs = gscGlosa($other, $unimedA, ['status' => TissGlosaStatus::Reversed->value, 'amount' => 700, 'identified_at' => '2026-09-10', 'glosa_description' => 'Duplicidade de cobrança']);
    gscAppeal($theirs, ['accepted_amount' => 700]);

    // Operadora excluída depois de ter glosa: não empresta o nome ao resumo.
    $extinct->delete();

    return compact('unimedA', 'unimedB', 'bradesco', 'extinct');
}

/**
 * Glosas do período exatamente como o antigo loadPeriod() carregava.
 *
 * @return Collection<int, TissGlosa>
 */
function gscOldPeriodGlosas(Entity $entity, ?string $operatorId, string $from, string $to): Collection
{
    return TissGlosa::query()
        ->forEntity($entity->id)
        ->when($operatorId !== null, fn ($q) => $q->where('tiss_glosas.operator_id', $operatorId))
        ->with(['operator', 'appeals'])
        ->whereBetween('tiss_glosas.identified_at', [$from, $to])
        ->get();
}

/**
 * Cálculo antigo (Fase 3) em memória: fila da clínica/operadora de qualquer
 * data + totais do período (somados em PHP).
 *
 * @return array<string, float|int>
 */
function gscOldSummary(Entity $entity, ?string $operatorId, string $from, string $to): array
{
    $today = now()->toDateString();
    $soon  = now()->addDays(5)->toDateString();

    $all = TissGlosa::query()->forEntity($entity->id)
        ->when($operatorId !== null, fn ($q) => $q->where('operator_id', $operatorId))
        ->get();

    $deadline = fn (TissGlosa $g): ?string => $g->deadline?->toDateString();
    $open     = $all->filter(fn (TissGlosa $g) => $g->status === TissGlosaStatus::Open);
    $appealed = $all->filter(fn (TissGlosa $g) => $g->status === TissGlosaStatus::Appealed);
    $overdue  = $open->filter(fn (TissGlosa $g) => $deadline($g) !== null && $deadline($g) < $today);
    $dueSoon  = $open->filter(fn (TissGlosa $g) => $deadline($g) !== null && $deadline($g) >= $today && $deadline($g) <= $soon);
    $period   = gscOldPeriodGlosas($entity, $operatorId, $from, $to);
    $sum      = fn (Collection $glosas): float => round((float) $glosas->sum('amount'), 2);

    return [
        'open'           => $sum($open),
        'open_count'     => $open->count(),
        'appealed'       => $sum($appealed),
        'appealed_count' => $appealed->count(),
        'overdue'        => $sum($overdue),
        'overdue_count'  => $overdue->count(),
        'due_soon'       => $sum($dueSoon),
        'due_soon_count' => $dueSoon->count(),
        'total'          => $sum($period),
        'count'          => $period->count(),
        'recovered'      => round((float) $period->sum(fn (TissGlosa $g) => min(
            (float) $g->appeals->where('status', TissAppealStatus::Accepted)->sum('accepted_amount'),
            (float) $g->amount,
        )), 2),
    ];
}

/**
 * Contagem antiga por aba, em memória (a busca do fixture só casa pela
 * descrição do motivo).
 *
 * @return array<string, int>
 */
function gscOldTabCounts(Entity $entity, ?string $operatorId, string $search, string $from, string $to): array
{
    $pending  = [TissGlosaStatus::Open, TissGlosaStatus::Appealed];
    $resolved = [TissGlosaStatus::PartialReversed, TissGlosaStatus::Reversed, TissGlosaStatus::Maintained, TissGlosaStatus::Cancelled];

    $glosas = TissGlosa::query()->forEntity($entity->id)
        ->when($operatorId !== null, fn ($q) => $q->where('operator_id', $operatorId))
        ->get()
        ->filter(fn (TissGlosa $g) => $search === '' || str_contains(mb_strtolower((string) $g->glosa_description), mb_strtolower($search)));

    $inPeriod = fn (TissGlosa $g): bool => $g->identified_at !== null
        && $g->identified_at->toDateString() >= $from
        && $g->identified_at->toDateString() <= $to;

    return [
        'pending'  => $glosas->filter(fn (TissGlosa $g) => in_array($g->status, $pending, true))->count(),
        'resolved' => $glosas->filter(fn (TissGlosa $g) => $inPeriod($g) && in_array($g->status, $resolved, true))->count(),
        'all'      => $glosas->filter($inPeriod)->count(),
    ];
}

/**
 * Resumo por operadora antigo (groupBy em memória), copiado da Fase 3.
 *
 * @return list<array<string, mixed>>
 */
function gscOldByOperator(Entity $entity, ?string $operatorId, string $from, string $to): array
{
    return gscOldPeriodGlosas($entity, $operatorId, $from, $to)
        ->groupBy(fn (TissGlosa $g) => (string) ($g->operator_id ?? ''))
        ->map(fn (Collection $group, string $id) => [
            'id'    => $id,
            'name'  => $group->first()->operator?->trade_name ?? $group->first()->operator?->name ?? __('financial_glosas.no_covenant'),
            'total' => round((float) $group->sum('amount'), 2),
            'open'  => round((float) $group->where('status', TissGlosaStatus::Open)->sum('amount'), 2),
            'count' => $group->count(),
        ])
        ->sortByDesc('open')
        ->values()
        ->all();
}

/** @return array<string, mixed> */
function gscProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** @return list<string> */
function gscPageIds(array $props): array
{
    return array_column($props['glosas']['data'], 'id');
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

describe('agregados SQL == cálculo antigo em memória', function (): void {
    it('KPIs, contagem das abas e resumo por operadora dão os mesmos números', function (array $query): void {
        $operators  = gscSeedFixture($this->entity);
        $operatorId = isset($query['operator']) ? $operators[$query['operator']]->id : null;

        $props = gscProps($this->get(route('panel.financial.tiss.glosas.index', array_filter([
            'tab'         => $query['tab'] ?? null,
            'from'        => $query['from'] ?? null,
            'to'          => $query['to'] ?? null,
            'search'      => $query['search'] ?? null,
            'operator_id' => $operatorId,
        ])))->assertOk());

        ['from' => $from, 'to' => $to, 'search' => $search] = $props['filters'];
        expect($props['filters']['operator_id'])->toBe($operatorId);

        $oldSummary = gscOldSummary($this->entity, $operatorId, $from, $to);
        expect(Arr::only($props['summary'], array_keys($oldSummary)))->toEqual($oldSummary)
            ->and($props['tabCounts'])->toEqual(gscOldTabCounts($this->entity, $operatorId, $search, $from, $to));

        $old = gscOldByOperator($this->entity, $operatorId, $from, $to);
        expect(collect($props['byOperator'])->keyBy('id')->all())->toEqual(collect($old)->keyBy('id')->all());

        // Mesma ordem de leitura: maior "em aberto" primeiro.
        $open   = array_column($props['byOperator'], 'open');
        $sorted = $open;
        rsort($sorted);
        expect($open)->toEqual($sorted);
    })->with([
        'padrão (Pendentes, mês atual)'      => [[]],
        'operadora homônima filtrada'        => [['operator' => 'unimedB']],
        'Todas com período largo'            => [['tab' => 'all', 'from' => '2026-07-01', 'to' => '2026-09-30']],
        'Resolvidas + busca'                 => [['tab' => 'resolved', 'search' => 'duplicidade']],
        'busca na fila + operadora filtrada' => [['search' => 'Duplicidade', 'operator' => 'bradesco']],
    ]);

    it('valores esperados do fixture (âncora independente dos dois cálculos)', function (): void {
        gscSeedFixture($this->entity);

        $props = gscProps($this->get(route('panel.financial.tiss.glosas.index'))->assertOk());

        expect($props['summary'])->toMatchArray([
            'open'           => 390.35,
            'open_count'     => 6,
            'appealed'       => 370.33,
            'appealed_count' => 2,
            'overdue'        => 100.10,
            'overdue_count'  => 1,
            'due_soon'       => 250.25,
            'due_soon_count' => 3,
            'total'          => 1760.48,
            'count'          => 10,
            'recovered'      => 380.50, // 250,50 + min(250, 100) + 30 (recurso excluído e outra clínica fora)
        ])
            ->and($props['tabCounts'])->toBe(['pending' => 8, 'resolved' => 5, 'all' => 10]);

        $byName = collect($props['byOperator']);
        expect($byName->pluck('name')->all())->toBe(['UNIMED', 'Bradesco Saúde', 'UNIMED', __('financial_glosas.no_covenant')])
            ->and($byName->pluck('count')->all())->toBe([3, 4, 2, 1])
            ->and($byName->pluck('open')->all())->toEqual([100.10, 80.05, 0, 0]);
    });
});

describe('lista paginada', function (): void {
    it('pagina a fila mantendo a ordem por prazo entre as páginas (vencidas primeiro, sem prazo por último)', function (): void {
        $operator = gscOperator('UNIMED');

        $offsets = range(0, 31);
        shuffle($offsets); // ordem de criação aleatória: a ordem da tela vem do SQL

        $byDeadline = [];

        foreach ($offsets as $offset) {
            $byDeadline[$offset] = gscGlosa($this->entity, $operator, [
                'deadline'      => Carbon::parse('2026-08-20')->addDays($offset)->toDateString(),
                'identified_at' => '2026-09-01',
            ])->id;
        }
        ksort($byDeadline);

        $noDeadline = [];

        foreach (['2026-09-03', '2026-09-01', '2026-09-02'] as $day) {
            $noDeadline[$day] = gscGlosa($this->entity, $operator, ['deadline' => null, 'identified_at' => $day])->id;
        }
        ksort($noDeadline);

        $expected = [...array_values($byDeadline), ...array_values($noDeadline)];

        $first  = gscProps($this->get(route('panel.financial.tiss.glosas.index'))->assertOk());
        $second = gscProps($this->get(route('panel.financial.tiss.glosas.index', ['page' => 2]))->assertOk());

        expect($first['glosas']['per_page'])->toBe(TissGlosasController::PER_PAGE)
            ->and($first['glosas']['total'])->toBe(35)
            ->and($first['glosas']['last_page'])->toBe(2)
            ->and($first['glosas']['data'])->toHaveCount(30)
            ->and($second['glosas']['data'])->toHaveCount(5)
            ->and([...gscPageIds($first), ...gscPageIds($second)])->toBe($expected)
            // Agregados valem para o filtro inteiro, não para a página.
            ->and($first['tabCounts']['pending'])->toBe(35)
            ->and($first['summary']['open_count'])->toBe(35);
    });

    it('page inválida cai na primeira; além da última mostra a última (sem 500)', function (): void {
        $operator = gscOperator('UNIMED');

        foreach (range(1, 35) as $i) {
            gscGlosa($this->entity, $operator, ['deadline' => Carbon::parse('2026-09-01')->addDays($i)->toDateString()]);
        }

        foreach (['abc', '-1', '0', ['x']] as $invalid) {
            $props = gscProps($this->get(route('panel.financial.tiss.glosas.index', ['page' => $invalid]))->assertOk());
            expect($props['glosas']['current_page'])->toBe(1)->and($props['glosas']['data'])->toHaveCount(30);
        }

        $beyond = gscProps($this->get(route('panel.financial.tiss.glosas.index', ['page' => 99]))->assertOk());
        expect($beyond['glosas']['current_page'])->toBe(2)
            ->and($beyond['glosas']['data'])->toHaveCount(5);
    });

    it('links das páginas levam os filtros da URL, mas não o detalhe; o detalhe do link direto continua', function (): void {
        $operator = gscOperator('UNIMED');
        $glosas   = collect(range(1, 31))->map(fn (int $i) => gscGlosa($this->entity, $operator, [
            'deadline' => Carbon::parse('2026-09-01')->addDays($i)->toDateString(),
        ]));

        $props = gscProps($this->get(route('panel.financial.tiss.glosas.index', [
            'operator_id' => $operator->id,
            'search'      => 'autorizado',
            'detail'      => $glosas->first()->id,
        ]))->assertOk());

        parse_str((string) parse_url((string) $props['glosas']['next_page_url'], PHP_URL_QUERY), $query);

        expect($query)->toMatchArray(['operator_id' => $operator->id, 'search' => 'autorizado', 'page' => '2'])
            ->and($query)->not->toHaveKey('detail')
            ->and(collect($props['glosas']['links'])->pluck('url')->filter()->implode(' '))->not->toContain('detail=')
            ->and($props['glosaDetail']['id'])->toBe($glosas->first()->id);
    });

    it('detalhe por recarga parcial funciona a partir da página 2, sem refazer lista e agregados', function (): void {
        $operator = gscOperator('UNIMED');
        $glosas   = collect(range(1, 31))->map(fn (int $i) => gscGlosa($this->entity, $operator, [
            'deadline' => Carbon::parse('2026-09-01')->addDays($i)->toDateString(),
        ]));
        $onPageTwo = $glosas->last(); // maior prazo: última da fila

        $props = $this->withHeaders(array_merge(inertiaHeaders(), [
            'X-Inertia-Partial-Component' => 'Panel/Financial/Tiss/GlosasIndex',
            'X-Inertia-Partial-Data'      => 'glosaDetail',
        ]))->get(route('panel.financial.tiss.glosas.index', ['page' => 2, 'detail' => $onPageTwo->id]))
            ->assertOk()
            ->json('props');

        expect($props['glosaDetail']['id'])->toBe($onPageTwo->id)
            ->and($props)->not->toHaveKeys(['glosas', 'summary', 'tabCounts', 'byOperator']);
    });

    it('só a página é carregada em memória: KPIs, abas e resumo não hidratam glosas', function (): void {
        $operator = gscOperator('UNIMED');

        foreach (range(1, 40) as $i) {
            $glosa = gscGlosa($this->entity, $operator, [
                'status'        => $i % 4 === 0 ? TissGlosaStatus::Reversed->value : TissGlosaStatus::Open->value,
                'deadline'      => Carbon::parse('2026-09-01')->addDays($i)->toDateString(),
                'identified_at' => '2026-09-05',
            ]);

            if ($i % 4 === 0) {
                gscAppeal($glosa, ['accepted_amount' => 10]);
            }
        }

        $hydrated = 0;
        Event::listen('eloquent.retrieved: ' . TissGlosa::class, function () use (&$hydrated): void {
            $hydrated++;
        });

        $props = gscProps($this->get(route('panel.financial.tiss.glosas.index', ['tab' => 'all']))->assertOk());

        expect($hydrated)->toBe(TissGlosasController::PER_PAGE)
            ->and($props['glosas']['total'])->toBe(40)
            ->and($props['summary']['count'])->toBe(40)
            ->and($props['summary']['recovered'])->toEqual(100)
            ->and($props['byOperator'][0]['count'])->toBe(40);
    });
});
