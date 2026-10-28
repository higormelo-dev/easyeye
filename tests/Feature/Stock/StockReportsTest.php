<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, Subscription, User};
use App\Services\Stock\StockService;

/**
 * Smoke test HTTP — App\Http\Controllers\Stock\StockReportsController.
 * Lógica de cálculo já coberta em tests/Unit/Stock/StockReportServiceTest.php;
 * aqui só confirma que a rota resolve, respeita a dupla trava (permission +
 * feature) e entrega os 4 blocos de dado esperados pro Inertia.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();
    Subscription::factory()->create([
        'entity_id' => $this->entity->id, 'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

it('admin acessa os relatórios de estoque com os 4 blocos de dado', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00);

    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.index'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Stock/Reports/Index')
        ->has('valuedInventory.items', 1)
        ->has('turnover')
        ->has('consumptionByProcedure')
        ->has('purchasesBySupplier'));
});

it('[REGRA DE NEGÓCIO] clínica sem o módulo de estoque no plano recebe 403', function () {
    $entityNoModule = entityWithoutInventoryModule();
    $admin          = User::factory()->create();
    $entityUser     = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)->withSession(panelSession($entityUser))
        ->get(route('panel.stock.reports.index'), ['Accept' => 'application/json'])
        ->assertForbidden();
});

// ── GAP fechado (revisão pós-Fase 4): Financeiro já exportava relatório
// (FinancialReportsController), Estoque não tinha exportação nenhuma ──────

it('[GAP] exportCsv() da posição valorizada retorna CSV com header + linha de dado', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'code' => 'PRD-1', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00);

    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.export'));

    $res->assertOk();
    $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    // fputcsv() envolve em aspas qualquer campo com espaço (CSV válido,
    // RFC 4180) — remove as aspas antes de comparar, o teste cobre
    // CONTEÚDO, não a decisão de aspas do PHP.
    $content = str_replace('"', '', $res->getContent());
    expect($content)->toContain('Produto;Código;Categoria')
        ->and($content)->toContain('Lente IOL;PRD-1');
});

it('[GAP] exportCsv() aceita report=turnover|consumption|purchases — cada um com header próprio', function () {
    $cases = [
        'turnover'    => 'Produto;Código;Saída no período',
        'consumption' => 'Procedimento;Médico;Executado em',
        'purchases'   => 'Fornecedor;Pedidos com recebimento',
    ];

    foreach ($cases as $report => $expectedHeader) {
        $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(route('panel.stock.reports.export', ['report' => $report]));

        $res->assertOk();
        expect(str_replace('"', '', $res->getContent()))->toContain($expectedHeader);
    }
});

it('[GAP][REGRA DE NEGÓCIO] exportCsv() sem o módulo de estoque no plano recebe 403', function () {
    $entityNoModule = entityWithoutInventoryModule();
    $admin          = User::factory()->create();
    $entityUser     = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)->withSession(panelSession($entityUser))
        ->get(route('panel.stock.reports.export'), ['Accept' => 'application/json'])
        ->assertForbidden();
});

// ── Período/parâmetros inválidos e idioma do CSV ─────────────────────────────

it('exportCsv() com período inválido responde 200 com o período padrão e nome de arquivo seguro', function () {
    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.export', [
            'report' => 'turnover',
            'from'   => '2026-09-01"; filename=../../etc/passwd',
            'to'     => '2026-02-31',
        ]));

    $res->assertOk();

    $expected = 'estoque_giro_' . now()->startOfMonth()->toDateString() . '_' . now()->toDateString() . '.csv';

    expect($res->headers->get('Content-Disposition'))->toBe('attachment; filename="' . $expected . '"')
        ->and($expected)->toMatch('/^[a-z_]+_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/');
});

it('exportCsv() com ano 0000 (inexistente no PostgreSQL) cai no período padrão em vez de erro 500', function () {
    $expectedPeriod = now()->startOfMonth()->toDateString() . '_' . now()->toDateString();
    $cases          = ['turnover' => 'estoque_giro_', 'consumption' => 'estoque_consumo_', 'purchases' => 'estoque_compras_'];

    foreach ($cases as $report => $prefix) {
        $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(route('panel.stock.reports.export', ['report' => $report, 'from' => '0000-01-01', 'to' => '0000-12-31']));

        $res->assertOk();
        expect($res->headers->get('Content-Disposition'))
            ->toBe('attachment; filename="' . $prefix . $expectedPeriod . '.csv"');
    }
});

it('exportCsv() neutraliza fórmulas (CSV/Formula Injection) no texto livre, sem mexer no texto comum nem nos números', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => '=1+1', 'code' => '+CMD', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00);
    $other = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => '@SUM(A1)', 'code' => '-2+3', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($other, 5, 10.00);
    $plain = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'code' => 'PRD-1', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($plain, 2, 1.00);

    $content = str_replace('"', '', $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.export'))
        ->assertOk()
        ->getContent());

    expect($content)->toContain("'=1+1;'+CMD;")
        ->and($content)->toContain("'@SUM(A1);'-2+3;")
        ->and($content)->toContain('Lente IOL;PRD-1;')
        ->and($content)->not->toContain(';=1+1')
        ->and($content)->not->toMatch('/^=1\+1/m');
});

it('exportCsv() com parâmetros em formato de lista não quebra: cai no relatório e período padrão', function () {
    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.export', ['report' => ['turnover'], 'from' => ['x'], 'to' => ['y']]));

    $res->assertOk();

    expect($res->headers->get('Content-Disposition'))
        ->toBe('attachment; filename="estoque_posicao_valorizada_' . now()->format('Y-m-d') . '.csv"')
        ->and(str_replace('"', '', $res->getContent()))->toContain('Produto;Código;Categoria');
});

it('exportCsv() usa os cabeçalhos do idioma do usuário (lang stock_reports.csv)', function () {
    $session = [...panelSession($this->adminEntityUser), 'locale' => 'en'];
    $cases   = [
        'inventory'   => 'Product;Code;Category;On hand;Average cost;Total value;Cumulative %;ABC class',
        'turnover'    => 'Product;Code;Out in period;Current on hand;Turnover',
        'consumption' => 'Procedure;Doctor;Performed on;Product;Quantity;Unit cost;Total cost',
        'purchases'   => 'Supplier;Orders received;Total spent',
    ];

    foreach ($cases as $report => $expectedHeader) {
        $res = $this->actingAs($this->admin)->withSession($session)
            ->get(route('panel.stock.reports.export', ['report' => $report]));

        $res->assertOk();
        expect(str_replace('"', '', $res->getContent()))->toContain($expectedHeader);
    }
});

// PHP 8.4: fputcsv() sem $escape explícito emite E_DEPRECATED a cada linha
// (ruído no log/Sentry a cada exportação). Mesmo formato do SpreadsheetWriter
// do financeiro: escape '' = RFC 4180 (aspas sempre dobradas, o que o Excel lê).
it('exportCsv() não emite deprecation do PHP 8.4 em nenhum dos 4 relatórios', function () {
    $this->withoutDeprecationHandling();

    foreach (['inventory', 'turnover', 'consumption', 'purchases'] as $report) {
        $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(route('panel.stock.reports.export', ['report' => $report]))
            ->assertOk();
    }
});

it('exportCsv() dobra as aspas mesmo depois de barra invertida (RFC 4180, igual ao financeiro)', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Tubo 3\" azul', 'code' => 'PRD-2', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 1, 5.00);

    $content = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.export'))
        ->assertOk()
        ->getContent();

    expect($content)->toContain('"Tubo 3\"" azul"');
});
