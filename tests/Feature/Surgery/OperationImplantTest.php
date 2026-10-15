<?php

declare(strict_types=1);

require_once __DIR__ . '/OperationFixtures.php';

use App\Enums\StockMovementType;
use App\Enums\Surgery\{ImplantStatus, SurgicalCaseStatus};
use App\Models\{EntityProduct, StockMovement};
use App\Models\Surgery\SurgicalImplant;
use App\Services\Stock\StockService;
use Carbon\Carbon;

/*
 * Fase 4 / M4 — implante (RDC 556/2021): baixa de estoque + registro congelado
 * na MESMA transação; lote, validade e série conferidos; estorno na anulação.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
});

// ── Registro ────────────────────────────────────────────────────────────────

it('registra a LIO: baixa o lote, congela os dados e liga a baixa ao implante', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);

    $response = sopStore($this, $case, sopImplantPayload($product, $lot))->assertCreated();

    $implant = SurgicalImplant::query()->findOrFail($response->json('implant_id'));

    expect($implant->status)->toBe(ImplantStatus::Active)
        ->and($implant->kind->value)->toBe('iol')
        ->and($implant->product_name)->toBe('LIO Monofocal')
        ->and($implant->manufacturer)->toBe('ACME')
        ->and($implant->lot_number)->toBe('L-001')
        ->and($implant->serial_number)->toBe('SN-0001')
        ->and($implant->expiry_date->toDateString())->toBe(now()->addMonths(6)->toDateString())
        ->and($implant->anvisa_registration)->toBe('80123456789')
        ->and((float) $implant->power)->toBe(21.5)
        ->and((string) $implant->patient_id)->toBe((string) $this->w->patient->id)
        ->and($implant->content_hash)->toHaveLength(64)
        ->and((string) $implant->registered_by_doctor_id)->toBe((string) $this->w->doctor->id);

    $movement = StockMovement::query()->findOrFail($implant->stock_movement_id);

    expect($movement->type)->toBe(StockMovementType::ConsumptionOut)
        ->and($movement->reference_type)->toBe(SurgicalImplant::class)
        ->and((string) $movement->reference_id)->toBe((string) $implant->id)
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(4.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(4.0);
});

it('o registro é congelado: editar lote/validade no estoque depois não muda o implante', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);

    $implant = SurgicalImplant::query()->findOrFail(sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id'));

    $lot->update(['lot_number' => 'LOTE-CORRIGIDO', 'expiry_date' => '2030-01-01']);
    $product->update(['name' => 'Outro nome', 'manufacturer' => 'OUTRA']);

    $implant->refresh();

    expect($implant->lot_number)->toBe('L-001')
        ->and($implant->expiry_date->toDateString())->toBe(now()->addMonths(6)->toDateString())
        ->and($implant->product_name)->toBe('LIO Monofocal')
        ->and($implant->manufacturer)->toBe('ACME');
});

it('o implante registrado é imutável e não se apaga', function () {
    [$product, $lot] = sopIol($this->w);
    $implant         = SurgicalImplant::query()->findOrFail(sopStore($this, sopCase($this->w), sopImplantPayload($product, $lot))->json('implant_id'));

    expect(fn () => $implant->update(['serial_number' => 'ADULTERADO']))->toThrow(LogicException::class);
    expect(fn () => $implant->update(['lot_number' => 'X']))->toThrow(LogicException::class);
    expect(fn () => $implant->delete())->toThrow(LogicException::class);
    expect($implant->fresh()->serial_number)->toBe('SN-0001');
});

it('recusa lote vencido sem baixar nada e sem criar implante', function () {
    [$product] = sopIol($this->w);
    $expired   = sopLot($product, 'VENC', '2026-10-01', 3);
    $case      = sopCase($this->w);

    $before = (float) $product->fresh()->qty_on_hand;

    sopStore($this, $case, sopImplantPayload($product, $expired))->assertStatus(422);

    expect(SurgicalImplant::query()->count())->toBe(0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe($before)
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionOut->value)->count())->toBe(0);
});

it('recusa lote sem saldo (nada de saldo negativo)', function () {
    [$product] = sopIol($this->w, qty: 0);
    $emptyLot  = sopLot($product, 'ZERO', '+1 year', 0);
    $case      = sopCase($this->w);

    sopStore($this, $case, sopImplantPayload($product, $emptyLot))->assertStatus(422);

    expect(SurgicalImplant::query()->count())->toBe(0)->and((float) $emptyLot->fresh()->qty_on_hand)->toBe(0.0);
});

it('lote obrigatório, lote de outro produto, produto de outra clínica e lote sem validade', function () {
    [$product, $lot]    = sopIol($this->w);
    [$other, $otherLot] = sopIol($this->w, 'L-OUTRO', name: 'LIO B');
    $case               = sopCase($this->w);

    sopStore($this, $case, sopImplantPayload($product, null))->assertStatus(422);
    sopStore($this, $case, sopImplantPayload($product, $otherLot))->assertStatus(422);

    $foreign = EntityProduct::create(['entity_id' => $this->w->other->id, 'name' => 'Lente alheia', 'unit' => 'un', 'is_opm' => true, 'active' => true]);
    sopStore($this, $case, sopImplantPayload($foreign, null))->assertStatus(422);

    $noExpiry = app(StockService::class)->findOrCreateLot($product, 'SEM-VAL');
    app(StockService::class)->manualIn($product, 2, 10.0, lot: $noExpiry);
    sopStore($this, $case, sopImplantPayload($product, $noExpiry, ['serial_number' => 'SN-2']))->assertStatus(422);

    expect(SurgicalImplant::query()->count())->toBe(0);
});

it('LIO exige série, potência e registro ANVISA; o ANVISA vem do pedido de OPME quando conhecido', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);

    sopStore($this, $case, sopImplantPayload($product, $lot, ['serial_number' => '']))->assertStatus(422);
    sopStore($this, $case, sopImplantPayload($product, $lot, ['power' => null]))->assertStatus(422);
    sopStore($this, $case, sopImplantPayload($product, $lot, ['anvisa_registration' => '']))->assertStatus(422);

    // Com o produto no pedido de OPME do caso (ANVISA conhecido), o registro não precisa redigitar.
    $case->materials()->create([
        'entity_id' => $this->w->entity->id, 'entity_product_id' => $product->id, 'description' => 'LIO',
        'quantity'  => 1, 'anvisa_registration' => '80999888777',
    ]);

    $response = sopStore($this, $case, sopImplantPayload($product, $lot, ['anvisa_registration' => '']))->assertCreated();

    expect(SurgicalImplant::query()->findOrFail($response->json('implant_id'))->anvisa_registration)->toBe('80999888777');
});

it('a série é única entre implantes vigentes, sem diferenciar maiúsculas, e a anulação a libera', function () {
    [$product, $lot] = sopIol($this->w);
    $caseA           = sopCase($this->w);
    $caseB           = sopCase($this->w, ['title' => 'Outra']);

    $first = sopStore($this, $caseA, sopImplantPayload($product, $lot, ['serial_number' => 'sn-77']))->assertCreated();

    sopStore($this, $caseB, sopImplantPayload($product, $lot, ['serial_number' => 'SN-77']))->assertStatus(422);
    expect((float) $lot->fresh()->qty_on_hand)->toBe(4.0);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$caseA, $first->json('implant_id')]), [
        'reason' => 'Registrado por engano', 'return_to_stock' => true,
    ])->assertOk();

    sopStore($this, $caseB, sopImplantPayload($product, $lot, ['serial_number' => 'SN-77']))->assertCreated();
});

it('o olho do implante precisa conferir com o olho da cirurgia', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w, ['eye' => 'oe']);

    sopStore($this, $case, sopImplantPayload($product, $lot, ['eye' => 'od']))->assertStatus(422);
    sopStore($this, $case, sopImplantPayload($product, $lot, ['eye' => 'oe']))->assertCreated();
});

it('material OPM sem série: aceita quantidade e baixa a quantidade', function () {
    [$ring, $lot] = sopOpm($this->w, 5);
    $case         = sopCase($this->w);

    $response = sopStore($this, $case, sopImplantPayload($ring, $lot, ['serial_number' => '', 'power' => null, 'quantity' => 2]))->assertCreated();

    $implant = SurgicalImplant::query()->findOrFail($response->json('implant_id'));

    expect($implant->kind->value)->toBe('other')->and($implant->quantity)->toBe(2)->and((float) $lot->fresh()->qty_on_hand)->toBe(3.0);
});

it('material com requires_serial exige a série', function () {
    [$ring, $lot] = sopOpm($this->w, 5, serial: true);

    sopStore($this, sopCase($this->w), sopImplantPayload($ring, $lot, ['serial_number' => '', 'power' => null]))->assertStatus(422);
});

it('só registra com a cirurgia agendada ou realizada', function () {
    [$product, $lot] = sopIol($this->w);

    foreach ([SurgicalCaseStatus::Authorized, SurgicalCaseStatus::Discharged, SurgicalCaseStatus::Cancelled, SurgicalCaseStatus::Suspended] as $state) {
        $case = sopCase($this->w, state: ['status' => $state]);
        sopStore($this, $case, sopImplantPayload($product, $lot, ['serial_number' => 'S-' . $state->value]))->assertStatus(409);
    }

    $performed = sopCase($this->w, state: ['status' => SurgicalCaseStatus::Performed]);
    sopStore($this, $performed, sopImplantPayload($product, $lot, ['serial_number' => 'S-OK']))->assertCreated();
});

it('perfil: secretária e financeiro não registram; médico de fora da equipe também não', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);

    sopStore($this, $case, sopImplantPayload($product, $lot), 'secretary')->assertForbidden();
    sopStore($this, $case, sopImplantPayload($product, $lot), 'financial')->assertForbidden();

    sopOtherDoctor($this->w)->postJson(route('panel.surgery.operation.implants.store', $case), sopImplantPayload($product, $lot))->assertForbidden();

    expect(SurgicalImplant::query()->count())->toBe(0);
});

it('médico da equipe (auxiliar) registra o implante', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);

    $assistant = createDoctorForEntity($this->w->entity);
    $case->team()->create(['entity_id' => $this->w->entity->id, 'role' => 'assistant', 'doctor_id' => $assistant->id, 'name' => 'AUX']);

    test()->actingAs($assistant->entityUser->user)->withSession(panelSession($assistant->entityUser))
        ->postJson(route('panel.surgery.operation.implants.store', $case), sopImplantPayload($product, $lot))
        ->assertCreated();
});

it('caso de outra clínica é 404 e não baixa o estoque dela', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $w2              = surgeryWorld();

    surgeryAs($w2, 'doctor')->postJson(route('panel.surgery.operation.implants.store', $case), sopImplantPayload($product, $lot))->assertNotFound();
    expect((float) $lot->fresh()->qty_on_hand)->toBe(5.0);
});

// ── Unidade de fora do estoque (consignado) ─────────────────────────────────

function sopExternal(array $over = []): array
{
    return array_merge([
        'source'              => 'external',
        'kind'                => 'iol',
        'eye'                 => 'od',
        'product_name'        => 'LIO Consignada',
        'manufacturer'        => 'HOSPITALCO',
        'model_reference'     => 'HC-200',
        'lot_number'          => 'EXT-9',
        'expiry_date'         => '2027-06-30',
        'serial_number'       => 'EXT-SN-1',
        'power'               => 20.0,
        'anvisa_registration' => '80111222333',
    ], $over);
}

it('registra a unidade consignada sem mexer em estoque, com a mesma série única', function () {
    $case  = sopCase($this->w);
    $other = sopCase($this->w, ['title' => 'B']);

    $response = sopStore($this, $case, sopExternal())->assertCreated();
    $implant  = SurgicalImplant::query()->findOrFail($response->json('implant_id'));

    expect($implant->stock_source->value)->toBe('external')
        ->and($implant->stock_movement_id)->toBeNull()
        ->and($implant->entity_product_id)->toBeNull()
        ->and(StockMovement::query()->count())->toBe(0);

    sopStore($this, $other, sopExternal())->assertStatus(422);
    // Outro modelo do mesmo fabricante pode ter a mesma série.
    sopStore($this, $other, sopExternal(['model_reference' => 'HC-300']))->assertCreated();
});

it('consignado: dados incompletos, vencido e LIO sem série são recusados', function () {
    $case = sopCase($this->w);

    sopStore($this, $case, sopExternal(['lot_number' => '']))->assertStatus(422);
    sopStore($this, $case, sopExternal(['expiry_date' => '2026-10-14']))->assertStatus(422);
    sopStore($this, $case, sopExternal(['serial_number' => '']))->assertStatus(422);
    sopStore($this, $case, sopExternal(['anvisa_registration' => '']))->assertStatus(422);

    expect(SurgicalImplant::query()->count())->toBe(0);
});

// ── Anulação ────────────────────────────────────────────────────────────────

function sopVoid(object $t, $case, string $implantId, array $body)
{
    return surgeryAs($t->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$case, $implantId]), $body);
}

it('anula devolvendo ao estoque: estorna o consumo e mantém a linha do implante', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    sopVoid($this, $case, $id, ['reason' => 'Lente caiu antes de abrir', 'return_to_stock' => true])->assertOk();

    $implant = SurgicalImplant::query()->findOrFail($id);

    expect($implant->status)->toBe(ImplantStatus::Voided)
        ->and($implant->void_stock_action)->toBe('returned')
        ->and($implant->void_reason)->toBe('Lente caiu antes de abrir')
        ->and($implant->loss_movement_id)->toBeNull()
        ->and(StockMovement::query()->findOrFail($implant->reversal_movement_id)->type)->toBe(StockMovementType::ConsumptionReversal)
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(5.0)
        // O consumo original e o implante continuam no histórico.
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionOut->value)->count())->toBe(1);
});

it('anula como perda: estorna o consumo e lança a perda (sobra de estoque não volta)', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    sopVoid($this, $case, $id, ['reason' => 'Contaminada na mesa', 'return_to_stock' => false])->assertOk();

    $implant = SurgicalImplant::query()->findOrFail($id);

    expect($implant->void_stock_action)->toBe('lost')
        ->and(StockMovement::query()->findOrFail($implant->loss_movement_id)->type)->toBe(StockMovementType::Loss)
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(4.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(4.0);
});

it('anulação exige motivo, vale uma vez e não mexe em estoque na segunda tentativa', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    sopVoid($this, $case, $id, ['reason' => ''])->assertStatus(422);
    sopVoid($this, $case, $id, ['reason' => 'Erro de registro', 'return_to_stock' => true])->assertOk();
    sopVoid($this, $case, $id, ['reason' => 'Erro de registro', 'return_to_stock' => true])->assertStatus(409);

    expect((float) $lot->fresh()->qty_on_hand)->toBe(5.0)
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionReversal->value)->count())->toBe(1);
});

it('implante consignado anulado não mexe em estoque', function () {
    $case = sopCase($this->w);
    $id   = sopStore($this, $case, sopExternal())->json('implant_id');

    sopVoid($this, $case, $id, ['reason' => 'Unidade trocada'])->assertOk();

    expect(SurgicalImplant::query()->findOrFail($id)->void_stock_action)->toBeNull()->and(StockMovement::query()->count())->toBe(0);
});

it('não anula depois da alta, nem implante de outro caso, nem como secretária', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $other           = sopCase($this->w, ['title' => 'Outro']);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    sopVoid($this, $other, $id, ['reason' => 'IDOR'])->assertNotFound();

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.operation.implants.void', [$case, $id]), ['reason' => 'Sem permissão'])->assertForbidden();

    $case->forceFill(['status' => SurgicalCaseStatus::Discharged])->save();
    sopVoid($this, $case, $id, ['reason' => 'Tarde demais'])->assertStatus(409);

    expect(SurgicalImplant::query()->findOrFail($id)->status)->toBe(ImplantStatus::Active);
});

// ── Busca de produtos (FEFO) ────────────────────────────────────────────────

it('a busca lista só produto implantável da clínica, com lotes em ordem FEFO e sem vencidos/zerados', function () {
    [$product, $late] = sopIol($this->w, 'L-TARDE', '+1 year');
    $soon             = sopLot($product, 'L-CEDO', '+2 months', 2);
    sopLot($product, 'L-VENCIDO', '2026-09-01', 3);
    sopLot($product, 'L-ZERADO', '+3 months', 0);

    EntityProduct::create(['entity_id' => $this->w->entity->id, 'name' => 'LIO comum de balcão', 'unit' => 'un', 'active' => true]);
    EntityProduct::create(['entity_id' => $this->w->other->id, 'name' => 'LIO Monofocal alheia', 'unit' => 'un', 'is_opm' => true, 'active' => true]);

    $case = sopCase($this->w);

    $response = surgeryAs($this->w, 'doctor')->getJson(route('panel.surgery.operation.products', $case) . '?q=LIO')->assertOk();

    $names = collect($response->json())->pluck('name')->all();
    expect($names)->toBe(['LIO Monofocal']);

    $lots = collect($response->json('0.lots'));
    expect($lots->pluck('lot_number')->all())->toBe(['L-CEDO', 'L-TARDE'])
        ->and($lots->first()['suggested'])->toBeTrue()
        ->and($response->json('0.kind'))->toBe('iol')
        ->and($response->json('0.requires_serial'))->toBeTrue();

    surgeryAs($this->w, 'secretary')->getJson(route('panel.surgery.operation.products', $case) . '?q=LIO')->assertForbidden();
});
