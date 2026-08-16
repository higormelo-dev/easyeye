<?php

use App\Enums\ClientRule;
use App\Models\{AuditLog, Cid10Code, Entity, EntityCustomDiagnosis, EntityDiagnosisUsage, Patient, PatientExam, User};

/**
 * "Diagnóstico do exame" (Gerenciador de Imagens): registro de diagnóstico
 * CID-10/customizado em patient_exams.diagnosis_cids via ExamDiagnosisController
 * (rotas eye-images.diagnoses.*) — ver DiagnosisCatalogService.
 *
 * Setup espelha tests/Feature/EyeImages/EyeImagesFiltersTest.php e
 * tests/Feature/AI/ApproveWithDiagnosisTest.php (mesmas factories/helpers de
 * Pest.php: createEntityUser, panelSession).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctor           = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctor, ClientRule::Doctor->value);

    $this->secretary           = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretary, ClientRule::Secretary->value);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
});

function updateExamDiagnosis($test, User $actingUser, $entityUser, PatientExam $exam, array $payload)
{
    return $test->actingAs($actingUser)
        ->withSession(panelSession($entityUser))
        ->putJson(route('panel.eye-images.exams.diagnosis.update', $exam), $payload);
}

function searchExamDiagnoses($test, User $actingUser, $entityUser, array $query = [])
{
    return $test->actingAs($actingUser)
        ->withSession(panelSession($entityUser))
        ->getJson(route('panel.eye-images.diagnoses.search', $query));
}

function storeCustomDiagnosis($test, User $actingUser, $entityUser, array $payload)
{
    return $test->actingAs($actingUser)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.eye-images.diagnoses.store'), $payload);
}

function filterEyeImagesByDiagnosis($test, User $actingUser, $entityUser, array $query = [])
{
    return $test->actingAs($actingUser)
        ->withSession(panelSession($entityUser))
        ->getJson(route('panel.eye-images.search', $query));
}

it('médico registra um diagnóstico CID-10 no exame', function () {
    $cid = Cid10Code::create(['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']);

    updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $this->exam, [
        'diagnoses' => [
            ['code' => $cid->code, 'description' => $cid->description],
        ],
    ])->assertOk();

    $cids = $this->exam->fresh()->diagnosis_cids;

    expect($cids)->toHaveCount(1);
    expect($cids[0])->toMatchArray([
        'code'        => 'H40.1',
        'description' => 'Glaucoma primário de ângulo aberto',
        'is_primary'  => true,
    ]);
});

it('médico registra múltiplos diagnósticos com um marcado is_primary', function () {
    Cid10Code::create(['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']);
    Cid10Code::create(['code' => 'H35.0', 'description' => 'Retinopatia de fundo']);

    updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $this->exam, [
        'diagnoses' => [
            ['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto', 'is_primary' => false],
            ['code' => 'H35.0', 'description' => 'Retinopatia de fundo', 'is_primary' => true],
        ],
    ])->assertOk();

    $cids = $this->exam->fresh()->diagnosis_cids;

    expect($cids)->toHaveCount(2);
    expect(collect($cids)->where('is_primary', true))->toHaveCount(1);
    expect(collect($cids)->firstWhere('is_primary', true)['code'])->toBe('H35.0');
});

it('secretary (não-doctor) recebe 403 ao tentar salvar diagnóstico — bloqueado pelo middleware entity.role', function () {
    updateExamDiagnosis($this, $this->secretary, $this->secretaryEntityUser, $this->exam, [
        'diagnoses' => [['code' => 'H40.1', 'description' => 'Glaucoma']],
    ])->assertForbidden();

    expect($this->exam->fresh()->diagnosis_cids)->toBeNull();
});

it('admin (não-doctor) recebe 403 ao tentar salvar diagnóstico — bloqueado pelo Gate IssueReport exclusivo de doctor', function () {
    updateExamDiagnosis($this, $this->admin, $this->adminEntityUser, $this->exam, [
        'diagnoses' => [['code' => 'H40.1', 'description' => 'Glaucoma']],
    ])->assertForbidden();

    expect($this->exam->fresh()->diagnosis_cids)->toBeNull();
});

it('payload com código CID-10 duplicado no mesmo request retorna 422', function () {
    Cid10Code::create(['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']);

    $res = updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $this->exam, [
        'diagnoses' => [
            ['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto'],
            ['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto'],
        ],
    ]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('diagnoses');
    expect($this->exam->fresh()->diagnosis_cids)->toBeNull();
});

it('cadastro de diagnóstico customizado: primeira vez cria, segunda vez (case/espaços diferentes) reaproveita', function () {
    $res1 = storeCustomDiagnosis($this, $this->doctor, $this->doctorEntityUser, ['name' => 'Blefarite crônica']);
    $res1->assertCreated();
    $id1 = $res1->json('data.custom_diagnosis_id');

    $res2 = storeCustomDiagnosis($this, $this->doctor, $this->doctorEntityUser, ['name' => '  blefarite CRÔNICA  ']);
    $res2->assertCreated();
    $id2 = $res2->json('data.custom_diagnosis_id');

    expect($id1)->not->toBeNull();
    expect($id2)->toBe($id1);
    expect(EntityCustomDiagnosis::where('entity_id', $this->entity->id)->count())->toBe(1);
});

it('busca combina CID-10 + customizados da entidade e isola customizados de outra entidade', function () {
    Cid10Code::create(['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']);

    EntityCustomDiagnosis::create([
        'entity_id'       => $this->entity->id,
        'name'            => 'Glaucoma atípico customizado',
        'normalized_name' => 'glaucoma atípico customizado',
    ]);

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    EntityCustomDiagnosis::create([
        'entity_id'       => $otherEntity->id,
        'name'            => 'Glaucoma outra clínica',
        'normalized_name' => 'glaucoma outra clínica',
    ]);

    $res = searchExamDiagnoses($this, $this->doctor, $this->doctorEntityUser, ['q' => 'glaucoma'])->assertOk();

    $descriptions = collect($res->json('data'))->pluck('description');

    expect($descriptions)->toContain('Glaucoma primário de ângulo aberto')
        ->and($descriptions)->toContain('Glaucoma atípico customizado')
        ->and($descriptions)->not->toContain('Glaucoma outra clínica');
});

it('diagnóstico usado mais vezes aparece antes de outro usado menos (ordenação por uso)', function () {
    $popular = Cid10Code::create(['code' => 'P01', 'description' => 'Diagnostico Popular Teste']);
    $rare    = Cid10Code::create(['code' => 'R01', 'description' => 'Diagnostico Raro Teste']);

    $examA = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $examB = PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    foreach (range(1, 3) as $_) {
        updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $examA, [
            'diagnoses' => [['code' => $popular->code, 'description' => $popular->description]],
        ])->assertOk();
    }

    updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $examB, [
        'diagnoses' => [['code' => $rare->code, 'description' => $rare->description]],
    ])->assertOk();

    // q vazio → mostUsed(), ranqueado direto por use_count desc.
    $res = searchExamDiagnoses($this, $this->doctor, $this->doctorEntityUser)->assertOk();

    $codes        = collect($res->json('data'))->pluck('code')->values()->all();
    $popularIndex = array_search('P01', $codes, true);
    $rareIndex    = array_search('R01', $codes, true);

    expect($popularIndex)->not->toBeFalse();
    expect($rareIndex)->not->toBeFalse();
    expect($popularIndex)->toBeLessThan($rareIndex);
});

it('filtro customDiagnosisId no Gerenciador de Imagens retorna só os exames com aquele diagnóstico customizado', function () {
    $target = EntityCustomDiagnosis::create([
        'entity_id'       => $this->entity->id,
        'name'            => 'Olho seco atípico',
        'normalized_name' => 'olho seco atípico',
    ]);
    $other = EntityCustomDiagnosis::create([
        'entity_id'       => $this->entity->id,
        'name'            => 'Outro diagnóstico',
        'normalized_name' => 'outro diagnóstico',
    ]);

    $matching = PatientExam::factory()->create([
        'patient_id'     => $this->patient->id,
        'diagnosis_cids' => [['custom_diagnosis_id' => (string) $target->id, 'description' => $target->name]],
    ]);
    PatientExam::factory()->create([
        'patient_id'     => $this->patient->id,
        'diagnosis_cids' => [['custom_diagnosis_id' => (string) $other->id, 'description' => $other->name]],
    ]);

    $res = filterEyeImagesByDiagnosis($this, $this->doctor, $this->doctorEntityUser, [
        'custom_diagnosis_id' => $target->id,
    ])->assertOk();

    $examIds = collect($res->json('patients.0.exams'))->pluck('id');
    expect($examIds->all())->toBe([(string) $matching->id]);
});

it('custom_diagnosis_id de outra entidade é rejeitado (422, isolamento multi-tenant) e não vaza o diagnóstico da outra clínica', function () {
    $otherEntity    = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherDiagnosis = EntityCustomDiagnosis::create([
        'entity_id'       => $otherEntity->id,
        'name'            => 'Diagnóstico sigiloso da outra clínica',
        'normalized_name' => 'diagnóstico sigiloso da outra clínica',
    ]);

    $res = updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $this->exam, [
        'diagnoses' => [
            ['custom_diagnosis_id' => (string) $otherDiagnosis->id, 'description' => 'texto qualquer', 'is_primary' => true],
        ],
    ]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('diagnoses.0.custom_diagnosis_id');

    expect($this->exam->fresh()->diagnosis_cids)->toBeNull();
    // Garante que nenhum contador de uso cross-tenant foi criado — o vetor
    // de vazamento que o teste protege é justamente entity_diagnosis_usages
    // apontando pra um entity_custom_diagnosis de outra entidade, exposto
    // depois via mostUsed()/customDiagnosis() sem escopo.
    expect(EntityDiagnosisUsage::where('entity_id', $this->entity->id)->count())->toBe(0);
});

it('audit_logs recebe entrada quando diagnosis_cids do exame é atualizado', function () {
    Cid10Code::create(['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']);

    updateExamDiagnosis($this, $this->doctor, $this->doctorEntityUser, $this->exam, [
        'diagnoses' => [['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']],
    ])->assertOk();

    $log = AuditLog::where('auditable_type', PatientExam::class)
        ->where('auditable_id', $this->exam->id)
        ->where('event', 'updated')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($this->doctor->id);
    expect($log->new_values)->toHaveKey('diagnosis_cids');
});
