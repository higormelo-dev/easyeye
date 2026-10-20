<?php

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Entity, EntityIntegrator, EntityIntegratorEquipment, EntityUserIntegrator, ExamType, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, User};
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
        // Exame DE FATO analisado (não só selecionado) — reportedConstraint()
        // só considera "laudado via IA" quando este outcome está preenchido
        // e não é SKIPPED_UNRECOGNIZED_LAYOUT (Fase 5 / revisão adversarial
        // Codex). Produção preenche via AiRunExecutionService::
        // resolveImages(); aqui é direto, sem decodificar imagem de verdade.
        'image_deidentification' => 'oculus_pentacam_panel_a_pt',
        'created_at'             => now(),
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

it('period=custom com date_from/date_to isola exames pelo intervalo informado', function () {
    $inRange = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'created_at' => now()->subDays(5),
    ]);
    PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'created_at' => now()->subDays(20),
    ]);

    $res = searchEyeImages($this, [
        'period'    => 'custom',
        'date_from' => now()->subDays(7)->format('Y-m-d'),
        'date_to'   => now()->format('Y-m-d'),
    ])->assertOk();

    $patients = collect($res->json('patients'));
    expect($patients)->toHaveCount(1);
    expect(collect($patients->first()['exams'])->pluck('id')->all())->toBe([(string) $inRange->id]);
});

it('period=custom sem date_to considera até agora', function () {
    PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'created_at' => now()->subDay(),
    ]);

    $res = searchEyeImages($this, [
        'period'    => 'custom',
        'date_from' => now()->subDays(3)->format('Y-m-d'),
    ])->assertOk();

    expect(collect($res->json('patients')))->toHaveCount(1);
});

it('period=custom com date_from em formato invalido nao quebra (ignora e cai no fallback seguro)', function () {
    PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    searchEyeImages($this, ['period' => 'custom', 'date_from' => 'DROP TABLE patients;--'])
        ->assertOk();
});

it('filtro urgent=1 traz só os exames marcados como urgentes', function () {
    $urgent = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'urgent' => true]);
    PatientExam::factory()->create(['patient_id' => $this->patient->id, 'urgent' => false]);

    $res = searchEyeImages($this, ['urgent' => 1])->assertOk();

    expect(collect($res->json('patients.0.exams'))->pluck('id')->all())->toBe([(string) $urgent->id])
        ->and($res->json('total_exams'))->toBe(1);
});

it('urgent fora de "1/true" não filtra, e o payload devolve urgent por exame', function () {
    $urgent = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'urgent' => true]);
    $normal = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'urgent' => false]);

    $res = searchEyeImages($this, ['urgent' => '0'])->assertOk();

    $byId = collect($res->json('patients.0.exams'))->keyBy('id');
    expect($byId)->toHaveCount(2)
        ->and($byId[(string) $urgent->id]['urgent'])->toBeTrue()
        ->and($byId[(string) $normal->id]['urgent'])->toBeFalse();
});

it('exames urgentes vêm primeiro dentro do paciente, mesmo sendo os mais antigos', function () {
    // Meio-dia: subHours(3) não pode cair no dia anterior (período padrão = hoje).
    $this->travelTo(now()->setTime(12, 0));

    $old = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'urgent' => true, 'created_at' => now()->subHours(3)]);
    $new = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'urgent' => false, 'created_at' => now()->subHour()]);

    $ids = collect(searchEyeImages($this)->assertOk()->json('patients.0.exams'))->pluck('id')->all();

    expect($ids)->toBe([(string) $old->id, (string) $new->id]);
});

it('paciente com exame urgente sobe na lista, mesmo com o cadastro menos recente', function () {
    $recent = Patient::factory()->create(['entity_id' => $this->entity->id, 'updated_at' => now()]);
    $older  = Patient::factory()->create(['entity_id' => $this->entity->id, 'updated_at' => now()->subDays(2)]);
    PatientExam::factory()->create(['patient_id' => $recent->id, 'urgent' => false]);
    PatientExam::factory()->create(['patient_id' => $older->id, 'urgent' => true]);

    $ids = collect(searchEyeImages($this)->assertOk()->json('patients'))->pluck('id')->all();

    expect(array_slice($ids, 0, 2))->toBe([(string) $older->id, (string) $recent->id]);
});

it('o urgente fora do filtro atual não promove o paciente (a contagem respeita o período)', function () {
    $recent = Patient::factory()->create(['entity_id' => $this->entity->id, 'updated_at' => now()]);
    $older  = Patient::factory()->create(['entity_id' => $this->entity->id, 'updated_at' => now()->subDays(2)]);
    PatientExam::factory()->create(['patient_id' => $recent->id, 'urgent' => false]);
    PatientExam::factory()->create(['patient_id' => $older->id, 'urgent' => false]);
    // Urgente de 60 dias atrás: fora do período "hoje" — não aparece, então não pode promover.
    PatientExam::factory()->create(['patient_id' => $older->id, 'urgent' => true, 'created_at' => now()->subDays(60)]);

    $ids = collect(searchEyeImages($this)->assertOk()->json('patients'))->pluck('id')->all();

    expect(array_slice($ids, 0, 2))->toBe([(string) $recent->id, (string) $older->id]);
});

/**
 * Laudo salvo (com médico solicitante em texto livre) vinculando os exames —
 * mesmo caminho do EyeImageReportController (documentação + pivot com entity_id).
 */
