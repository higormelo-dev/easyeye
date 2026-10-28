<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiRunDocumentationService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DocumentationType, DraftStatus, ReportSettingStatus};
use App\Models\{Doctor, Entity, EntityIntegrator, EntityIntegratorEquipment, EntityUserIntegrator, EyeImageReportDraft, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, ReportCategory, ReportSetting, ReportSettingContent, User};
use App\Services\EyeImages\ReportExamSummary;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, URL};

/**
 * Resumo dos exames do laudo (ReportExamSummary) CONGELADO na emissão — achado
 * via revisão adversarial Codex (20ª rodada): o PDF do laudo — inclusive o que
 * sai pelo link público do médico solicitante — remontava a lista de exames dos
 * dados AO VIVO a cada render. Emitir e assinar um laudo de um exame OD e depois
 * trocar a lateralidade desse exame para OE imprimia "OE" ao lado da imagem OD já
 * congelada; nem o clinical_revision nem o hash da assinatura enxergam essa
 * troca (o hash cobre só o prontuário, nunca o exame de origem).
 *
 * Agora a emissão grava o retrato bruto (metadata.exam_summary: data ISO, código
 * do olho) e o PDF o localiza ao imprimir.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUserModel  = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUserModel, ClientRule::Doctor->value);
    $this->doctor           = Doctor::query()->create([
        'entity_user_id' => $this->doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'date_time'  => now(),
    ]);

    $integratorUser = EntityUserIntegrator::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    $integrator     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $integratorUser->id, 'active' => true]);

    $this->equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $integrator->id, 'name' => 'Cirrus HD-OCT']);

    $this->exam = PatientExam::factory()->create([
        'patient_id'                     => $this->patient->id,
        'schedule_id'                    => $this->schedule->id,
        'laterality'                     => 1,
        'exam_performed_at'              => '2026-10-25 09:30:00',
        'entity_integrator_equipment_id' => $this->equipment->id,
    ]);
});

function rssActingAsDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

/** Emite o laudo (sem modelo) do exame pelo endpoint real e devolve a documentação criada. */
function rssIssue($test, array $extra = []): MedicalRecordDocumentation
{
    rssActingAsDoctor($test)->postJson(route('panel.exam-manager.reports.store'), array_merge([
        'patient_id'              => $test->patient->id,
        'exam_ids'                => [$test->exam->id],
        'content'                 => '<p>Achados normais.</p>',
        'confirm_open_record'     => true,
        'requesting_doctor_name'  => 'Dr. Carlos Souza',
        'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
    ], $extra))->assertCreated();

    return MedicalRecordDocumentation::query()->firstOrFail();
}

it('[CODEX] laudo emitido de um exame OD e assinado: trocar o exame para OE depois NÃO muda o que o link público imprime', function () {
    SnappyPdf::fake();

    $doc = rssIssue($this);
    $doc->medicalRecord->sign($this->doctorEntityUser);

    $frozen = app(ReportExamSummary::class)->forDocumentation($doc->fresh());

    expect($frozen)->toHaveCount(1)
        ->and($frozen[0]['eyes'])->toBe(['OD']);

    // Depois de assinado, o exame de origem é corrigido (lateralidade trocada) pelo endpoint real.
    rssActingAsDoctor($this)
        ->putJson(route('panel.exam-manager.exams.laterality.update', $this->exam), ['laterality' => 2])
        ->assertOk();

    expect($this->exam->fresh()->laterality)->toBe(2);

    // O MESMO link do e-mail ao médico solicitante.
    $link = URL::temporarySignedRoute('external-reports.show', now()->addDays(7), ['documentation' => $doc->id]);

    auth()->logout();
    $this->get($link)->assertOk();

    SnappyPdf::assertViewIs('pdf.documentation');
    SnappyPdf::assertViewHas('examSummary', $frozen);
});

it('a emissão grava o retrato BRUTO dos exames em metadata.exam_summary (data ISO, código do olho), nunca texto já formatado', function () {
    $doc = rssIssue($this);

    expect($doc->metadata[ReportExamSummary::SNAPSHOT_KEY])->toBe([[
        'type'      => $this->exam->examType->name,
        'date'      => '2026-10-25',
        'equipment' => 'CIRRUS HD-OCT',
        'eyes'      => [1],
        'images'    => 1,
    ]]);
});

