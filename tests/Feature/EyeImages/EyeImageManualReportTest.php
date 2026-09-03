<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType, ReportSettingStatus};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, ReportCategory, ReportSetting, ReportSettingContent, User};

/**
 * Laudo manual do Gerenciador de Imagens (Modelos) — EyeImageReportController.
 * Mesma ancoragem no prontuário do dia do laudo de IA (ConsultationRecordResolver),
 * mesma tabela de destino (MedicalRecordDocumentation) e mesmo PDF.
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

    $this->secretaryUser       = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretaryUser, ClientRule::Secretary->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'date_time'  => now(),
    ]);
    $this->exam = PatientExam::factory()->create([
        'patient_id'  => $this->patient->id,
        'schedule_id' => $this->schedule->id,
        'laterality'  => 1,
    ]);

    // Template global (RETINOGRAFIA COLORIDA já é seedado pelo
    // ReportSettingContentSeeder — reaproveita se existir, senão cria um
    // fixture equivalente, mantendo o teste independente de seeder ter rodado).
    $this->reportSetting = ReportSetting::whereNull('entity_id')->where('title', 'RETINOGRAFIA COLORIDA')->first()
        ?? ReportSetting::create([
            'report_category_id' => ReportCategory::firstOrCreate(['slug' => 'exames-especializados'], ['name' => 'Exames Especializados'])->id,
            'title'              => 'RETINOGRAFIA COLORIDA (fixture)',
            'active'             => true,
            'status'             => ReportSettingStatus::Published,
        ]);
    $this->content = ReportSettingContent::where('report_setting_id', $this->reportSetting->id)
        ->where('slug', 'padrao')
        ->first()
        ?? ReportSettingContent::create([
            'report_setting_id' => $this->reportSetting->id,
            'type'              => DocumentationType::Report,
            'slug'              => 'padrao-fixture',
            'label'             => 'Padrão',
            'content'           => '{{CABECALHO_PACIENTE}}<p><strong>OLHO DIREITO</strong></p><p>Normal.</p>',
            'active'            => true,
        ]);
});

function actingAsDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

function actingAsSecretary($test)
{
    return $test->actingAs($test->secretaryUser)->withSession(panelSession($test->secretaryEntityUser));
}

describe('templates()', function () {
    it('lista só laudos/exames especializados — não mistura receituários/atestados', function () {
        $response = actingAsDoctor($this)->getJson(route('panel.eye-images.report-templates.index'));

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('report_setting_title');

        // Sufixo "(fixture)" quando o seeder de produção não rodou no banco de
        // teste (beforeEach cria um equivalente) — aceita os dois casos.
        expect($titles->contains(fn ($t) => str_starts_with((string) $t, 'RETINOGRAFIA COLORIDA')))->toBeTrue()
            ->and($titles->filter(fn ($t) => str_contains((string) $t, 'RECEITU')))->toBeEmpty()
            ->and($titles->filter(fn ($t) => str_contains((string) $t, 'ATESTADO')))->toBeEmpty();
    });
});

describe('previewTemplate()', function () {
    it('resolve o template mesmo sem prontuário ainda aberto', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.eye-images.report-templates.preview'), [
            'report_setting_content_id' => $this->content->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
        ]);

        $response->assertOk();
        expect($response->json('content'))->toContain('OLHO DIREITO')
            ->and($response->json('content'))->not->toContain('{{CABECALHO_PACIENTE}}');
    });

    it('[SEGURANÇA] template de outra clínica devolve 404', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherSetting = ReportSetting::create([
            'entity_id'          => $otherEntity->id,
            'report_category_id' => ReportCategory::firstOrCreate(['slug' => 'laudos'], ['name' => 'Laudos'])->id,
            'title'              => 'LAUDO PRIVADO DE OUTRA CLINICA',
            'active'             => true,
            'status'             => ReportSettingStatus::Published,
        ]);
        $otherContent = ReportSettingContent::create([
            'report_setting_id' => $otherSetting->id,
            'type'              => DocumentationType::Report,
            'slug'              => 'x',
            'label'             => 'X',
            'content'           => 'segredo',
            'active'            => true,
        ]);

        actingAsDoctor($this)->postJson(route('panel.eye-images.report-templates.preview'), [
            'report_setting_content_id' => $otherContent->id,
            'patient_id'                => $this->patient->id,
        ])->assertNotFound();
    });
});

describe('store()', function () {
    it('sem prontuário do dia: devolve 422 requires_record_confirmation, sem criar nada', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais.</p>',
        ]);

        $response->assertStatus(422)->assertJson(['requires_record_confirmation' => true]);
        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and(MedicalRecord::count())->toBe(0);
    });

    it('confirm_open_record=true abre o prontuário do dia e salva o laudo com PDF', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
            'report_setting_content_id' => $this->content->id,
            'title'                     => 'Retinografia — OD/OE',
            'content'                   => '<p>Achados normais em ambos os olhos.</p>',
            'confirm_open_record'       => true,
        ]);

        $response->assertCreated();
        $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->firstOrFail();

        expect($record->schedule_id)->toBe((string) $this->schedule->id)
            ->and($record->doctor_id)->toBe((string) $this->doctor->id);

        $doc = MedicalRecordDocumentation::query()->where('medical_record_id', $record->id)->firstOrFail();
        expect($doc->title)->toBe('Retinografia — OD/OE')
            ->and($doc->content)->toContain('Achados normais')
            ->and($doc->report_setting_content_id)->toBe($this->content->id)
            ->and($response->json('pdf_url'))->toContain((string) $doc->id);
    });

    it('reaproveita o prontuário do MESMO agendamento em vez de criar outro', function () {
        $existing = MedicalRecord::create([
            'entity_id'   => $this->entity->id,
            'patient_id'  => $this->patient->id,
            'doctor_id'   => $this->doctor->id,
            'schedule_id' => $this->schedule->id,
        ]);

        actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados.</p>',
        ])->assertCreated();

        expect(MedicalRecord::count())->toBe(1);
        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->medical_record_id)->toBe($existing->id)
            ->and($doc->title)->toBe(__('eye_images.report_default_title')); // sem template = título default
    });

    it('funciona sem template (laudo em branco, texto livre)', function () {
        actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Texto livre digitado pelo médico.</p>',
            'confirm_open_record' => true,
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->report_setting_content_id)->toBeNull()
            ->and($doc->content)->toContain('Texto livre digitado');
    });

    it('sanitiza o conteúdo (Purifier) antes de persistir', function () {
        actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achado normal.</p><script>alert(1)</script>',
            'confirm_open_record' => true,
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->content)->not->toContain('<script>');
    });

    it('[SEGURANÇA] secretária não emite laudo (Gate IssueReport é doctor-only)', function () {
        actingAsSecretary($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Tentativa.</p>',
            'confirm_open_record' => true,
        ])->assertForbidden();

        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[SEGURANÇA] exam_id de outra clínica é rejeitado com 403', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

        actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$otherExam->id],
            'content'             => '<p>Tentativa cross-tenant.</p>',
            'confirm_open_record' => true,
        ])->assertForbidden();

        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[SEGURANÇA] patient_id de outra clínica devolve 404', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);

        actingAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id'          => $otherPatient->id,
            'content'             => '<p>Tentativa cross-tenant.</p>',
            'confirm_open_record' => true,
        ])->assertNotFound();
    });
});
