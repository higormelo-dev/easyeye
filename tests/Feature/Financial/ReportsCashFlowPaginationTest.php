<?php

declare(strict_types=1);

use App\Http\Controllers\Financial\FinancialReportsController;
use App\Models\{Entity, FinancialCashEntry, FinancialCategory};
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Relatório de fluxo de caixa — lista de lançamentos paginada no servidor
 * (fase 4): página própria (entries_page) com a query string nos links, busca
 * com % e _ literais, filtros de tipo/status/categoria e ordenação pela
 * whitelist; valor inválido nunca vira 500; agregados (KPIs, por dia, por
 * categoria) sobre o período INTEIRO, sem mudar com página/filtros; nada de
 * outra clínica na lista, nos agregados nem nas opções de categoria.
 */

const REPORTS_CF_PAGE_FROM = '2026-08-01';
const REPORTS_CF_PAGE_TO   = '2026-08-31';

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function reportsCfPageEntry(Entity $entity, array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => '2026-08-10',
        'description' => 'Lançamento',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 100,
        'active'      => true,
    ], $attrs));
}

function reportsCfPageCategory(Entity $entity, string $name, string $type = 'income'): FinancialCategory
{
    return FinancialCategory::query()->create(['entity_id' => $entity->id, 'name' => $name, 'type' => $type, 'active' => true]);
}

/** Props da tela em agosto/2026 com a query extra (filtros, página, ordem). */
function reportsCfPageProps($testCase, array $query = []): array
{
    return $testCase->get(route('panel.financial.reports.cash-flow', ['from' => REPORTS_CF_PAGE_FROM, 'to' => REPORTS_CF_PAGE_TO, ...$query]))
        ->assertOk()
        ->viewData('page')['props'];
}

/** Ids da página atual da lista. */
function reportsCfPageIds(array $props): array
{
    return collect($props['entries']['data'])->pluck('id')->all();
}

describe('paginação no servidor', function () {
    it('pagina com página própria, leva período/filtros nos links e mantém os agregados do período inteiro', function () {
        foreach (range(1, 35) as $day) {
            reportsCfPageEntry($this->entity, [
                'entry_date'  => sprintf('2026-08-%02d', min($day, 31)),
                'description' => "Consulta {$day}",
                'amount'      => 10,
            ]);
        }

        $first = reportsCfPageProps($this, ['search' => 'Consulta']);

        expect($first['entries']['data'])->toHaveCount(FinancialReportsController::CASH_FLOW_PER_PAGE)
            ->and($first['entries']['total'])->toBe(35)
            ->and($first['entries']['last_page'])->toBe(2)
            ->and($first['entries']['next_page_url'])->toContain(FinancialReportsController::CASH_FLOW_PAGE_NAME . '=2')
            // withQueryString(): período e busca seguem no link da próxima página.
            ->and($first['entries']['next_page_url'])->toContain('from=' . REPORTS_CF_PAGE_FROM)
            ->and($first['entries']['next_page_url'])->toContain('search=Consulta');

        $second = reportsCfPageProps($this, ['search' => 'Consulta', FinancialReportsController::CASH_FLOW_PAGE_NAME => 2]);

        expect($second['entries']['data'])->toHaveCount(5)
            ->and($second['entries']['current_page'])->toBe(2)
            ->and(array_intersect(reportsCfPageIds($first), reportsCfPageIds($second)))->toBe([])
            // Agregados = período inteiro, em qualquer página.
            ->and($second['summary'])->toBe($first['summary'])
            ->and($second['summary']['income'])->toBe(350.0)
            ->and($second['overview']['entries_count'])->toBe(35)
            ->and(array_sum(array_column($second['byDay'], 'income')))->toEqual(350.0);
    });

    it('página inválida ou fora do intervalo não vira erro', function () {
        reportsCfPageEntry($this->entity);

        expect(reportsCfPageProps($this, ['entries_page' => 'abc'])['entries']['current_page'])->toBe(1)
            ->and(reportsCfPageProps($this, ['entries_page' => ['x']])['entries']['current_page'])->toBe(1)
            ->and(reportsCfPageProps($this, ['entries_page' => 999])['entries']['data'])->toBe([]);
    });
});

