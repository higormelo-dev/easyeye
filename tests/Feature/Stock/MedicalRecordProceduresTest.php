<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, MedicalRecordProcedureStatus, SubscriptionStatus};
use App\Models\{Doctor, Entity, EntityProduct, MedicalRecord, MedicalRecordProcedure, Patient, People, Plan, PlanFeature, Procedure, Subscription, User};
use App\Models\ProcedureProduct;
use App\Services\Stock\StockService;

/**
 * Solicitação/execução estruturada de procedimento (Fase 3) —
 * App\Http\Controllers\MedicalRecordProceduresController.
 *
 * Escrita (`store`/`mark-done`/`cancel`) é Gate::IssueReport — ClientRule::
 * Doctor ESTRITO (admin NÃO faz bypass aqui, diferente do RBAC granular de
 * Permission — ver AuthServiceProvider). Leitura (`index`) segue o grupo
 * admin/doctor/secretary da rota.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->doctorUserAccount = User::factory()->create();
    $this->doctorEntityUser  = createEntityUser($this->entity, $this->doctorUserAccount, ClientRule::Doctor->value);
    $this->doctor            = Doctor::query()->create([
        'entity_user_id' => $this->doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->secretaryUserAccount = User::factory()->create();
    $this->secretaryEntityUser  = createEntityUser($this->entity, $this->secretaryUserAccount, ClientRule::Secretary->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $this->catalogProcedure = Procedure::query()->create([
        'entity_id' => $this->entity->id, 'code' => 'PROC-1', 'name' => 'Facectomia', 'active' => true,
    ]);
});

// Nomes com sufixo Account (em vez de actingAsDoctor/actingAsSecretary
// genéricos) para não colidir com os helpers globais de mesmo nome já
// declarados em tests/Feature/EyeImages/EyeImageManualReportTest.php — Pest
// carrega todos os arquivos de tests/Feature no mesmo processo, e duas
// funções globais com o MESMO nome em arquivos diferentes quebram a suite
// inteira com "Cannot redeclare function" assim que os dois são carregados
// juntos (achado ao rodar a suite completa em vez de arquivos isolados).
function actingAsDoctorAccount($test)
{
    return $test->actingAs($test->doctorUserAccount)->withSession(panelSession($test->doctorEntityUser));
}

function actingAsSecretaryAccount($test)
{
    return $test->actingAs($test->secretaryUserAccount)->withSession(panelSession($test->secretaryEntityUser));
}

it('médico solicita procedimento — status nasce requested', function () {
    $res = actingAsDoctorAccount($this)->postJson(
        route('panel.patients.medicalrecords.medicalrecord-procedures.store', [$this->patient->id, $this->record->id]),
        ['procedure_id' => $this->catalogProcedure->id, 'eye' => 'right', 'solicitation_type' => 'rotina'],
    );

    $res->assertCreated();
    expect($res->json('data.status'))->toBe('requested')
        ->and(MedicalRecordProcedure::query()->count())->toBe(1);
});

it('[ACL] secretária NÃO consegue solicitar procedimento (403 — IssueReport é doctor estrito)', function () {
    actingAsSecretaryAccount($this)->postJson(
        route('panel.patients.medicalrecords.medicalrecord-procedures.store', [$this->patient->id, $this->record->id]),
        ['procedure_id' => $this->catalogProcedure->id],
    )->assertForbidden();

    expect(MedicalRecordProcedure::query()->count())->toBe(0);
});

it('médico marca executado + confirma consumo de material — baixa estoque de verdade', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 5, 800.00);

    $mrProcedure = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->catalogProcedure->id, 'doctor_id' => $this->doctor->id,
    ]);

    $res = actingAsDoctorAccount($this)->postJson(
        route('panel.patients.medicalrecord-procedures.mark-done', [$this->patient->id, $mrProcedure->id]),
        ['items' => [['entity_product_id' => $product->id, 'quantity' => 1]]],
    );

    $res->assertOk();
    expect($res->json('data.status'))->toBe('done')
        ->and((float) $product->fresh()->qty_on_hand)->toBe(4.0);
});

it('[REGRA DE NEGÓCIO] consumo com módulo de estoque desabilitado no plano é rejeitado (403)', function () {
    $entityNoModule = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planNoModule   = Plan::factory()->create(['active' => true]); // sem PlanFeature has_inventory_module
    Subscription::factory()->create([
        'entity_id' => $entityNoModule->id, 'plan_id' => $planNoModule->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $doctorAccount    = User::factory()->create();
    $doctorEntityUser = createEntityUser($entityNoModule, $doctorAccount, ClientRule::Doctor->value);
    $doctor           = Doctor::query()->create(['entity_user_id' => $doctorEntityUser->id, 'person_id' => People::factory()->create()->id, 'active' => true]);
    $patient          = Patient::factory()->create(['entity_id' => $entityNoModule->id]);
    $record           = MedicalRecord::query()->create(['entity_id' => $entityNoModule->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
    $procedure        = Procedure::query()->create(['entity_id' => $entityNoModule->id, 'code' => 'P', 'name' => 'Proc', 'active' => true]);
    $product          = EntityProduct::create(['entity_id' => $entityNoModule->id, 'name' => 'Item', 'unit' => 'un', 'active' => true]);

    $mrProcedure = MedicalRecordProcedure::create([
        'entity_id'    => $entityNoModule->id, 'patient_id' => $patient->id, 'medical_record_id' => $record->id,
        'procedure_id' => $procedure->id, 'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($doctorAccount)->withSession(panelSession($doctorEntityUser))
        ->postJson(
            route('panel.patients.medicalrecord-procedures.mark-done', [$patient->id, $mrProcedure->id]),
            ['items' => [['entity_product_id' => $product->id, 'quantity' => 1]]],
        )->assertForbidden();

    expect($mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('marcar executado SEM módulo de estoque habilitado ainda funciona quando não há itens de consumo', function () {
    $entityNoModule = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planNoModule   = Plan::factory()->create(['active' => true]);
    Subscription::factory()->create([
        'entity_id' => $entityNoModule->id, 'plan_id' => $planNoModule->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    $doctorAccount    = User::factory()->create();
    $doctorEntityUser = createEntityUser($entityNoModule, $doctorAccount, ClientRule::Doctor->value);
    $doctor           = Doctor::query()->create(['entity_user_id' => $doctorEntityUser->id, 'person_id' => People::factory()->create()->id, 'active' => true]);
    $patient          = Patient::factory()->create(['entity_id' => $entityNoModule->id]);
    $record           = MedicalRecord::query()->create(['entity_id' => $entityNoModule->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
    $procedure        = Procedure::query()->create(['entity_id' => $entityNoModule->id, 'code' => 'P', 'name' => 'Proc', 'active' => true]);

    $mrProcedure = MedicalRecordProcedure::create([
        'entity_id'    => $entityNoModule->id, 'patient_id' => $patient->id, 'medical_record_id' => $record->id,
        'procedure_id' => $procedure->id, 'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($doctorAccount)->withSession(panelSession($doctorEntityUser))
        ->postJson(route('panel.patients.medicalrecord-procedures.mark-done', [$patient->id, $mrProcedure->id]), [])
        ->assertOk();

    expect($mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Done);
});

it('médico cancela solicitação', function () {
    $mrProcedure = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->catalogProcedure->id, 'doctor_id' => $this->doctor->id,
    ]);

    actingAsDoctorAccount($this)->postJson(
        route('panel.patients.medicalrecord-procedures.cancel', [$this->patient->id, $mrProcedure->id]),
        ['notes' => 'Paciente desistiu'],
    )->assertOk();

    expect($mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Cancelled);
});

it('[ISOLAMENTO] admin/médico de outra clínica recebe 404 ao tentar marcar executado procedimento alheio', function () {
    $mrProcedure = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->catalogProcedure->id, 'doctor_id' => $this->doctor->id,
    ]);

    $otherEntity           = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherDoctorAccount    = User::factory()->create();
    $otherDoctorEntityUser = createEntityUser($otherEntity, $otherDoctorAccount, ClientRule::Doctor->value);

    $this->actingAs($otherDoctorAccount)->withSession(panelSession($otherDoctorEntityUser))
        ->postJson(route('panel.patients.medicalrecord-procedures.mark-done', [$this->patient->id, $mrProcedure->id]), [])
        ->assertStatus(404);

    expect($mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('bom() retorna a lista de materiais padrão do procedimento', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    ProcedureProduct::create([
        'entity_id' => $this->entity->id, 'procedure_id' => $this->catalogProcedure->id, 'entity_product_id' => $product->id, 'quantity' => 1,
    ]);

    $res = actingAsDoctorAccount($this)->getJson(route('panel.procedures.bom', $this->catalogProcedure->id));

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.entity_product_id'))->toBe($product->id);
});

it('[GAP] bom() inclui lotes disponíveis pra item requires_lot=true — doctor não passa por stock.manage', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM rastreável', 'unit' => 'un', 'active' => true, 'requires_lot' => true,
    ]);
    ProcedureProduct::create([
        'entity_id' => $this->entity->id, 'procedure_id' => $this->catalogProcedure->id, 'entity_product_id' => $lotProduct->id, 'quantity' => 1,
    ]);
    app(StockService::class)->manualIn($lotProduct, 5, 100.00, lot: app(StockService::class)->findOrCreateLot($lotProduct, 'L1'));

    // Doctor NÃO tem permission stock.manage — se bom() dependesse de
    // ProductLotsController (atrás de stock.manage) isso 403aria; aqui deve
    // vir OK porque bom() é doctor-acessível de propósito.
    $res = actingAsDoctorAccount($this)->getJson(route('panel.procedures.bom', $this->catalogProcedure->id));

    $res->assertOk();
    expect($res->json('data.0.requires_lot'))->toBeTrue()
        ->and($res->json('data.0.lots'))->toHaveCount(1)
        ->and($res->json('data.0.lots.0.lot_number'))->toBe('L1');
});

it('médico solicita um procedimento GLOBAL (entity_id null — catálogo compartilhado)', function () {
    $globalProcedure = Procedure::query()->create(['entity_id' => null, 'code' => 'GLOBAL-1', 'name' => 'Consulta padrão', 'active' => true]);

    $res = actingAsDoctorAccount($this)->postJson(
        route('panel.patients.medicalrecords.medicalrecord-procedures.store', [$this->patient->id, $this->record->id]),
        ['procedure_id' => $globalProcedure->id],
    );

    $res->assertCreated();
});

it('bom() de procedimento global NÃO retorna a BOM cadastrada por outra clínica', function () {
    $globalProcedure = Procedure::query()->create(['entity_id' => null, 'code' => 'GLOBAL-1', 'name' => 'Consulta padrão', 'active' => true]);

    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProduct = EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Item de outra clínica', 'unit' => 'un', 'active' => true]);
    ProcedureProduct::create([
        'entity_id' => $otherEntity->id, 'procedure_id' => $globalProcedure->id, 'entity_product_id' => $otherProduct->id, 'quantity' => 1,
    ]);

    $res = actingAsDoctorAccount($this)->getJson(route('panel.procedures.bom', $globalProcedure->id));

    $res->assertOk();
    expect($res->json('data'))->toBe([]);
});
