<?php

declare(strict_types=1);

use App\Enums\{ClientRule, MedicalRecordProcedureStatus};
use App\Exceptions\{InsufficientStockException, LotRequiredException};
use App\Models\{Doctor, Entity, EntityProduct, MedicalRecord, MedicalRecordProcedure, Patient, People, Procedure, StockMovement, User};
use App\Services\MedicalRecordProcedureExecutionService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Unit não herda TestCase::class + RefreshDatabase de Pest.php (só
// 'Feature' herda) — mesmo padrão de tests/Unit/Stock/StockServiceTest.php.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Fase 3 — estoque ↔ prontuário. Testa a transição de estado +
 * atomicidade do consumo direto no service, sem HTTP/ACL (isso fica em
 * tests/Feature/Stock/MedicalRecordProceduresTest.php).
 */
beforeEach(function () {
    $this->service = app(MedicalRecordProcedureExecutionService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUser = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor     = Doctor::query()->create([
        'entity_user_id' => $this->doctorUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $this->catalogProcedure = Procedure::query()->create([
        'entity_id' => $this->entity->id, 'code' => 'PROC-1', 'name' => 'Facectomia', 'active' => true,
    ]);

    $this->mrProcedure = MedicalRecordProcedure::create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $this->record->id,
        'procedure_id'      => $this->catalogProcedure->id,
        'doctor_id'         => $this->doctor->id,
        'eye'               => 'right',
    ]);

    $this->product = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true,
    ]);
});

it('marca executado sem itens de consumo (procedimento sem material físico)', function () {
    $updated = $this->service->markDone($this->mrProcedure, $this->doctorUser, [], 'Sem intercorrências.');

    expect($updated->status)->toBe(MedicalRecordProcedureStatus::Done)
        ->and($updated->executed_at)->not->toBeNull()
        ->and((string) $updated->executed_by)->toBe((string) $this->doctorUser->id)
        ->and($updated->notes)->toBe('Sem intercorrências.');
});

it('marca executado E baixa estoque do(s) item(ns) confirmado(s)', function () {
    app(StockService::class)->manualIn($this->product, 10, 500.00);

    $updated = $this->service->markDone($this->mrProcedure, $this->doctorUser, [
        ['entity_product_id' => $this->product->id, 'quantity' => 1.0, 'stock_lot_id' => null],
    ]);

    expect($updated->status)->toBe(MedicalRecordProcedureStatus::Done)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(9.0);

    $movement = StockMovement::query()
        ->where('reference_type', MedicalRecordProcedure::class)
        ->where('reference_id', $this->mrProcedure->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type->value)->toBe('consumption_out')
        ->and((float) $movement->quantity)->toBe(1.0);
});

it('[ATOMICIDADE] se um item falhar por saldo insuficiente, NENHUM item baixa e o procedimento continua requested', function () {
    $productB = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Viscoelástico', 'unit' => 'un', 'active' => true]);

    app(StockService::class)->manualIn($this->product, 10, 500.00); // saldo suficiente
    // productB fica com saldo ZERO de propósito — a 2ª baixa vai falhar.

    expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser, [
        ['entity_product_id' => $this->product->id, 'quantity' => 1.0, 'stock_lot_id' => null],
        ['entity_product_id' => $productB->id, 'quantity' => 1.0, 'stock_lot_id' => null],
    ]))->toThrow(InsufficientStockException::class);

    // rollback total: item A (que teria sucesso sozinho) também foi desfeito.
    expect((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $productB->fresh()->qty_on_hand)->toBe(0.0)
        ->and($this->mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Requested)
        ->and(StockMovement::query()->where('reference_type', MedicalRecordProcedure::class)->count())->toBe(0);
});

it('[REGRA DE NEGÓCIO] produto requires_lot=true sem lote informado lança LotRequiredException (via service, não só HTTP)', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);
    app(StockService::class)->manualIn($lotProduct, 5, 300.00, lot: app(StockService::class)->findOrCreateLot($lotProduct, 'L1', now()->addYear()));

    expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser, [
        ['entity_product_id' => $lotProduct->id, 'quantity' => 1.0, 'stock_lot_id' => null],
    ]))->toThrow(LotRequiredException::class);

    expect($this->mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('não permite marcar executado um procedimento já cancelado', function () {
    $this->service->cancel($this->mrProcedure);

    expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser))
        ->toThrow(InvalidArgumentException::class);
});

it('não permite cancelar um procedimento já executado', function () {
    $this->service->markDone($this->mrProcedure, $this->doctorUser);

    expect(fn () => $this->service->cancel($this->mrProcedure))
        ->toThrow(InvalidArgumentException::class);
});