function reportRequestedBy($test, ?string $requester, array $exams, ?Entity $entity = null, ?Patient $patient = null): MedicalRecordDocumentation
{
    $entity ??= $test->entity;
    $patient ??= $test->patient;
    $doctor = createDoctorForEntity($entity);

    $record = MedicalRecord::query()->create([
        'entity_id'  => $entity->id,
        'patient_id' => $patient->id,
        'doctor_id'  => $doctor->id,
    ]);

    $doc = MedicalRecordDocumentation::query()->create([
        'medical_record_id'      => $record->id,
        'patient_id'             => $patient->id,
        'doctor_id'              => $doctor->id,
        'type'                   => DocumentationType::Report,
        'title'                  => 'Retinografia',
        'content'                => '<p>Achados normais.</p>',
        'requesting_doctor_name' => $requester,
    ]);
    $doc->syncExams(array_map(fn (PatientExam $e) => (string) $e->id, $exams), (string) $entity->id);

    return $doc;
}

describe('filtro por médico solicitante (requesting_doctor)', function () {
    it('casa parcial, sem diferença de caixa nem de acento, e só traz o exame do laudo', function () {
        $matching = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        $other    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        PatientExam::factory()->create(['patient_id' => $this->patient->id]); // sem laudo
        reportRequestedBy($this, 'Dr. José Álvares Souza', [$matching]);
        reportRequestedBy($this, 'Dra. Marta Lima', [$other]);

        foreach (['jose alvares', 'JOSÉ', 'souza', '  Álvares  '] as $term) {
            $res = searchEyeImages($this, ['requesting_doctor' => $term])->assertOk();

            expect(collect($res->json('patients.0.exams'))->pluck('id')->all())->toBe([(string) $matching->id])
                ->and($res->json('total_exams'))->toBe(1);
        }
    });

    it('exame sem laudo, laudo sem solicitante e termo que não bate ficam de fora', function () {
        $semSolicitante = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        PatientExam::factory()->create(['patient_id' => $this->patient->id]); // sem laudo algum
        reportRequestedBy($this, null, [$semSolicitante]);

        $res = searchEyeImages($this, ['requesting_doctor' => 'carlos'])->assertOk();

        expect($res->json('patients'))->toBe([])
            ->and($res->json('total_exams'))->toBe(0);
    });

    it('laudo excluído (soft delete) não conta', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        $doc  = reportRequestedBy($this, 'Dr. Carlos Souza', [$exam]);

        expect(searchEyeImages($this, ['requesting_doctor' => 'carlos'])->json('total_exams'))->toBe(1);

        $doc->delete();

        expect(searchEyeImages($this, ['requesting_doctor' => 'carlos'])->json('total_exams'))->toBe(0);
    });

    it('não vaza outra clínica: exame/laudo de lá com o mesmo solicitante não aparece aqui', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);
        reportRequestedBy($this, 'Dr. Carlos Souza', [$otherExam], $otherEntity, $otherPatient);

        $mine = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        reportRequestedBy($this, 'Dr. Carlos Souza', [$mine]);

        $res = searchEyeImages($this, ['requesting_doctor' => 'carlos'])->assertOk();

        expect(collect($res->json('patients'))->pluck('id')->all())->toBe([(string) $this->patient->id])
            ->and(collect($res->json('patients.0.exams'))->pluck('id')->all())->toBe([(string) $mine->id]);
    });

    it('combina com os outros filtros mantendo o AND (olho + solicitante)', function () {
        $od = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 1]);
        $oe = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 2]);
        reportRequestedBy($this, 'Dr. Carlos Souza', [$od, $oe]);

        $res = searchEyeImages($this, ['requesting_doctor' => 'carlos', 'eye' => 'od'])->assertOk();

        expect(collect($res->json('patients.0.exams'))->pluck('id')->all())->toBe([(string) $od->id]);

        $none = searchEyeImages($this, ['requesting_doctor' => 'marta', 'eye' => 'od'])->assertOk();

        expect($none->json('patients'))->toBe([]);
    });

    it('%, _ e \\ valem como texto, não como curinga', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        reportRequestedBy($this, 'Dr. Carlos Souza', [$exam]);

        foreach (['%', '_', 'C_rlos', 'Car%os', '\\'] as $term) {
            expect(searchEyeImages($this, ['requesting_doctor' => $term])->assertOk()->json('total_exams'))->toBe(0);
        }
    });

    it('array no query string não vira filtro nem erro', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        reportRequestedBy($this, 'Dr. Carlos Souza', [$exam]);

        expect(searchEyeImages($this, ['requesting_doctor' => ['carlos']])->assertOk()->json('total_exams'))->toBe(1);
    });

    it('a tela devolve o eco do filtro (filters.requesting_doctor), nulo quando vazio', function () {
        $page = fn (array $query) => $this->actingAs($this->authUser)->withSession(panelSession($this->authEntityUser))
            ->get(route('panel.eye-images.index', $query));

        $page(['requesting_doctor' => '  Dr. Carlos  '])->assertOk()->assertInertia(fn ($p) => $p->where('filters.requesting_doctor', 'Dr. Carlos'));
        $page(['requesting_doctor' => '   '])->assertOk()->assertInertia(fn ($p) => $p->where('filters.requesting_doctor', null));
        // Teto de 80 caracteres (o mesmo do maxlength do input).
        $page(['requesting_doctor' => str_repeat('a', 100)])->assertOk()->assertInertia(fn ($p) => $p->where('filters.requesting_doctor', str_repeat('a', 80)));
    });
});