describe('busca, filtros e ordenação da lista', function () {
    it('busca por descrição (sem acento/caixa) e pelo código FLC, com % e _ como texto', function () {
        $percent    = reportsCfPageEntry($this->entity, ['description' => 'Desconto 50% à vista']);
        $fifty      = reportsCfPageEntry($this->entity, ['description' => 'Desconto 500 reais']);
        $underscore = reportsCfPageEntry($this->entity, ['description' => 'Taxa_boleto']);
        $plain      = reportsCfPageEntry($this->entity, ['description' => 'Taxa boleto']);
        $accent     = reportsCfPageEntry($this->entity, ['description' => 'CONSULTA Pediátrica']);

        $ids = fn (string $search) => collect(reportsCfPageProps($this, ['search' => $search])['entries']['data'])
            ->pluck('id')->sort()->values()->all();

        expect($ids('50%'))->toBe([$percent->id])
            ->and($ids('%'))->toBe([$percent->id])
            ->and($ids('taxa_'))->toBe([$underscore->id])
            ->and($ids('_'))->toBe([$underscore->id])
            ->and($ids('pediatrica'))->toBe([$accent->id])
            ->and($ids($fifty->code))->toBe([$fifty->id])
            ->and(in_array($plain->id, $ids('taxa'), true))->toBeTrue();
    });

    it('filtra por tipo, status e categoria sem mudar indicadores, tabelas por dia/categoria nem a exportação', function () {
        $consultas = reportsCfPageCategory($this->entity, 'CONSULTAS');
        $aluguel   = reportsCfPageCategory($this->entity, 'ALUGUEL', 'expense');

        $paidIncome    = reportsCfPageEntry($this->entity, ['amount' => 300, 'category_id' => $consultas->id]);
        $pendingIncome = reportsCfPageEntry($this->entity, ['amount' => 50, 'status' => 'pending', 'category_id' => $consultas->id]);
        $paidExpense   = reportsCfPageEntry($this->entity, ['amount' => 80, 'type' => 'expense', 'category_id' => $aluguel->id]);
        reportsCfPageEntry($this->entity, ['amount' => 999, 'status' => 'cancelled']);

        $all = reportsCfPageProps($this);

        expect(reportsCfPageIds(reportsCfPageProps($this, ['type' => 'expense'])))->toBe([$paidExpense->id])
            ->and(reportsCfPageIds(reportsCfPageProps($this, ['status' => 'pending'])))->toBe([$pendingIncome->id])
            ->and(collect(reportsCfPageIds(reportsCfPageProps($this, ['category_id' => $consultas->id]))))->toHaveCount(2)
            ->and(reportsCfPageIds(reportsCfPageProps($this, ['type' => 'income', 'status' => 'paid', 'category_id' => $consultas->id])))->toBe([$paidIncome->id]);

        $filtered = reportsCfPageProps($this, ['type' => 'expense', 'search' => 'nada']);

        expect($filtered['entries']['total'])->toBe(0)
            ->and($filtered['summary'])->toBe($all['summary'])
            ->and($filtered['overview'])->toBe($all['overview'])
            ->and($filtered['byDay'])->toBe($all['byDay'])
            ->and($filtered['byCategory'])->toBe($all['byCategory'])
            ->and($filtered['filters'])->toMatchArray(['type' => 'expense', 'search' => 'nada']);

        // Exportação: período inteiro, ignorando página e filtros da lista.
        $csv = $this->get(route('panel.financial.reports.cash-flow.export', [
            'from' => REPORTS_CF_PAGE_FROM, 'to' => REPORTS_CF_PAGE_TO, 'type' => 'expense', 'search' => 'nada', 'entries_page' => 2,
        ]))->assertOk()->getContent();

        expect(count(preg_split('/\r?\n/', trim($csv))) - 1)->toBe(3);
    });

    it('status cancelado não é filtro válido: cancelados nunca aparecem na lista', function () {
        reportsCfPageEntry($this->entity, ['description' => 'Cancelado', 'status' => 'cancelled']);
        $paid = reportsCfPageEntry($this->entity, ['description' => 'Pago']);

        $props = reportsCfPageProps($this, ['status' => 'cancelled']);

        expect($props['filters']['status'])->toBeNull()
            ->and(reportsCfPageIds($props))->toBe([$paid->id]);
    });

    it('ordena pelas colunas da whitelist; padrão = data crescente', function () {
        $late  = reportsCfPageEntry($this->entity, ['entry_date' => '2026-08-20', 'amount' => 5, 'description' => 'B']);
        $early = reportsCfPageEntry($this->entity, ['entry_date' => '2026-08-02', 'amount' => 900, 'description' => 'A']);

        $default = reportsCfPageProps($this);

        expect(reportsCfPageIds($default))->toBe([$early->id, $late->id])
            ->and($default['filters'])->toMatchArray(['sort' => 'entry_date', 'direction' => 'asc']);

        $byAmount = reportsCfPageProps($this, ['sort' => 'amount', 'direction' => 'desc']);

        expect(reportsCfPageIds($byAmount))->toBe([$early->id, $late->id])
            ->and($byAmount['filters'])->toMatchArray(['sort' => 'amount', 'direction' => 'desc'])
            ->and(reportsCfPageIds(reportsCfPageProps($this, ['sort' => 'description', 'direction' => 'desc'])))->toBe([$late->id, $early->id]);
    });

    it('filtros inválidos caem no padrão sem erro 500', function (array $query) {
        reportsCfPageEntry($this->entity);

        $props = reportsCfPageProps($this, $query);

        expect($props['entries']['total'])->toBe(1)
            ->and($props['filters'])->toMatchArray([
                'type' => null, 'status' => null, 'category_id' => null, 'search' => '', 'sort' => 'entry_date', 'direction' => 'asc',
            ]);
    })->with([
        'texto'                         => [['type' => 'xyz', 'status' => 'foo', 'category_id' => 'nao-uuid', 'sort' => 'password', 'direction' => 'sideways']],
        'arrays'                        => [['type' => ['income'], 'status' => ['paid'], 'category_id' => ['x'], 'search' => ['a' => 'b'], 'sort' => ['amount'], 'direction' => ['desc']]],
        'injeção'                       => [['sort' => 'amount; DROP TABLE financial_cash_entries', 'category_id' => "' OR '1'='1"]],
        'uuid de categoria inexistente' => [['category_id' => '00000000-0000-4000-8000-000000000000']],
    ]);

    it('busca longa é cortada em 100 caracteres', function () {
        reportsCfPageEntry($this->entity);

        $props = reportsCfPageProps($this, ['search' => str_repeat('a', 300)]);

        expect(mb_strlen($props['filters']['search']))->toBe(100)
            ->and($props['entries']['total'])->toBe(0);
    });
});

