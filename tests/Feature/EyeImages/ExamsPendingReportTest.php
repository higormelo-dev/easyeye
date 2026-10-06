<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, User};
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * "Exames pendentes" (Dashboard) e filtro "Sem laudo"/"Laudado" do Gerenciador
 * de Imagens: exame laudado = laudo manual/conjunto vigente OU laudo de IA
 * aprovado (PatientExam::scopeReported). Antes o card era fixo "Em breve" e o
 * filtro "Laudado" ignorava o laudo manual.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->doctor     = Doctor::query()->create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    // O card da CLÍNICA (todos os exames) é o do administrador; o do médico
    // conta só os exames dele (ver DashboardPerProfileTest).
    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);
});

function eprExam($test, array $attributes = []): PatientExam
{
    return PatientExam::factory()->create(['patient_id' => $test->patient->id, 'active' => true, ...$attributes]);
}

/** Laudo manual (ou conjunto) vinculando os exames — mesmo caminho do EyeImageReportController. */
function eprManualReport($test, PatientExam ...$exams): MedicalRecordDocumentation
{
    $record = MedicalRecord::query()->create([
        'entity_id'  => $test->entity->id,
        'patient_id' => $test->patient->id,
        'doctor_id'  => $test->doctor->id,
    ]);

    $doc = MedicalRecordDocumentation::query()->create([
        'medical_record_id' => $record->id,
        'patient_id'        => $test->patient->id,
        'doctor_id'         => $test->doctor->id,
        'type'              => DocumentationType::Report,
        'title'             => 'Retinografia',
        'content'           => '<p>Achados normais.</p>',
    ]);
    $doc->syncExams(array_map(fn (PatientExam $e) => (string) $e->id, $exams), $test->entity->id);

    return $doc;
}

function eprAiRun($test, PatientExam $exam, AiRunStatus $status): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'    => $test->entity->id,
        'patient_id'   => $exam->patient_id,
        'requested_by' => $test->user->id,
        'workflow'     => 'eye_image_analysis',
        'mode'         => AiRunMode::Economy->value,
        'risk_level'   => AiRiskLevel::Medium->value,
        'status'       => $status->value,
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

function eprDashboardStats($test): array
{
    $stats = null;

    $test->actingAs($test->adminUser)
        ->withSession(panelSession($test->adminEntityUser))
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$stats) {
            $stats = $page->toArray()['props']['stats'];
        });

    return $stats;
}

function eprEyeImagesExamIds($test, array $query): array
{
    return collect(
        $test->actingAs($test->user)
            ->withSession(panelSession($test->entityUser))
            ->getJson(route('panel.eye-images.search', $query))
            ->assertOk()
            ->json('patients'),
    )->flatMap(fn (array $p) => collect($p['exams'])->pluck('id'))->sort()->values()->all();
}

it('conta só exames habilitados dos últimos 30 dias sem laudo manual nem IA aprovada', function () {
    $pending       = eprExam($this);
    $pendingOldest = eprExam($this, ['created_at' => now()->subDays(30)->startOfDay()]);
    $manual        = eprExam($this);
    $joint         = eprExam($this);
    $aiApproved    = eprExam($this);
    $aiWaiting     = eprExam($this); // IA ainda não aprovada = continua pendente
    eprExam($this, ['created_at' => now()->subDays(31)]); // fora da janela
    eprExam($this, ['active' => false]);                  // desabilitado não pode ser laudado

    eprManualReport($this, $manual);
    eprManualReport($this, $joint, eprExam($this)); // laudo conjunto cobre os dois
    eprAiRun($this, $aiApproved, AiRunStatus::Approved);
    eprAiRun($this, $aiWaiting, AiRunStatus::WaitingApproval);

    expect(eprDashboardStats($this)['exams_pending'])->toBe(3);

    $ids = PatientExam::query()->pendingReport($this->entity->id)
        ->where('created_at', '>=', now()->subDays(30)->startOfDay())
        ->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    expect($ids)->toBe(collect([$pending->id, $pendingOldest->id, $aiWaiting->id])->map(fn ($id) => (string) $id)->sort()->values()->all());
});

it('laudo manual excluído devolve o exame para pendente', function () {
    $exam = eprExam($this);
    $doc  = eprManualReport($this, $exam);

    expect(eprDashboardStats($this)['exams_pending'])->toBe(0);

    // SoftDeletes: o vínculo no pivot fica, mas o laudo não vale mais. (Direto
    // na coluna: $doc->delete() esbarra em deleted_by, que a tabela não tem.)
    MedicalRecordDocumentation::query()->whereKey($doc->id)->update(['deleted_at' => now()]);

    expect(eprDashboardStats($this)['exams_pending'])->toBe(1);
});

it('não conta exames nem laudos de outra clínica', function () {
    eprExam($this);

    $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $other->id]);
    PatientExam::factory()->count(2)->create(['patient_id' => $otherPatient->id, 'active' => true]);

    // Exame desta clínica com IA aprovada registrada por OUTRA clínica: não é laudo daqui.
    $exam = eprExam($this);
    $run  = eprAiRun($this, $exam, AiRunStatus::Approved);
    DB::table('ai_run_patient_exam')->where('ai_run_id', $run->id)->update(['entity_id' => $other->id]);

    expect(eprDashboardStats($this)['exams_pending'])->toBe(2);
});

it('libera o atalho do Gerenciador de Imagens para todos os perfis da clínica', function (string $rule) {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, $rule);

    $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('access.eye_images', true));

    $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->get(route('panel.eye-images.index', ['status' => 'pendente', 'period' => '30']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'pendente')
            ->where('filters.period', '30'));
})->with([
    'admin'      => ClientRule::Admin->value,
    'médico'     => ClientRule::Doctor->value,
    'secretária' => ClientRule::Secretary->value,
    'financeiro' => ClientRule::Financial->value,
    'usuário'    => ClientRule::User->value,
]);

it('filtro "Sem laudo" do Gerenciador de Imagens lista o que o card conta', function () {
    $pending    = eprExam($this);
    $manual     = eprExam($this);
    $aiApproved = eprExam($this);
    eprExam($this, ['active' => false]);
    eprExam($this, ['created_at' => now()->subDays(31)]);

    eprManualReport($this, $manual);
    eprAiRun($this, $aiApproved, AiRunStatus::Approved);

    $ids = eprEyeImagesExamIds($this, ['status' => 'pendente', 'period' => '30']);

    expect($ids)->toBe([(string) $pending->id])
        ->and(count($ids))->toBe(eprDashboardStats($this)['exams_pending']);
});

it('filtro "Laudado" passa a considerar o laudo manual além da IA aprovada', function () {
    eprExam($this);
    $manual     = eprExam($this);
    $aiApproved = eprExam($this);

    eprManualReport($this, $manual);
    eprAiRun($this, $aiApproved, AiRunStatus::Approved);

    expect(eprEyeImagesExamIds($this, ['status' => 'laudado', 'period' => '30']))
        ->toBe(collect([$manual->id, $aiApproved->id])->map(fn ($id) => (string) $id)->sort()->values()->all());
});

it('status desconhecido é ignorado (allowlist)', function () {
    eprExam($this);
    eprExam($this);

    expect(eprEyeImagesExamIds($this, ['status' => "pendente' OR 1=1", 'period' => '30']))->toHaveCount(2);
});
