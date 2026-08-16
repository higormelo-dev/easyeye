<?php

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\ClientRule;
use App\Models\{Entity, EntityIntegrator, EntityIntegratorEquipment, EntityUserIntegrator, ExamType, Patient, PatientExam, People, User};
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->authUser       = User::factory()->create();
    $this->authEntityUser = createEntityUser($this->entity, $this->authUser, ClientRule::Doctor->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
});

function searchEyeImages($test, array $query = [])
{
    return $test->actingAs($test->authUser)
        ->withSession(panelSession($test->authEntityUser))
        ->getJson(route('panel.eye-images.search', $query));
}

/**
 * Cadeia completa equipment.integrator.user.entity_id — entity_integrator_equipments
 * não tem entity_id direto (ver EyeImagesController::availableEquipments()).
 */
function createEntityEquipment(Entity $entity): EntityIntegratorEquipment
{
    return EntityIntegratorEquipment::factory()
        ->for(
            EntityIntegrator::factory()->for(
                EntityUserIntegrator::factory(['entity_id' => $entity->id]),
                'user',
            ),
            'integrator',
        )
        ->create(['active' => true]);
}

function approvedEyeRunFor($test, PatientExam $exam): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'    => $test->entity->id,
        'patient_id'   => $exam->patient_id,
        'requested_by' => $test->authUser->id,
        'workflow'     => 'eye_image_analysis',
        'mode'         => AiRunMode::Economy->value,
        'risk_level'   => AiRiskLevel::Medium->value,
        'status'       => AiRunStatus::Approved->value,
        'final_output' => 'Achado normal.',
    ]);

    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'       => $run->id,
        'patient_exam_id' => $exam->id,
        'entity_id'       => $test->entity->id,
        'created_at'      => now(),
    ]);

    return $run;
}

it('filtro status=laudado só retorna exames com AiRun aprovado (não zera mais a lista)', function () {
    $examLaudado  = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $examSemLaudo = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    approvedEyeRunFor($this, $examLaudado);

    $res = searchEyeImages($this, ['status' => 'laudado'])->assertOk();

    $patients = collect($res->json('patients'));
    expect($patients)->toHaveCount(1);

    $examIds = collect($patients->first()['exams'])->pluck('id');
    expect($examIds->all())->toBe([(string) $examLaudado->id])
        ->and($examIds->all())->not->toContain((string) $examSemLaudo->id);
});

it('filtro equipment_id isola exames por equipamento', function () {
    $equipmentA = createEntityEquipment($this->entity);
    $equipmentB = createEntityEquipment($this->entity);

    $examA = PatientExam::factory()->create([
        'patient_id'                     => $this->patient->id,
        'entity_integrator_equipment_id' => $equipmentA->id,
    ]);
    PatientExam::factory()->create([
        'patient_id'                     => $this->patient->id,
        'entity_integrator_equipment_id' => $equipmentB->id,
    ]);

    $res = searchEyeImages($this, ['equipment_id' => $equipmentA->id])->assertOk();

    $examIds = collect($res->json('patients.0.exams'))->pluck('id');
    expect($examIds->all())->toBe([(string) $examA->id]);
});

it('filtro cid_code isola exames por diagnóstico (whereJsonContains no Postgres)', function () {
    $examH40 = PatientExam::factory()->create([
        'patient_id'     => $this->patient->id,
        'diagnosis_cids' => [['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']],
    ]);
    PatientExam::factory()->create([
        'patient_id'     => $this->patient->id,
        'diagnosis_cids' => [['code' => 'H35.0', 'description' => 'Retinopatia de fundo']],
    ]);

    $res = searchEyeImages($this, ['cid_code' => 'H40.1'])->assertOk();

    $examIds = collect($res->json('patients.0.exams'))->pluck('id');
    expect($examIds->all())->toBe([(string) $examH40->id]);
});

