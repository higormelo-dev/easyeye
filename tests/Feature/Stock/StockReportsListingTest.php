<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, Entity, EntityProduct, MedicalRecord, MedicalRecordProcedure, Patient, People, Procedure, Supplier, User};
use App\Services\MedicalRecordProcedureExecutionService;
use App\Services\Stock\{PurchaseOrderService, StockService};
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Relatórios de estoque no padrão da listagem de pacientes: ordenação por
 * whitelist (por relatório), filtros normalizados, textos via `t`, período
 * validado (antes uma data inválida virava erro 500 do PostgreSQL) e
 * isolamento por clínica preservado.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function stockReportProduct(Entity $entity, string $name, float $qty = 0, float $cost = 0): EntityProduct
{
    $product = EntityProduct::create(['entity_id' => $entity->id, 'name' => $name, 'unit' => 'un', 'active' => true]);

    if ($qty > 0) {
        app(StockService::class)->manualIn($product, $qty, $cost);
    }

    return $product->refresh();
}

/** Props Inertia da tela de relatórios para a clínica do admin. */
function stockReportsProps(array $query = []): array
{
    $props = null;

    test()->actingAs(test()->admin)->withSession(panelSession(test()->adminEntityUser))
        ->get(route('panel.stock.reports.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('Panel/Stock/Reports/Index');
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * Procedimento executado com consumo de material (gera o movimento
 * consumption_out que alimenta o relatório de consumo). `$procedureName`
 * null = execução sem procedimento de catálogo (o serviço mostra '—').
 */
function stockReportConsumption(Entity $entity, EntityProduct $product, ?string $procedureName): void
{
    $doctorEntityUser = createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value);
    $doctor           = Doctor::query()->create([
        'entity_user_id' => $doctorEntityUser->id, 'person_id' => People::factory()->create()->id, 'active' => true,
    ]);
    $patient   = Patient::factory()->create(['entity_id' => $entity->id]);
    $record    = MedicalRecord::query()->create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
    $procedure = $procedureName === null
        ? null
        : Procedure::query()->create(['entity_id' => $entity->id, 'code' => Str::upper(Str::random(6)), 'name' => $procedureName, 'active' => true]);
    $execution = MedicalRecordProcedure::create([
        'entity_id'    => $entity->id, 'patient_id' => $patient->id, 'medical_record_id' => $record->id,
        'procedure_id' => $procedure?->id, 'doctor_id' => $doctor->id,
    ]);

    app(MedicalRecordProcedureExecutionService::class)->markDone(
        $execution,
        $doctorEntityUser,
        [['entity_product_id' => $product->id, 'quantity' => 1.0, 'stock_lot_id' => null]],
    );
}

function stockReportReceive(Entity $entity, string $supplierName, float $quantity, float $unitCost): void
{
    $supplier = Supplier::create(['entity_id' => $entity->id, 'name' => $supplierName, 'active' => true]);
    $product  = stockReportProduct($entity, "Item {$supplierName}");

    $service = app(PurchaseOrderService::class);
    $order   = $service->send($service->createDraft($entity->id, ['supplier_id' => $supplier->id], [
        ['entity_product_id' => $product->id, 'quantity_ordered' => $quantity, 'unit_cost' => $unitCost],
    ]));

    $service->receive($order, [[
        'purchase_order_item_id' => $order->items->first()->id, 'quantity' => $quantity,
        'stock_lot_id'           => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null,
    ]]);
}

describe('Relatórios de estoque — filtros e textos', function () {
    it('entrega os textos traduzidos (t) e os filtros normalizados com a ordenação padrão de hoje', function () {
        $props = stockReportsProps();

        expect($props['t']['page_title'])->toBe(trans('stock_reports.page_title'))
            ->and($props['t'])->toHaveKeys(['tab_inventory', 'col_product', 'btn_export', 'sort_by', 'columns_label'])
            ->and($props['filters'])->toMatchArray([
                'from'      => now()->startOfMonth()->toDateString(),
                'to'        => now()->toDateString(),
                'report'    => 'inventory',
                'sort'      => 'total_value',
                'direction' => 'desc',
            ])
            ->and($props['filters']['sorts'])->toBe([
                'inventory'   => ['sort' => 'total_value', 'direction' => 'desc'],
                'turnover'    => ['sort' => 'qty_out', 'direction' => 'desc'],
                'consumption' => ['sort' => 'total_cost', 'direction' => 'desc'],
                'purchases'   => ['sort' => 'total_spent', 'direction' => 'desc'],
            ]);

        expect($props['sortable']['purchases'])->toBe(['total_spent', 'supplier_name', 'orders_count'])
            ->and($props['sortable']['consumption'])->not->toContain('items');
    });

    it('relatório, ordenação e direção fora da whitelist voltam ao padrão e aparecem normalizados', function () {
        $props = stockReportsProps(['report' => 'drop;table', 'sort' => 'name; drop', 'direction' => 'sideways']);

        expect($props['filters'])->toMatchArray(['report' => 'inventory', 'sort' => 'total_value', 'direction' => 'desc']);

        // `name` é válido na posição valorizada, mas não em compras por fornecedor.
        $props = stockReportsProps(['report' => 'purchases', 'sort' => 'name', 'direction' => 'asc']);

        expect($props['filters'])->toMatchArray(['report' => 'purchases', 'sort' => 'total_spent', 'direction' => 'asc'])
            ->and($props['filters']['sorts']['inventory'])->toBe(['sort' => 'total_value', 'direction' => 'desc']);
    });

    it('guarda a ordenação das outras abas (sorts[...]) validada pela whitelist, com prioridade para sort/direction do report', function () {
        stockReportProduct($this->entity, 'Bravo', 1, 100);
        stockReportProduct($this->entity, 'alfa', 1, 50);

        $props = stockReportsProps([
            'report' => 'turnover', 'sort' => 'name', 'direction' => 'asc',
            'sorts'  => [
                'inventory' => ['sort' => 'name', 'direction' => 'asc'],
                'turnover'  => ['sort' => 'qty_on_hand', 'direction' => 'desc'],   // perde para sort/direction avulsos
                'purchases' => ['sort' => 'name; drop', 'direction' => 'sideways'], // fora da whitelist → padrão
            ],
        ]);

        expect($props['filters'])->toMatchArray(['report' => 'turnover', 'sort' => 'name', 'direction' => 'asc'])
            ->and($props['filters']['sorts'])->toBe([
                'inventory'   => ['sort' => 'name', 'direction' => 'asc'],
                'turnover'    => ['sort' => 'name', 'direction' => 'asc'],
                'consumption' => ['sort' => 'total_cost', 'direction' => 'desc'],
                'purchases'   => ['sort' => 'total_spent', 'direction' => 'desc'],
            ])
            ->and(collect($props['valuedInventory']['items'])->pluck('name')->all())->toBe(['alfa', 'Bravo']);

        // Sem sort/direction avulsos, a aba ativa usa o que veio em sorts[...].
        $props = stockReportsProps(['report' => 'inventory', 'sorts' => ['inventory' => ['sort' => 'name', 'direction' => 'desc']]]);

        expect($props['filters'])->toMatchArray(['report' => 'inventory', 'sort' => 'name', 'direction' => 'desc'])
            ->and(collect($props['valuedInventory']['items'])->pluck('name')->all())->toBe(['Bravo', 'alfa']);

        // sorts em formato inesperado não quebra a tela.
        expect(stockReportsProps(['sorts' => 'name'])['filters']['sorts']['inventory'])
            ->toBe(['sort' => 'total_value', 'direction' => 'desc']);
    });

    it('parâmetros em formato de lista não quebram a tela', function () {
        $props = stockReportsProps(['report' => ['turnover'], 'sort' => ['name'], 'direction' => ['asc'], 'from' => ['x']]);

        expect($props['filters'])->toMatchArray([
            'from' => now()->startOfMonth()->toDateString(), 'report' => 'inventory', 'sort' => 'total_value', 'direction' => 'desc',
        ]);
    });

    it('período inválido ou vazio cai no padrão em vez de erro 500, e período invertido é corrigido', function () {
        expect(stockReportsProps(['from' => 'abc', 'to' => '2026-02-31'])['filters'])->toMatchArray([
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        expect(stockReportsProps(['from' => '', 'to' => ''])['filters'])->toMatchArray([
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        // Ano 0000 passa no round-trip Y-m-d, mas o PostgreSQL não tem ano 0
        // (SQLSTATE 22008) — antes virava erro 500 no giro, que roda sempre.
        expect(stockReportsProps(['from' => '0000-01-01', 'to' => '0000-12-31'])['filters'])->toMatchArray([
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        // Ano 1 é o limite válido: o PostgreSQL aceita e o filtro é mantido.
        expect(stockReportsProps(['from' => '0001-01-01', 'to' => '2026-09-20'])['filters'])->toMatchArray([
            'from' => '0001-01-01',
            'to'   => '2026-09-20',
        ]);

        expect(stockReportsProps(['from' => '2026-09-20', 'to' => '2026-09-01'])['filters'])->toMatchArray([
            'from' => '2026-09-01',
            'to'   => '2026-09-20',
        ]);
    });
});

describe('Relatórios de estoque — ordenação por relatório', function () {
    it('posição valorizada ordena por nome (sem diferenciar acento/caixa) nos dois sentidos', function () {
        stockReportProduct($this->entity, 'Bravo', 1, 100);
        stockReportProduct($this->entity, 'alfa', 1, 50);
        stockReportProduct($this->entity, 'Ábaco', 1, 10);

        $asc  = collect(stockReportsProps(['sort' => 'name', 'direction' => 'asc'])['valuedInventory']['items'])->pluck('name');
        $desc = collect(stockReportsProps(['sort' => 'name', 'direction' => 'desc'])['valuedInventory']['items'])->pluck('name');

        expect($asc->all())->toBe(['Ábaco', 'alfa', 'Bravo'])
            ->and($desc->all())->toBe(['Bravo', 'alfa', 'Ábaco']);
    });

    it('nome repetido desempata pelo código, no mesmo sentido da ordenação', function () {
        foreach ([30, 10, 20] as $cost) {
            stockReportProduct($this->entity, 'Mesmo Nome', 1, $cost);
        }

        $asc  = collect(stockReportsProps(['sort' => 'name', 'direction' => 'asc'])['valuedInventory']['items'])->pluck('code');
        $desc = collect(stockReportsProps(['sort' => 'name', 'direction' => 'desc'])['valuedInventory']['items'])->pluck('code');

        expect($asc->all())->toBe($asc->sort(SORT_NATURAL)->values()->all())
            ->and($desc->all())->toBe($asc->reverse()->values()->all());
    });

    it('empates sem chave secundária são desfeitos pelo id, sempre na mesma ordem', function () {
        foreach ([30, 10, 20] as $cost) {
            stockReportProduct($this->entity, "Saldo igual {$cost}", 1, $cost);
        }

        foreach (['asc', 'desc'] as $direction) {
            $ids = collect(stockReportsProps(['sort' => 'qty_on_hand', 'direction' => $direction])['valuedInventory']['items'])->pluck('id');

            expect($ids->all())->toBe($ids->sort()->values()->all());
        }
    });

    it('padrão (valor total desc) mantém a curva ABC em ordem com valores empatados', function () {
        stockReportProduct($this->entity, 'Empate 1', 1, 100);
        stockReportProduct($this->entity, 'Empate 2', 1, 100);
        stockReportProduct($this->entity, 'Menor', 1, 50);
        stockReportProduct($this->entity, 'Zerado A');
        stockReportProduct($this->entity, 'Zerado B');

        $items      = collect(stockReportsProps()['valuedInventory']['items']);
        $cumulative = $items->pluck('cumulative_pct');

        expect($items->pluck('total_value')->take(3)->all())->toEqual([100, 100, 50])
            ->and($cumulative->all())->toBe($cumulative->sort()->values()->all())
            ->and($items->slice(3)->pluck('id')->all())->toBe($items->slice(3)->pluck('id')->sort()->values()->all());
    });

    it('giro ordena pela razão com quem não tem saldo sempre no fim, sem mexer nos outros relatórios', function () {
        $stock = app(StockService::class);

        $fast = stockReportProduct($this->entity, 'Gira muito', 10, 10);
        $stock->manualOut($fast, 5);   // saldo 5 → giro 1.0
        $slow = stockReportProduct($this->entity, 'Gira pouco', 10, 10);
        $stock->manualOut($slow, 2);   // saldo 8 → giro 0.25
        $empty = stockReportProduct($this->entity, 'Zerou', 5, 10);
        $stock->manualOut($empty, 5);  // saldo 0 → giro null

        $period = ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];

        $desc = stockReportsProps([...$period, 'report' => 'turnover', 'sort' => 'turnover_ratio', 'direction' => 'desc']);
        $asc  = stockReportsProps([...$period, 'report' => 'turnover', 'sort' => 'turnover_ratio', 'direction' => 'asc']);

        expect(collect($desc['turnover'])->pluck('name')->all())->toBe(['Gira muito', 'Gira pouco', 'Zerou'])
            ->and(collect($asc['turnover'])->pluck('name')->all())->toBe(['Gira pouco', 'Gira muito', 'Zerou'])
            ->and($desc['filters'])->toMatchArray(['report' => 'turnover', 'sort' => 'turnover_ratio', 'direction' => 'desc'])
            ->and($desc['filters']['sorts']['inventory'])->toBe(['sort' => 'total_value', 'direction' => 'desc']);
    });

    it('compras por fornecedor ordena por nome do fornecedor', function () {
        stockReportReceive($this->entity, 'Zeta Lentes', 2, 100);  // 200
        stockReportReceive($this->entity, 'Alfa Óptica', 1, 50);   // 50

        $period = ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];

        $default = stockReportsProps([...$period, 'report' => 'purchases']);
        $byName  = stockReportsProps([...$period, 'report' => 'purchases', 'sort' => 'supplier_name', 'direction' => 'asc']);

        expect(collect($default['purchasesBySupplier'])->pluck('supplier_name')->all())->toBe(['Zeta Lentes', 'Alfa Óptica'])
            ->and(collect($byName['purchasesBySupplier'])->pluck('supplier_name')->all())->toBe(['Alfa Óptica', 'Zeta Lentes']);
    });

    it('consumo por procedimento traz a data em ISO (formatada no idioma do usuário na tela) e aceita ordenar por data', function () {
        stockReportConsumption($this->entity, stockReportProduct($this->entity, 'Lente IOL', 5, 200), 'Facectomia');

        $props = stockReportsProps([
            'from'   => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
            'report' => 'consumption', 'sort' => 'executed_at', 'direction' => 'asc',
        ]);

        expect($props['consumptionByProcedure'])->toHaveCount(1)
            ->and($props['consumptionByProcedure'][0]['executed_at'])->toBe(now()->toDateString())
            ->and($props['consumptionByProcedure'][0]['total_cost'])->toEqual(200)
            ->and($props['filters'])->toMatchArray(['report' => 'consumption', 'sort' => 'executed_at', 'direction' => 'asc']);
    });

    it('consumo: procedimento desconhecido (o "—" do serviço) fica no fim nos dois sentidos, como os vazios', function () {
        $product = stockReportProduct($this->entity, 'Lente IOL', 5, 200);
        stockReportConsumption($this->entity, $product, 'Facectomia');
        stockReportConsumption($this->entity, $product, null);

        $period = ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(), 'report' => 'consumption'];

        foreach (['asc', 'desc'] as $direction) {
            $names = collect(stockReportsProps([...$period, 'sort' => 'procedure_name', 'direction' => $direction])['consumptionByProcedure'])
                ->pluck('procedure_name');

            expect($names->all())->toBe(['Facectomia', '—']);
        }
    });
});

describe('Relatórios de estoque — isolamento entre clínicas', function () {
    it('produtos e compras de outra clínica não aparecem, mesmo ordenando', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        giveInventoryModuleAccess($other);

        stockReportProduct($this->entity, 'Daqui', 1, 10);
        stockReportProduct($other, 'De outra clinica', 1, 999);
        stockReportReceive($other, 'Fornecedor alheio', 1, 999);

        $period = ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];

        $inventory = stockReportsProps([...$period, 'sort' => 'name', 'direction' => 'asc']);
        $purchases = stockReportsProps([...$period, 'report' => 'purchases', 'sort' => 'supplier_name', 'direction' => 'asc']);

        expect(collect($inventory['valuedInventory']['items'])->pluck('name')->all())->toBe(['Daqui'])
            ->and($inventory['valuedInventory']['total_value'])->toEqual(10)
            ->and(collect($inventory['turnover'])->pluck('name')->all())->toBe(['Daqui'])
            ->and($purchases['purchasesBySupplier'])->toBe([]);
    });
});

describe('Relatórios de estoque — breadcrumbs, idioma e formato das datas', function () {
    it('breadcrumbs vêm de actions.sidemenu.* e a prop t não carrega os cabeçalhos do CSV', function () {
        $props = stockReportsProps();

        expect(collect($props['breadcrumbs'])->pluck('label')->all())->toBe([
            trans('actions.sidemenu.dashboard'), trans('actions.sidemenu.stock'), trans('actions.sidemenu.stock_reports'),
        ])
            ->and($props['t'])->not->toHaveKey('csv')
            ->and($props['t'])->not->toHaveKey('breadcrumb_stock');
    });

    it('em inglês: breadcrumbs, unidade e fornecedor removido traduzidos', function () {
        stockReportProduct($this->entity, 'Lente IOL', 2, 10);
        stockReportReceive($this->entity, 'Fornecedor apagado', 1, 50);
        Supplier::query()->where('name', 'Fornecedor apagado')->firstOrFail()->delete();

        $props = null;
        $this->actingAs($this->admin)->withSession([...panelSession($this->adminEntityUser), 'locale' => 'en'])
            ->get(route('panel.stock.reports.index', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        expect(collect($props['breadcrumbs'])->pluck('label')->slice(1)->values()->all())->toBe(['Stock', 'Stock reports'])
            ->and(collect($props['valuedInventory']['items'])->firstWhere('name', 'Lente IOL')['unit'])->toBe('Unit')
            ->and(collect($props['purchasesBySupplier'])->pluck('supplier_name')->all())->toBe(['Removed supplier']);
    });

    it('CSV de consumo mantém a data em d/m/Y (a tela recebe ISO)', function () {
        stockReportConsumption($this->entity, stockReportProduct($this->entity, 'Lente IOL', 5, 200), 'Facectomia');

        $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(route('panel.stock.reports.export', [
                'report' => 'consumption', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
            ]));

        $res->assertOk();

        expect(str_replace('"', '', $res->getContent()))
            ->toContain('Facectomia;')
            ->toContain(';' . now()->format('d/m/Y') . ';Lente IOL;');
    });
});
