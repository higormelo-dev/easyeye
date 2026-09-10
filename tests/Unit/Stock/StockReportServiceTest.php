<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, Entity, EntityProduct, MedicalRecord, MedicalRecordProcedure, Patient, People, Procedure, Supplier, User};
use App\Services\MedicalRecordProcedureExecutionService;
use App\Services\Stock\{PurchaseOrderService, StockReportService, StockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Unit não herda TestCase::class + RefreshDatabase de Pest.php (só
// 'Feature' herda) — mesmo padrão dos demais testes de Unit/Stock.
uses(TestCase::class, RefreshDatabase::class);

/**
 * GAP fechado nesta revisão (Fase 4 original previa relatório e ficou de
 * fora) — App\Services\Stock\StockReportService.
 */
beforeEach(function () {
    $this->reports = app(StockReportService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

it('valuedInventory() soma qty×custo e classifica a curva ABC por contribuição acumulada', function () {
    // Distribuição desenhada pra cair EXATAMENTE nos cortes documentados
    // (≤80%=A, ≤95%=B, resto=C): 500(50%) + 300(50+30=80%) = corte de A;
    // +150(95%) = corte de B; +50(100%) = C. Cumulativo é sobre o item
    // JÁ SOMADO (Pareto clássico) — um único item que sozinho já supere
    // 80% do total cai em B/C, não em A "de graça" só por ser o maior;
    // por isso a distribuição aqui é espalhada, não um item dominante.
    $p1 = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'P1', 'unit' => 'un', 'active' => true]);
    $p2 = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'P2', 'unit' => 'un', 'active' => true]);
    $p3 = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'P3', 'unit' => 'un', 'active' => true]);
    $p4 = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'P4', 'unit' => 'un', 'active' => true]);

    app(StockService::class)->manualIn($p1, 1, 500.00);
    app(StockService::class)->manualIn($p2, 1, 300.00);
    app(StockService::class)->manualIn($p3, 1, 150.00);
    app(StockService::class)->manualIn($p4, 1, 50.00);

    $result = $this->reports->valuedInventory($this->entity->id);
    $byName = collect($result['items'])->keyBy('name');

    expect($result['total_value'])->toBe(1000.0)
        ->and($byName['P1']['abc_class'])->toBe('A') // cumulativo 50%
        ->and($byName['P2']['abc_class'])->toBe('A') // cumulativo 80%
        ->and($byName['P3']['abc_class'])->toBe('B') // cumulativo 95%
        ->and($byName['P4']['abc_class'])->toBe('C'); // cumulativo 100%
});

it('turnoverByProduct() soma saídas do período (exclui ajuste) e calcula a razão sobre o saldo atual', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 100, 10.00);
    app(StockService::class)->manualOut($product, 20, occurredAt: now()->subDays(2));
    app(StockService::class)->adjustToCountedQuantity($product, 90); // ajuste — NÃO conta como saída de giro

    $result = $this->reports->turnoverByProduct($this->entity->id, now()->subDays(5)->toDateString(), now()->toDateString());
    $row    = collect($result)->firstWhere('id', $product->id);

    expect($row['qty_out'])->toBe(20.0)
        ->and($row['qty_on_hand'])->toBe(90.0);
});

it('consumptionByProcedure() agrupa por execução de procedimento com custo total', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 5, 200.00);

    $doctorEntityUser = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $doctor           = Doctor::query()->create([
        'entity_user_id' => $doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
    $patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $record  = MedicalRecord::query()->create(['entity_id' => $this->entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    $catalogProcedure = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'P1', 'name' => 'Facectomia', 'active' => true]);
    $mrProcedure      = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $patient->id, 'medical_record_id' => $record->id,
        'procedure_id' => $catalogProcedure->id, 'doctor_id' => $doctor->id,
    ]);

    app(MedicalRecordProcedureExecutionService::class)->markDone(
        $mrProcedure,
        $doctorEntityUser,
        [['entity_product_id' => $product->id, 'quantity' => 1.0, 'stock_lot_id' => null]],
    );

    $result = $this->reports->consumptionByProcedure($this->entity->id, now()->subDay()->toDateString(), now()->addDay()->toDateString());

    expect($result)->toHaveCount(1)
        ->and($result[0]['procedure_name'])->toBe('Facectomia')
        ->and($result[0]['total_cost'])->toBe(200.0);
});

it('purchasesBySupplier() valoriza pelo recebido de verdade, não pelo total pedido', function () {
    $supplier = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Fornecedor Alfa', 'active' => true]);
    $product  = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto', 'unit' => 'un', 'active' => true]);

    $poService = app(PurchaseOrderService::class);
    $po        = $poService->send($poService->createDraft($this->entity->id, ['supplier_id' => $supplier->id], [
        ['entity_product_id' => $product->id, 'quantity_ordered' => 10, 'unit_cost' => 50.00], // pedido = 500
    ]));
    $item = $po->items->first();
    $poService->receive($po, [['purchase_order_item_id' => $item->id, 'quantity' => 4, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null]]); // recebido parcial = 200

    $result = $this->reports->purchasesBySupplier($this->entity->id, now()->subDay()->toDateString(), now()->addDay()->toDateString());

    expect($result)->toHaveCount(1)
        ->and($result[0]['supplier_name'])->toBe('Fornecedor Alfa')
        ->and($result[0]['total_spent'])->toBe(200.0); // NÃO 500 (o total pedido)
});