describe('linha da lista e isolamento por clínica', function () {
    it('cada linha abre a tela de Fluxo de caixa no mesmo período buscando pelo código FLC', function () {
        $entry = reportsCfPageEntry($this->entity, ['payment_method' => 'cash']);

        $this->get(route('panel.financial.reports.cash-flow', ['from' => REPORTS_CF_PAGE_FROM, 'to' => REPORTS_CF_PAGE_TO]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.id', (string) $entry->id)
                ->where('entries.data.0.code', $entry->code)
                ->where('entries.data.0.cash_flow_url', route('panel.financial.cash-flow.index', [
                    'from' => REPORTS_CF_PAGE_FROM, 'to' => REPORTS_CF_PAGE_TO, 'search' => $entry->code,
                ]))
                ->has('t.cashflow.open_in_cash_flow_code')
                ->has('t.cashflow.pagination_label'));

        // A tela de Fluxo de caixa encontra exatamente o lançamento pelo link.
        $cashFlow = $this->get(route('panel.financial.cash-flow.index', [
            'from' => REPORTS_CF_PAGE_FROM, 'to' => REPORTS_CF_PAGE_TO, 'search' => $entry->code,
        ]))->assertOk()->viewData('page')['props'];

        expect(collect($cashFlow['entries']['data'])->pluck('id')->all())->toBe([$entry->id]);
    });

    it('outra clínica fica fora da lista, dos agregados e das opções de categoria (id dela no filtro é ignorado)', function () {
        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherCategory = reportsCfPageCategory($other, 'CATEGORIA DE OUTRA');
        reportsCfPageEntry($other, ['description' => 'Outra clínica', 'amount' => 7000, 'category_id' => $otherCategory->id]);

        $mine     = reportsCfPageCategory($this->entity, 'MINHA');
        $ownEntry = reportsCfPageEntry($this->entity, ['amount' => 10, 'category_id' => $mine->id]);

        $props = reportsCfPageProps($this);

        expect(reportsCfPageIds($props))->toBe([$ownEntry->id])
            ->and($props['summary']['income'])->toBe(10.0)
            ->and($props['overview']['entries_count'])->toBe(1)
            ->and(array_column($props['byCategory'], 'category'))->toBe(['MINHA'])
            ->and(array_column($props['byDay'], 'income'))->toBe([10.0])
            ->and($props['categories'])->toBe([['id' => (string) $mine->id, 'name' => 'MINHA', 'type' => 'income']]);

        $foreign = reportsCfPageProps($this, ['category_id' => $otherCategory->id, 'search' => 'Outra']);

        expect($foreign['filters']['category_id'])->toBeNull()
            ->and($foreign['entries']['total'])->toBe(0);

        $csv = $this->get(route('panel.financial.reports.cash-flow.export', ['from' => REPORTS_CF_PAGE_FROM, 'to' => REPORTS_CF_PAGE_TO]))
            ->assertOk()->getContent();

        expect($csv)->not->toContain('Outra clínica');
    });

    it('opções de categoria = categorias com lançamento no período, sem excluídas, agrupáveis pelo tipo', function () {
        $consultas = reportsCfPageCategory($this->entity, 'CONSULTAS');
        $aluguel   = reportsCfPageCategory($this->entity, 'ALUGUEL', 'expense');
        $sem       = reportsCfPageCategory($this->entity, 'SEM LANCAMENTO');
        $excluida  = reportsCfPageCategory($this->entity, 'EXCLUIDA');

        reportsCfPageEntry($this->entity, ['category_id' => $consultas->id]);
        reportsCfPageEntry($this->entity, ['type' => 'expense', 'category_id' => $aluguel->id]);
        reportsCfPageEntry($this->entity, ['category_id' => $excluida->id]);
        reportsCfPageEntry($this->entity, ['category_id' => $sem->id, 'entry_date' => '2026-07-10']); // fora do período
        $excluida->delete();

        expect(reportsCfPageProps($this)['categories'])->toBe([
            ['id' => (string) $aluguel->id, 'name' => 'ALUGUEL', 'type' => 'expense'],
            ['id' => (string) $consultas->id, 'name' => 'CONSULTAS', 'type' => 'income'],
        ]);
    });
});