it('o retrato é gravado também quando o laudo sai de um MODELO (ramo do documentationService) e quando sai de um RASCUNHO', function () {
    $setting = ReportSetting::create([
        'report_category_id' => ReportCategory::firstOrCreate(['slug' => 'exames-especializados'], ['name' => 'Exames Especializados'])->id,
        'title'              => 'RETINOGRAFIA COLORIDA (fixture)',
        'active'             => true,
        'status'             => ReportSettingStatus::Published,
    ]);
    $content = ReportSettingContent::create([
        'report_setting_id' => $setting->id,
        'type'              => DocumentationType::Report,
        'slug'              => 'padrao-fixture',
        'label'             => 'Padrão',
        'content'           => '<p>Normal.</p>',
        'active'            => true,
    ]);

    $fromTemplate = rssIssue($this, ['report_setting_content_id' => $content->id]);

    expect($fromTemplate->metadata[ReportExamSummary::SNAPSHOT_KEY][0]['eyes'])->toBe([1]);

    // Outro prontuário/dia: rascunho emitido pelo médico.
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'title'      => 'Laudo',
        'content'    => '<p>Achados.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    rssActingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
        'patient_id'          => $this->patient->id,
        'exam_ids'            => [$this->exam->id],
        'content'             => '<p>Achados.</p>',
        'draft_id'            => $draft->id,
        'draft_revision'      => $draft->revision,
        'confirm_open_record' => true,
    ])->assertCreated();

    $issued = MedicalRecordDocumentation::query()->latest('created_at')->firstOrFail();

    expect($issued->metadata[ReportExamSummary::SNAPSHOT_KEY][0]['eyes'])->toBe([1]);
});

it('o que muda no exame DEPOIS da emissão não muda o resumo impresso', function (Closure $mutate) {
    $doc    = rssIssue($this);
    $before = app(ReportExamSummary::class)->forDocumentation($doc->fresh());

    expect($before[0]['eyes'])->toBe(['OD']);

    $mutate($this->exam->fresh(), $this);

    expect(app(ReportExamSummary::class)->forDocumentation($doc->fresh()))->toBe($before);
})->with([
    'lateralidade trocada para OE' => fn (PatientExam $exam) => $exam->update(['laterality' => 2]),
    'lateralidade limpa (AO)'      => fn (PatientExam $exam) => $exam->update(['laterality' => null]),
    'tipo de exame renomeado'      => fn (PatientExam $exam) => $exam->examType->update(['name' => 'Exame renomeado depois']),
    'equipamento renomeado'        => fn (PatientExam $exam, $test) => $test->equipment->update(['name' => 'Outro aparelho']),
    'data do exame corrigida'      => fn (PatientExam $exam) => $exam->update(['exam_performed_at' => '2026-01-02 08:00:00']),
    'exame desativado'             => fn (PatientExam $exam) => $exam->update(['active' => false]),
    'exame excluído'               => fn (PatientExam $exam) => $exam->delete(),
]);

it('exame dividido (ou mesclado) DEPOIS da emissão: o agrupamento e a contagem de imagens impressos são os da emissão', function () {
    $session = (string) Str::uuid();

    $this->exam->update(['exam_session_id' => $session]);
    $second = PatientExam::factory()->create([
        'patient_id'                     => $this->patient->id,
        'schedule_id'                    => $this->schedule->id,
        'laterality'                     => 2,
        'exam_id'                        => $this->exam->exam_id,
        'exam_performed_at'              => '2026-10-25 09:31:00',
        'entity_integrator_equipment_id' => $this->equipment->id,
        'exam_session_id'                => $session,
    ]);

    $doc = rssIssue($this, ['exam_ids' => [$this->exam->id, $second->id]]);

    $before = app(ReportExamSummary::class)->forDocumentation($doc->fresh());

    // Um exame só, com os dois olhos e 2 imagens.
    expect($before)->toHaveCount(1)
        ->and($before[0]['eyes'])->toBe(['OD', 'OE'])
        ->and($before[0]['images'])->toBe(2);

    // Depois da emissão o segundo exame é dividido da sessão (ExamManagerActionsController::splitExams).
    $second->update(['exam_session_id' => null]);

    expect(app(ReportExamSummary::class)->forDocumentation($doc->fresh()))->toBe($before);
});