it('busca por nome (parcial, case-insensitive) e por código do paciente', function () {
    $paciente = Patient::factory()
        ->for(People::factory(['full_name' => 'MARIA SILVA TESTE']), 'person')
        ->create(['entity_id' => $this->entity->id]);
    PatientExam::factory()->create(['patient_id' => $paciente->id]);

    $res = searchEyeImages($this, ['search' => 'maria silva'])->assertOk();
    expect(collect($res->json('patients'))->pluck('id')->all())->toBe([(string) $paciente->id]);

    $res2 = searchEyeImages($this, ['search' => $paciente->code])->assertOk();
    expect(collect($res2->json('patients'))->pluck('id')->all())->toBe([(string) $paciente->id]);
});

it('filtro eye=ao inclui exames com laterality nula', function () {
    $examNull = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => null]);
    PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 1]);

    $res = searchEyeImages($this, ['eye' => 'ao'])->assertOk();

    $examIds = collect($res->json('patients.0.exams'))->pluck('id');
    expect($examIds->all())->toBe([(string) $examNull->id]);
});

it('combina todos os filtros ao mesmo tempo (cenário do pedido: OCT + equipamento + diagnóstico + médico + olho)', function () {
    $doctor    = createDoctorForEntity($this->entity);
    $equipment = createEntityEquipment($this->entity);
    $examType  = ExamType::factory()->create(['entity_id' => $this->entity->id, 'name' => 'OCT']);

    $matching = PatientExam::factory()->create([
        'patient_id'                     => $this->patient->id,
        'doctor_id'                      => $doctor->id,
        'entity_integrator_equipment_id' => $equipment->id,
        'exam_id'                        => $examType->id,
        'laterality'                     => 1,
        'diagnosis_cids'                 => [['code' => 'H40.1', 'description' => 'Glaucoma']],
    ]);

    // "Isca": bate em tudo, exceto o olho (oe em vez de od) — precisa ficar de fora.
    PatientExam::factory()->create([
        'patient_id'                     => $this->patient->id,
        'doctor_id'                      => $doctor->id,
        'entity_integrator_equipment_id' => $equipment->id,
        'exam_id'                        => $examType->id,
        'laterality'                     => 2,
        'diagnosis_cids'                 => [['code' => 'H40.1', 'description' => 'Glaucoma']],
    ]);

    $res = searchEyeImages($this, [
        'doctor_id'    => $doctor->id,
        'equipment_id' => $equipment->id,
        'exam_type_id' => $examType->id,
        'eye'          => 'od',
        'cid_code'     => 'H40.1',
    ])->assertOk();

    $examIds = collect($res->json('patients.0.exams'))->pluck('id');
    expect($examIds->all())->toBe([(string) $matching->id]);
});

it('pagina os resultados (meta.total, has_more, current_page) em vez do limit(200) fixo', function () {
    Patient::factory()->count(30)->create(['entity_id' => $this->entity->id])
        ->each(fn (Patient $p) => PatientExam::factory()->create(['patient_id' => $p->id]));

    $res = searchEyeImages($this, ['per_page' => 25])->assertOk();
    expect($res->json('meta.total'))->toBe(30)
        ->and($res->json('meta.has_more'))->toBeTrue()
        ->and($res->json('patients'))->toHaveCount(25);

    $res2 = searchEyeImages($this, ['per_page' => 25, 'page' => 2])->assertOk();
    expect($res2->json('meta.current_page'))->toBe(2)
        ->and($res2->json('patients'))->toHaveCount(5)
        ->and($res2->json('meta.has_more'))->toBeFalse();
});

it('isola exames de outras entidades mesmo sem nenhum filtro', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

    PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    $res = searchEyeImages($this)->assertOk();

    expect(collect($res->json('patients'))->pluck('id')->all())->toBe([(string) $this->patient->id]);
});

it('exame filtrado não aparece nem em paciente que também tem outro exame batendo', function () {
    $matching = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 1]);
    PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 2]);

    $res = searchEyeImages($this, ['eye' => 'od'])->assertOk();

    $patients = collect($res->json('patients'));
    expect($patients)->toHaveCount(1);
    expect(collect($patients->first()['exams'])->pluck('id')->all())->toBe([(string) $matching->id]);
});