it('o retrato é localizado AO IMPRIMIR, no idioma de quem vê: data e rótulos dos olhos mudam, o retrato guardado não', function () {
    $this->exam->update(['laterality' => 2]);

    $doc = rssIssue($this);

    $original = app()->getLocale();

    try {
        app()->setLocale('pt_BR');
        Carbon::setLocale('pt_BR');
        $pt = app(ReportExamSummary::class)->forDocumentation($doc->fresh());

        app()->setLocale('en');
        Carbon::setLocale('en');
        $en = app(ReportExamSummary::class)->forDocumentation($doc->fresh());
    } finally {
        app()->setLocale($original);
        Carbon::setLocale($original);
    }

    expect($pt[0]['date'])->toBe('25/10/2026')
        ->and($pt[0]['eyes'])->toBe(['OE'])
        ->and($en[0]['date'])->toBe('10/25/2026')
        ->and($en[0]['eyes'])->toBe(['OS'])
        ->and($doc->fresh()->metadata[ReportExamSummary::SNAPSHOT_KEY][0]['date'])->toBe('2026-10-25')
        ->and($doc->fresh()->metadata[ReportExamSummary::SNAPSHOT_KEY][0]['eyes'])->toBe([2]);
});

it('laudo emitido ANTES do congelamento (sem retrato) segue lendo os exames ao vivo — não há como congelar retroativamente', function () {
    $record = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $legacy = MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'content'           => '<p>Laudo antigo.</p>',
    ]);
    $legacy->syncExams([(string) $this->exam->id], (string) $this->entity->id);

    expect($legacy->metadata)->toBeNull()
        ->and(app(ReportExamSummary::class)->forDocumentation($legacy->fresh())[0]['eyes'])->toBe(['OD']);

    $this->exam->update(['laterality' => 2]);

    expect(app(ReportExamSummary::class)->forDocumentation($legacy->fresh())[0]['eyes'])->toBe(['OE']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Laudo de IA: mesmo retrato (AiRunDocumentationService::writeRunDocumentation)
// ─────────────────────────────────────────────────────────────────────────────

function rssAiRun($test): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'requested_by'      => $test->doctorUserModel->id,
        'workflow'          => 'eye_image_analysis',
        'mode'              => AiRunMode::Economy->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::WaitingApproval->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
        'final_output'      => 'Achados compatíveis com retinopatia leve.',
        'input_summary'     => ['exam_ids' => [(string) $test->exam->id]],
    ]);

    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'              => $run->id,
        'patient_exam_id'        => $test->exam->id,
        'entity_id'              => $test->entity->id,
        'image_deidentification' => 'oculus_pentacam_panel_a_pt',
        'created_at'             => now(),
    ]);

    return $run;
}

it('laudo de IA aprovado também congela o resumo dos exames; reescrever o mesmo run não o regrava', function () {
    $run    = rssAiRun($this);
    $record = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    app(AiRunDocumentationService::class)->writeRunDocumentation($run, $record, 'Achados compatíveis com retinopatia leve.');

    $doc = MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->sole();

    expect($doc->metadata[ReportExamSummary::SNAPSHOT_KEY][0]['eyes'])->toBe([1]);

    $this->exam->update(['laterality' => 2]);

    // Idempotente: reaprovar/reescrever o mesmo run mantém o retrato da emissão.
    app(AiRunDocumentationService::class)->writeRunDocumentation($run, $record, 'Achados compatíveis com retinopatia leve.');

    expect($doc->fresh()->metadata[ReportExamSummary::SNAPSHOT_KEY][0]['eyes'])->toBe([1])
        ->and(app(ReportExamSummary::class)->forDocumentation($doc->fresh())[0]['eyes'])->toBe(['OD']);
});
