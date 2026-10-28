<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType, DraftStatus, ReportSettingStatus};
use App\Models\{Doctor, Entity, ExamType, EyeImageReportDraft, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, ReportCategory, ReportSetting, ReportSettingContent, User};
use App\Notifications\RequestingDoctorReportReady;
use App\Services\EyeImages\{ReportExamSummary, ReportImageBlockBuilder};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\Facades\{Notification, Storage, URL};
use Illuminate\Support\Str;

/**
 * Laudo manual do Gerenciador de exames (Modelos) — ExamManagerReportController.
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

    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);

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

function actingAsAdmin($test)
{
    return $test->actingAs($test->adminUser)->withSession(panelSession($test->adminEntityUser));
}

describe('templates()', function () {
    it('lista só laudos/exames especializados — não mistura receituários/atestados', function () {
        $response = actingAsDoctor($this)->getJson(route('panel.exam-manager.report-templates.index'));

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
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
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

        actingAsDoctor($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $otherContent->id,
            'patient_id'                => $this->patient->id,
        ])->assertNotFound();
    });

    it('admin e secretária pré-visualizam (rascunham) — Gate DraftImageReport, mais aberto que o de emitir (Fase 5, 15ª revisão Codex)', function () {
        // Pré-visualizar é ato preparatório (rascunho), não emissão —
        // continua travado em IssueReport só dentro de store(). Sem médico
        // cadastrado ativo, o preview usa o médico do AGENDAMENTO ligado ao
        // exame só pra mostrar como o modelo fica (resolveDoctorIdForPreview);
        // quem de fato assina resolve de novo, do zero, em store().
        actingAsAdmin($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $this->content->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
        ])->assertOk();

        actingAsSecretary($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $this->content->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
        ])->assertOk();
    });

    it('[GAP] secretária pré-visualiza modelo pra exame importado/capturado sem agendamento, usando o médico gravado no exame', function () {
        // Exame importado manualmente ou capturado pela câmera (ImportExternalExamModal
        // / ExternalExamImportService) nunca tem schedule_id. Sem o fallback via
        // PatientExam::doctor_id, resolveDoctorIdForPreview() devolvia null pra
        // secretária (sem cadastro de Doctor — resolveDoctorIdForCurrentUser() é
        // assim mesmo pra ela) e o preview quebrava com 422, mesmo quando o
        // import já tinha um médico explicitamente selecionado.
        $importedExam = PatientExam::factory()->create([
            'patient_id'  => $this->patient->id,
            'schedule_id' => null,
            'doctor_id'   => $this->doctor->id,
        ]);

        actingAsSecretary($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $this->content->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$importedExam->id],
        ])->assertOk();
    });

    it('[GAP] resolve {{DATA_ATUAL}} com a data alternativa escolhida, não com a data de hoje', function () {
        $contentWithDate = ReportSettingContent::create([
            'report_setting_id' => $this->reportSetting->id,
            'type'              => DocumentationType::Report,
            'slug'              => 'com-data-fixture',
            'label'             => 'Com data (fixture)',
            'content'           => '<p>Data: {{DATA_ATUAL}}</p>',
            'active'            => true,
        ]);
        $alternativeDate = now()->subDays(5);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $contentWithDate->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
            'document_date'             => $alternativeDate->toDateString(),
        ]);

        $response->assertOk();
        expect($response->json('content'))->toContain($alternativeDate->format('d/m/Y'))
            ->and($response->json('content'))->not->toContain(now()->format('d/m/Y'));
    });

    it('[GAP] sem data alternativa, resolve {{DATA_ATUAL}} com hoje (comportamento inalterado)', function () {
        $contentWithDate = ReportSettingContent::create([
            'report_setting_id' => $this->reportSetting->id,
            'type'              => DocumentationType::Report,
            'slug'              => 'com-data-fixture-2',
            'label'             => 'Com data (fixture 2)',
            'content'           => '<p>Data: {{DATA_ATUAL}}</p>',
            'active'            => true,
        ]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $contentWithDate->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
        ]);

        $response->assertOk();
        expect($response->json('content'))->toContain(now()->format('d/m/Y'));
    });
});

describe('store()', function () {
    it('sem prontuário do dia: devolve 422 requires_record_confirmation, sem criar nada', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais.</p>',
        ]);

        $response->assertStatus(422)->assertJson(['requires_record_confirmation' => true]);
        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and(MedicalRecord::count())->toBe(0);
    });

    it('confirm_open_record=true abre o prontuário do dia e salva o laudo com PDF', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
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

    it('atribui o laudo ao médico que está de fato assinando, não ao dono original do prontuário', function () {
        // Prontuário do dia já existe sob OUTRO médico (cobertura/plantão —
        // Gate::IssueReport autoriza por role em toda a clínica, não por
        // posse do prontuário). O laudo tem que nascer com o autor real.
        $coveringDoctorUser       = User::factory()->create();
        $coveringDoctorEntityUser = createEntityUser($this->entity, $coveringDoctorUser, ClientRule::Doctor->value);
        $coveringDoctor           = Doctor::query()->create([
            'entity_user_id' => $coveringDoctorEntityUser->id,
            'person_id'      => People::factory()->create()->id,
            'active'         => true,
        ]);

        $existing = MedicalRecord::create([
            'entity_id'   => $this->entity->id,
            'patient_id'  => $this->patient->id,
            'doctor_id'   => $this->doctor->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $response = $this->actingAs($coveringDoctorUser)
            ->withSession(panelSession($coveringDoctorEntityUser))
            ->postJson(route('panel.exam-manager.reports.store'), [
                'patient_id' => $this->patient->id,
                'exam_ids'   => [$this->exam->id],
                'content'    => '<p>Laudo emitido pelo médico de cobertura.</p>',
            ]);

        $response->assertCreated();
        $doc = MedicalRecordDocumentation::query()->where('medical_record_id', $existing->id)->firstOrFail();
        expect($doc->doctor_id)->toBe((string) $coveringDoctor->id)
            ->and($doc->doctor_id)->not->toBe((string) $this->doctor->id);
    });

    it('salva indicação clínica, observações e data alternativa (laudo em branco) e reflete no PDF', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'clinical_indication' => 'Encaminhado para rastreio de retinopatia diabética.',
            'observations'        => 'Paciente colaborativo, exame sem intercorrências.',
            'document_date'       => now()->subDay()->toDateString(),
        ]);

        $response->assertCreated();
        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->imageReportClinicalIndication())->toBe('Encaminhado para rastreio de retinopatia diabética.')
            ->and($doc->imageReportObservations())->toBe('Paciente colaborativo, exame sem intercorrências.')
            ->and($doc->document_date->toDateString())->toBe(now()->subDay()->toDateString());

        actingAsDoctor($this)->get($response->json('pdf_url'))->assertOk();
    });

    it('salva indicação clínica/observações também com template (não só laudo em branco)', function () {
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
            'report_setting_content_id' => $this->content->id,
            'content'                   => '<p>Achados normais em ambos os olhos.</p>',
            'confirm_open_record'       => true,
            'clinical_indication'       => 'Pré-operatório de catarata.',
            'observations'              => 'Repetir em 6 meses.',
        ]);

        $response->assertCreated();
        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->imageReportClinicalIndication())->toBe('Pré-operatório de catarata.')
            ->and($doc->imageReportObservations())->toBe('Repetir em 6 meses.');
    });

    it('recusa data alternativa fora da faixa permitida, sem criar nada', function () {
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'document_date'       => now()->addYears(2)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('document_date');

        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[SEGURANÇA] com e-mail de médico solicitante, grava os dados mas NÃO envia: o prontuário ainda não está assinado (achado via revisão adversarial Codex)', function () {
        Notification::fake();

        // Antes o link público saía na emissão, com o prontuário editável e o
        // documento ainda excluível — o médico solicitante recebia um laudo
        // que a clínica podia mudar ou apagar depois. O laudo é criado DENTRO
        // do prontuário (que, por isso mesmo, ainda não pode estar assinado:
        // GuardedBySignedRecord recusa documento novo em prontuário assinado),
        // então a entrega fica pendente até a assinatura.
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'              => $this->patient->id,
            'exam_ids'                => [$this->exam->id],
            'content'                 => '<p>Achados normais.</p>',
            'confirm_open_record'     => true,
            'requesting_doctor_name'  => 'Dr. Carlos Souza',
            'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('requesting_doctor_email', 'carlos.souza@outraclinica.com')
            ->assertJsonPath('requesting_doctor_sent_at', null)
            ->assertJsonPath('requesting_doctor_awaiting_signature', true);

        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->requesting_doctor_name)->toBe('Dr. Carlos Souza')
            ->and($doc->requesting_doctor_email)->toBe('carlos.souza@outraclinica.com')
            ->and($doc->requesting_doctor_sent_at)->toBeNull()
            ->and($doc->medicalRecord->isSigned())->toBeFalse();

        Notification::assertNothingSent();
    });

    it('[FLUXO] emite com e-mail (nada sai) → assina o prontuário → envia → o link público abre → o laudo entregue não pode mais ser excluído', function () {
        Notification::fake();

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'              => $this->patient->id,
            'exam_ids'                => [$this->exam->id],
            'content'                 => '<p>Achados normais.</p>',
            'confirm_open_record'     => true,
            'requesting_doctor_name'  => 'Dr. Carlos Souza',
            'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
        ])->assertCreated();

        $doc    = MedicalRecordDocumentation::query()->firstOrFail();
        $record = $doc->medicalRecord;
        $link   = URL::temporarySignedRoute('external-reports.show', now()->addDays(7), ['documentation' => $doc->id]);

        // Antes da assinatura: nem o link público, nem o envio.
        $this->get($link)->assertNotFound();
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.resend', $doc))->assertUnprocessable();
        Notification::assertNothingSent();

        // Assina o prontuário (ato médico) — agora o laudo é imutável.
        $record->sign($this->doctorEntityUser);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.resend', $doc))->assertOk();

        Notification::assertSentOnDemandTimes(RequestingDoctorReportReady::class, 1);
        Notification::assertSentOnDemand(
            RequestingDoctorReportReady::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'carlos.souza@outraclinica.com'
                && $notification->documentationId === $doc->id,
        );
        expect($doc->refresh()->requesting_doctor_sent_at)->not->toBeNull();

        $this->get($link)->assertOk();

        // O que foi entregue fora da clínica não some nem muda.
        actingAsDoctor($this)
            ->deleteJson(route('panel.patients.medicalrecords.documentations.destroy', [$this->patient, $record, $doc]))
            ->assertForbidden();

        expect(MedicalRecordDocumentation::query()->whereKey($doc->id)->exists())->toBeTrue();
    });

    it('rascunho emitido com e-mail de solicitante também só entrega depois da assinatura', function () {
        Notification::fake();

        $draft = EyeImageReportDraft::create([
            'entity_id'                 => $this->entity->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$this->exam->id],
            'report_setting_content_id' => $this->content->id,
            'title'                     => 'Laudo',
            'content'                   => '<p>Achados.</p>',
            'status'                    => DraftStatus::Submitted,
            'requesting_doctor_name'    => 'Dr. Carlos Souza',
            'requesting_doctor_email'   => 'carlos.souza@outraclinica.com',
        ]);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'              => $this->patient->id,
            'exam_ids'                => [$this->exam->id],
            'content'                 => '<p>Achados.</p>',
            'confirm_open_record'     => true,
            'draft_id'                => $draft->id,
            'draft_revision'          => $draft->revision,
            'requesting_doctor_name'  => 'Dr. Carlos Souza',
            'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
        ])->assertCreated()->assertJsonPath('requesting_doctor_awaiting_signature', true);

        Notification::assertNothingSent();
    });

    it('sem e-mail de médico solicitante, não dispara notificação nem grava sent_at', function () {
        Notification::fake();

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
        ])->assertCreated();

        Notification::assertNothingSent();
        expect(MedicalRecordDocumentation::query()->firstOrFail()->requesting_doctor_sent_at)->toBeNull();
    });

    it('[GAP] nome do médico solicitante SEM e-mail é persistido (não é descartado em silêncio)', function () {
        Notification::fake();

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'             => $this->patient->id,
            'exam_ids'               => [$this->exam->id],
            'content'                => '<p>Achados normais.</p>',
            'confirm_open_record'    => true,
            'requesting_doctor_name' => 'Dr. Carlos Souza',
            // SEM requesting_doctor_email de propósito.
        ])->assertCreated();

        Notification::assertNothingSent(); // sem e-mail, nada pra notificar
        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->requesting_doctor_name)->toBe('Dr. Carlos Souza')
            ->and($doc->requesting_doctor_email)->toBeNull()
            ->and($doc->requesting_doctor_sent_at)->toBeNull();
    });

    it('[GAP] rascunho pré-visualizado por outro médico BLOQUEIA a emissão até o médico confirmar que revisou (nunca emite nem edita o texto antes do aviso)', function () {
        $otherDoctorUser       = User::factory()->create();
        $otherDoctorEntityUser = createEntityUser($this->entity, $otherDoctorUser, ClientRule::Doctor->value);
        $otherDoctor           = Doctor::query()->create([
            'entity_user_id' => $otherDoctorEntityUser->id,
            'person_id'      => People::factory()->create(['full_name' => 'Dra. Beatriz Nunes'])->id,
            'active'         => true,
        ]);

        $draft = EyeImageReportDraft::create([
            'entity_id'          => $this->entity->id,
            'patient_id'         => $this->patient->id,
            'exam_ids'           => [$this->exam->id],
            'content'            => '<p>Achados normais.</p>',
            'resolved_doctor_id' => $otherDoctor->id,
            'status'             => DraftStatus::Submitted,
        ]);

        // $this->doctor (quem emite) !== $otherDoctor (quem previu o texto
        // ao abrir o rascunho) — o PDF assina com $this->doctor (correto,
        // CurrentDoctor nunca muda); o aviso é só pro médico revisar se o
        // CORPO do texto, já resolvido, cita o nome/CRM de quem previu.
        $payload = [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
            'draft_revision'      => $draft->revision,
        ];

        // 1ª tentativa, SEM confirmar o aviso — bloqueia em 422, nada é
        // criado nem o rascunho é marcado Issued. Antes, o aviso só vinha
        // na resposta de SUCESSO: o documento já estava criado, o rascunho
        // já Issued e um eventual e-mail ao médico solicitante já enviado
        // de verdade ANTES do médico ver o aviso (achado via revisão Codex).
        // People::setAttribute() deixa full_name em caixa alta sempre
        // (convenção já estabelecida no model, não específica deste teste).
        $blocked = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), $payload);

        $blocked->assertStatus(422);
        expect($blocked->json('physician_mismatch_warning'))
            ->toBeString()
            ->toContain('BEATRIZ NUNES');
        expect(MedicalRecordDocumentation::count())->toBe(0);
        expect($draft->refresh()->status)->toBe(DraftStatus::Submitted);

        // 2ª tentativa, médico já revisou o texto e confirma — emite normalmente.
        $confirmed = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            ...$payload,
            'confirm_physician_mismatch' => true,
        ]);

        $confirmed->assertCreated();
        expect($confirmed->json('physician_mismatch_warning'))
            ->toBeString()
            ->toContain('BEATRIZ NUNES');
        // Nunca edita o texto automaticamente — mesmo conteúdo emitido.
        expect(MedicalRecordDocumentation::query()->firstOrFail()->content)->toContain('Achados normais');
        expect($draft->refresh()->status)->toBe(DraftStatus::Issued);
    });

    it('médico que previu o rascunho é o mesmo que está emitindo — sem aviso de divergência', function () {
        $draft = EyeImageReportDraft::create([
            'entity_id'          => $this->entity->id,
            'patient_id'         => $this->patient->id,
            'exam_ids'           => [$this->exam->id],
            'content'            => '<p>Achados normais.</p>',
            'resolved_doctor_id' => $this->doctor->id,
            'status'             => DraftStatus::Submitted,
        ]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
            'draft_revision'      => $draft->revision,
        ]);

        $response->assertCreated();
        expect($response->json('physician_mismatch_warning'))->toBeNull();
    });

    it('rascunho nunca pré-visualizado com médico (resolved_doctor_id nulo) — sem aviso de divergência', function () {
        $draft = EyeImageReportDraft::create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais.</p>',
            // SEM resolved_doctor_id de propósito — rascunho escrito do
            // zero, sem nunca ter passado por onTemplateChange()/preview.
            'status' => DraftStatus::Submitted,
        ]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
            'draft_revision'      => $draft->revision,
        ]);

        $response->assertCreated();
        expect($response->json('physician_mismatch_warning'))->toBeNull();
    });

    it('[GAP] emite com draft_revision ATUAL: aceita normalmente (token não é exigência vazia) — achado via revisão adversarial Codex', function () {
        $draft = EyeImageReportDraft::create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais.</p>',
            'status'     => DraftStatus::Submitted,
        ]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
            'draft_revision'      => $draft->revision,
        ]);

        $response->assertCreated();
        expect($draft->refresh()->status)->toBe(DraftStatus::Issued);
    });

    it('[GAP] emite com draft_revision OBSOLETA (outra pessoa corrigiu o rascunho enquanto o médico ainda tinha a versão antiga aberta): rejeita com 409, correção não é perdida, rascunho não fecha o ciclo — achado via revisão adversarial Codex', function () {
        $draft = EyeImageReportDraft::create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais (texto que o médico abriu).</p>',
            'status'     => DraftStatus::Submitted,
        ]);
        $staleRevision = $draft->revision; // 1 — o que o médico "carregou"

        // Secretária corrige de verdade (mesmo endpoint) ENQUANTO o médico
        // ainda tinha a revisão antiga aberta na tela — avança revision pra 2.
        actingAsSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
            'exam_ids'       => [$this->exam->id],
            'content'        => '<p>Achados normais, COM a correção da secretária.</p>',
            'draft_revision' => $staleRevision,
        ])->assertOk();

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            // Médico emite o texto ANTIGO que o navegador dele carregou —
            // nunca viu a correção da secretária.
            'content'             => '<p>Achados normais (texto que o médico abriu).</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
            'draft_revision'      => $staleRevision,
        ]);

        $response->assertStatus(409);
        expect(MedicalRecordDocumentation::count())->toBe(0);
        // Não existia prontuário do dia (beforeEach não cria um pra este
        // paciente) e confirm_open_record=true — withRecordOpeningLock()
        // abria e comitava ANTES desta checagem de revision, deixando um
        // prontuário vazio e órfão pra sempre mesmo com a emissão rejeitada
        // (achado na 9ª rodada de revisão adversarial Codex: a abertura
        // precisa estar na MESMA transação do documento/checagem de revision).
        expect(MedicalRecord::count())->toBe(0);
        // A correção da secretária não foi perdida nem sobrescrita, e o
        // rascunho não fechou o ciclo (continua Submitted, editável).
        expect($draft->refresh()->status)->toBe(DraftStatus::Submitted)
            ->and($draft->content)->toContain('COM a correção da secretária');
    });

    it('[GAP] revisão obsoleta detectada na emissão mesmo com updated_at idêntico entre as 2 versões (colisão do precision 0 de timestamps() — revision nunca depende de relógio) — achado na 2ª rodada de revisão adversarial Codex', function () {
        $draft = EyeImageReportDraft::create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais (texto que o médico abriu).</p>',
            'status'     => DraftStatus::Submitted,
        ]);
        $staleRevision   = $draft->revision; // 1 — o que o médico "carregou"
        $frozenUpdatedAt = $draft->updated_at;

        // Secretária corrige de verdade (revision avança pra 2), mas
        // updated_at fica PINADO no MESMO valor — reproduz a colisão real
        // (Schema Builder usa precision 0 pra timestamps() desta tabela).
        $draft->forceFill(['content' => '<p>Achados normais, COM a correção da secretária.</p>'])->save();
        $draft->incrementRevision();
        $draft->forceFill(['updated_at' => $frozenUpdatedAt])->saveQuietly();
        expect($draft->refresh()->updated_at->equalTo($frozenUpdatedAt))->toBeTrue()
            ->and($draft->revision)->toBe(2);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais (texto que o médico abriu).</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
            'draft_revision'      => $staleRevision,
        ]);

        $response->assertStatus(409);
        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and($draft->refresh()->status)->toBe(DraftStatus::Submitted)
            ->and($draft->content)->toContain('COM a correção da secretária');
    });

    it('[SEGURANÇA] emite com draft_id SEM draft_revision: 422, nunca passa por cima da checagem de obsolescência — achado na 3ª rodada de revisão adversarial Codex', function () {
        $draft = EyeImageReportDraft::create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'exam_ids'   => [$this->exam->id],
            'content'    => '<p>Achados normais.</p>',
            'status'     => DraftStatus::Submitted,
        ]);

        // Omitir o campo (em vez de mandar o valor errado) ANTES desligava
        // a checagem por completo — bastava não enviar nada pra emitir por
        // cima da correção de qualquer um sem nunca receber 409.
        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['draft_revision']);
        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and($draft->refresh()->status)->toBe(DraftStatus::Submitted);
    });

    // Fixture mínima de JPEG real (precisa decodificar de verdade: a
    // emissão agora resolve e CONGELA os bytes na hora de emitir — 2ª
    // rodada de revisão adversarial Codex — nunca mais um teste com
    // exam_id "de fachada", sem arquivo nenhum no S3).
    function manualReportFakeExamWithPhoto(Patient $patient, ?string $scheduleId, int $laterality = 1): PatientExam
    {
        $img = imagecreatetruecolor(20, 15);
        ob_start();
        imagejpeg($img);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($img);

        // display_archive não está em $fillable (correto — PatientExam
        // nunca deixa isto vir cru de um payload) — update() depois de
        // criar dropava silenciosamente. factory()->create() força o
        // atributo (bypassa $fillable), então o id precisa ser conhecido
        // ANTES pra montar o path do S3 já na criação.
        $id   = (string) Str::uuid();
        $path = "exams/{$id}_display.jpg";

        $exam = PatientExam::factory()->create([
            'id'              => $id,
            'patient_id'      => $patient->id,
            'schedule_id'     => $scheduleId,
            'laterality'      => $laterality,
            'display_archive' => $path,
        ]);
        Storage::disk('s3')->put($path, $jpegBytes);

        return $exam;
    }

    it('salva o bloco de imagens no laudo (metadata.image_report), com order pela posição enviada', function () {
        Storage::fake('s3');
        $firstExam  = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id, 1);
        $secondExam = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id, 2);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$firstExam->id, $secondExam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'image_report'        => [
                'enabled'    => true,
                'columns'    => 4,
                'pair_od_oe' => true,
                // Mandado "fora de ordem" de propósito — order final vem da
                // posição no array, nunca do campo order enviado.
                'images' => [
                    ['exam_id' => $secondExam->id, 'caption' => 'OE', 'order' => 99],
                    ['exam_id' => $firstExam->id, 'caption' => 'OD', 'order' => 0],
                ],
            ],
        ])->assertCreated();

        $doc   = MedicalRecordDocumentation::query()->firstOrFail();
        $block = $doc->imageReportBlock();
        // Bytes já congelados na emissão (achado via revisão adversarial
        // Codex) — nunca mais exam_id/order no bloco salvo, só o
        // resultado final já resolvido, na ordem enviada.
        expect($block['enabled'])->toBeTrue()
            ->and($block['columns'])->toBe(4)
            ->and($block['images'])->toHaveCount(2)
            ->and($block['images'][0]['caption'])->toBe('OE')
            ->and($block['images'][1]['caption'])->toBe('OD')
            ->and($block['images'][0]['data_uri'])->toStartWith('data:image/jpeg;base64,');
    });

    it('[SEGURANÇA] exam_id fora do laudo (exam_ids) no bloco de imagens rejeita com 422, nunca descarta em silêncio — achado via revisão adversarial Codex', function () {
        Storage::fake('s3');
        $exam = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'image_report'        => [
                'enabled' => true,
                'images'  => [
                    ['exam_id' => $exam->id, 'caption' => 'Fica'],
                    ['exam_id' => $otherExam->id, 'caption' => 'Fora da seleção'],
                ],
            ],
        ]);

        // Antes: descartava a imagem fora de exam_ids em silêncio e emitia
        // normalmente (201) — o laudo saía com menos imagem do que a tela
        // mostrava, sem nenhum aviso. Rejeitar com 422 obriga a tela a
        // resincronizar a seleção ANTES de salvar (nunca emite incompleto
        // sem o médico saber).
        $response->assertStatus(422);
        expect(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('image_report desabilitado ou sem imagens não grava bloco', function () {
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'image_report'        => ['enabled' => false, 'images' => [['exam_id' => $this->exam->id]]],
        ])->assertCreated();

        expect(MedicalRecordDocumentation::query()->firstOrFail()->imageReportBlock())->toBeNull();
    });

    it('[GAP] imagem selecionada sem arquivo no S3: bloqueia com aviso, nunca emite um laudo silenciosamente incompleto — achado na 2ª rodada de revisão adversarial Codex', function () {
        Storage::fake('s3');
        // Exame SEM nenhum arquivo de verdade no disco fake — simula
        // arquivo ausente/corrompido/exame desabilitado no momento exato
        // da emissão.
        $examWithoutFile = PatientExam::factory()->create([
            'patient_id'  => $this->patient->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$examWithoutFile->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'image_report'        => [
                'enabled' => true,
                'images'  => [['exam_id' => $examWithoutFile->id, 'caption' => 'Sem arquivo']],
            ],
        ]);

        // Antes, a imagem simplesmente desaparecia do laudo assinado sem
        // nenhum aviso ao médico — nunca bloqueava nem avisava.
        $response->assertStatus(422);
        expect($response->json('image_report_incomplete_warning'))->toBeString();
        expect(MedicalRecordDocumentation::count())->toBe(0)
            // confirm_open_record=true (acima) simula a 2ª rodada, DEPOIS do
            // médico já ter confirmado abrir o prontuário — antes, esta
            // checagem de imagem vinha DEPOIS de withRecordOpeningLock() já
            // ter aberto E COMITADO o prontuário; cancelar aqui deixava um
            // prontuário vazio e órfão pra sempre (achado no /codex:review:
            // "an empty medical record remains despite the rejected report
            // operation").
            ->and(MedicalRecord::count())->toBe(0);
    });

    it('[GAP] confirma emissão incompleta (confirm_image_report_incomplete=true): emite mesmo faltando a imagem, nunca trava o médico sem saída — achado na 2ª rodada de revisão adversarial Codex', function () {
        Storage::fake('s3');
        $examWithoutFile = PatientExam::factory()->create([
            'patient_id'  => $this->patient->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'                      => $this->patient->id,
            'exam_ids'                        => [$examWithoutFile->id],
            'content'                         => '<p>Achados normais.</p>',
            'confirm_open_record'             => true,
            'confirm_image_report_incomplete' => true,
            'image_report'                    => [
                'enabled' => true,
                'images'  => [['exam_id' => $examWithoutFile->id, 'caption' => 'Sem arquivo']],
            ],
        ])->assertCreated();

        // Emite, mas sem o bloco de imagens (a única imagem selecionada não
        // pôde ser resolvida) — nunca um bloco "vazio" enganoso.
        expect(MedicalRecordDocumentation::query()->firstOrFail()->imageReportBlock())->toBeNull();
    });

    it('[GAP] "Inserir imagem" (referência inline <img data-exam-image-id>, distinta do bloco acima) sobrevive ao salvar e aparece no PDF — achado via revisão adversarial Codex', function () {
        Storage::fake('s3');
        $examWithPhoto = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id, $examWithPhoto->id],
            'content'             => '<p>Achados.</p><p><img src="https://signed-url.example/x.jpg" data-exam-image-id="' . $examWithPhoto->id . '" alt="Foto OD" style="max-width:320px"></p>',
            'confirm_open_record' => true,
        ]);

        $response->assertCreated();
        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        // Antes, o profile 'medical' excluía <img> por completo — a imagem
        // inserida desaparecia em silêncio ao salvar. Agora a REFERÊNCIA
        // sobrevive; a src crua do cliente (poderia ser uma URL assinada já
        // expirada) nunca é confiável, é removida sempre.
        expect($doc->content)->not->toContain('https://signed-url.example/x.jpg')
            ->and($doc->content)->toContain('data-exam-image-id="' . $examWithPhoto->id . '"')
            ->and($doc->metadata['inline_images'] ?? null)->toHaveKey((string) $examWithPhoto->id);

        // contentForRender() mescla o byte congelado de volta — é isso que o PDF usa.
        expect($doc->contentForRender())->toContain('data:image/jpeg;base64,');

        actingAsDoctor($this)->get($response->json('pdf_url'))->assertOk();
    });

    it('[GAP] referência inline que não resolve (sem arquivo no S3) bloqueia com aviso, nunca emite silenciosamente incompleta — achado via revisão adversarial Codex', function () {
        Storage::fake('s3');
        $examWithoutFile = PatientExam::factory()->create([
            'patient_id'  => $this->patient->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $payload = [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$examWithoutFile->id],
            'content'             => '<p><img src="x" data-exam-image-id="' . $examWithoutFile->id . '" alt="Foto"></p>',
            'confirm_open_record' => true,
        ];

        $blocked = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), $payload);
        $blocked->assertStatus(422);
        expect($blocked->json('inline_images_incomplete_warning'))->toBeString();
        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and(MedicalRecord::count())->toBe(0);

        $confirmed = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            ...$payload,
            'confirm_inline_images_incomplete' => true,
        ]);
        $confirmed->assertCreated();
        // Emite, mas sem a referência quebrada — nunca uma <img> sem src no laudo assinado.
        expect(MedicalRecordDocumentation::query()->firstOrFail()->content)->not->toContain('data-exam-image-id');
    });

    it('[SEGURANÇA] referência inline citando exame fora da seleção do laudo é rejeitada (422), nunca aceita em silêncio', function () {
        Storage::fake('s3');
        // Exame COM foto de verdade no S3 fake — se a checagem de posse
        // (sanitizeReportContent) não rodasse, resolveria sem problema e o
        // 422 viria por outro motivo (ex.: "sem arquivo"), mascarando a
        // falta da checagem real. Com foto de verdade, só a posse pode
        // bloquear.
        $otherExam = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id], // $otherExam NÃO está na seleção
            'content'             => '<p><img src="x" data-exam-image-id="' . $otherExam->id . '" alt="Foto"></p>',
            'confirm_open_record' => true,
        ]);

        $response->assertStatus(422);
        expect($response->json('message'))->toBe(__('eye_images.report_image_not_selected'));
        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[SEGURANÇA] atributo fora do allowlist (onerror etc.) na referência inline nunca sobrevive à sanitização', function () {
        Storage::fake('s3');
        $examWithPhoto = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$examWithPhoto->id],
            'content'             => '<p><img src="x" data-exam-image-id="' . $examWithPhoto->id . '" onerror="alert(1)" alt="Foto"></p>',
            'confirm_open_record' => true,
        ]);

        $response->assertCreated();
        expect(MedicalRecordDocumentation::query()->firstOrFail()->content)->not->toContain('onerror');
    });

    it('[GAP] a mesma imagem inline referenciada várias vezes multiplica o peso esperado na renderização — bloqueia ANTES de comitar um laudo que nunca mais vai renderizar — achado via revisão adversarial Codex', function () {
        Storage::fake('s3');
        // Teto baixo só pro teste — orçamento de RESOLUÇÃO por exame único
        // (freezeInlineImages()) passaria fácil mesmo repetindo a mesma
        // imagem; o que deve estourar é o total EXPANDIDO (cada ocorrência
        // soma o peso de novo quando contentForRender() monta o HTML).
        config(['eye_image_capture.image_report.max_embedded_bytes' => 5000]);
        $examWithPhoto = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        $repeatedImage = str_repeat(
            '<img src="x" data-exam-image-id="' . $examWithPhoto->id . '" alt="Foto">',
            20,
        );

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$examWithPhoto->id],
            'content'             => '<p>Achados.</p>' . $repeatedImage,
            'confirm_open_record' => true,
        ]);

        // Antes, freezeInlineImages() só contava o orçamento 1 vez por
        // exame único — a mesma imagem 20x passava sem aviso e deixava um
        // laudo que esgotava memória em TODA tentativa futura de abrir/
        // imprimir (nunca falhava na emissão, só depois, pra sempre).
        $response->assertStatus(422);
        expect($response->json('message'))->toBe(__('eye_images.report_inline_images_too_large'));
        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[GAP] a mesma imagem inline referenciada poucas vezes, dentro do teto expandido, continua sendo aceita normalmente', function () {
        Storage::fake('s3');
        $examWithPhoto = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        $repeatedImage = str_repeat(
            '<img src="x" data-exam-image-id="' . $examWithPhoto->id . '" alt="Foto">',
            3,
        );

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$examWithPhoto->id],
            'content'             => '<p>Achados.</p>' . $repeatedImage,
            'confirm_open_record' => true,
        ]);

        $response->assertCreated();
        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect(substr_count($doc->content, 'data-exam-image-id'))->toBe(3)
            ->and($doc->contentForRender())->toContain('data:image/jpeg;base64,');
    });

    it('[GAP] laudo assinado NUNCA muda depois de emitido: desabilitar o exame não tira a imagem já congelada do PDF — achado na 2ª rodada de revisão adversarial Codex', function () {
        Storage::fake('s3');
        $exam = manualReportFakeExamWithPhoto($this->patient, $this->schedule->id);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$exam->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'image_report'        => [
                'enabled' => true,
                'images'  => [['exam_id' => $exam->id, 'caption' => 'Antes de desabilitar']],
            ],
        ])->assertCreated();

        $doc           = MedicalRecordDocumentation::query()->firstOrFail();
        $frozenDataUri = $doc->imageReportBlock()['images'][0]['data_uri'];
        expect($frozenDataUri)->toStartWith('data:image/jpeg;base64,');

        // Desabilita o exame DEPOIS de emitido — antes, isto fazia a imagem
        // desaparecer de qualquer reimpressão futura (inclusive pelo link
        // público do médico solicitante), sem nenhum aviso.
        $exam->update(['active' => false]);
        // Também apaga o arquivo do S3, pra garantir que a prova não depende
        // só da checagem active=true — mesmo sem NENHUM jeito de reler o
        // exame, o laudo já emitido continua intacto.
        Storage::disk('s3')->delete($exam->display_archive);

        $doc->refresh();
        $imageBlockOnReprint = app(ReportImageBlockBuilder::class)->build($doc);

        expect($imageBlockOnReprint)->not->toBeNull()
            ->and($imageBlockOnReprint['images'])->toHaveCount(1)
            ->and($imageBlockOnReprint['images'][0]['data_uri'])->toBe($frozenDataUri)
            ->and($imageBlockOnReprint['images'][0]['caption'])->toBe('Antes de desabilitar');
    });

    it('o bloco de imagens aparece de verdade no HTML do laudo (ReportImageBlockBuilder → blade)', function () {
        Storage::fake('s3');
        $img = imagecreatetruecolor(20, 15);
        ob_start();
        imagejpeg($img);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($img);

        $examWithPhoto = PatientExam::factory()->create([
            'patient_id'      => $this->patient->id,
            'schedule_id'     => $this->schedule->id,
            'display_archive' => 'exams/pdf-e2e_display.jpg',
        ]);
        Storage::disk('s3')->put('exams/pdf-e2e_display.jpg', $jpegBytes);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$examWithPhoto->id],
            'content'             => '<p>Achados normais.</p>',
            'confirm_open_record' => true,
            'image_report'        => [
                'enabled' => true,
                'columns' => 2,
                'images'  => [['exam_id' => $examWithPhoto->id, 'caption' => 'Legenda de teste e2e']],
            ],
        ])->assertCreated();

        $doc        = MedicalRecordDocumentation::query()->firstOrFail()->load(['patient.person', 'doctor.person', 'medicalRecord']);
        $imageBlock = app(ReportImageBlockBuilder::class)->build($doc);

        $html = view('pdf.documentation', ['doc' => $doc, 'setting' => null, 'examSummary' => [], 'imageBlock' => $imageBlock])->render();

        expect($html)->toContain('data:image/jpeg;base64,')
            ->and($html)->toContain('Legenda de teste e2e');
    });

    it('reaproveita o prontuário do MESMO agendamento em vez de criar outro', function () {
        $existing = MedicalRecord::create([
            'entity_id'   => $this->entity->id,
            'patient_id'  => $this->patient->id,
            'doctor_id'   => $this->doctor->id,
            'schedule_id' => $this->schedule->id,
        ]);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
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
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
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
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Achado normal.</p><script>alert(1)</script>',
            'confirm_open_record' => true,
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->content)->not->toContain('<script>');
    });

    it('[SEGURANÇA] secretária não emite laudo (Gate IssueReport é doctor-only)', function () {
        actingAsSecretary($this)->postJson(route('panel.exam-manager.reports.store'), [
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

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$otherExam->id],
            'content'             => '<p>Tentativa cross-tenant.</p>',
            'confirm_open_record' => true,
        ])->assertForbidden();

        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[SEGURANÇA] imagem desabilitada (active=false) é rejeitada com 422, não 403', function () {
        $this->exam->update(['active' => false]);

        $response = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Tentativa com imagem desabilitada.</p>',
            'confirm_open_record' => true,
        ]);

        $response->assertStatus(422);
        expect(MedicalRecordDocumentation::count())->toBe(0);
    });

    it('[SEGURANÇA] patient_id de outra clínica devolve 404', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $otherPatient->id,
            'content'             => '<p>Tentativa cross-tenant.</p>',
            'confirm_open_record' => true,
        ])->assertNotFound();
    });
});

describe('laudo conjunto (vários exames do mesmo paciente)', function () {
    beforeEach(function () {
        $this->oct = PatientExam::factory()->create([
            'patient_id'  => $this->patient->id,
            'schedule_id' => $this->schedule->id,
            'exam_id'     => ExamType::factory()->create(['name' => 'OCT de mácula'])->id,
            'laterality'  => 2,
        ]);
        $this->exam->examType->update(['name' => 'Retinografia']);
    });

    it('um laudo só registra todos os exames selecionados e o PDF lista cada um', function () {
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id, $this->oct->id],
            'content'             => '<p>Achados conjuntos.</p>',
            'confirm_open_record' => true,
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->sole();

        expect($doc->patientExams()->pluck('patient_exams.id')->map(fn ($id) => (string) $id)->sort()->values()->all())
            ->toBe(collect([$this->exam->id, $this->oct->id])->map(fn ($id) => (string) $id)->sort()->values()->all());

        $summary = app(ReportExamSummary::class)->forDocumentation($doc);
        expect(collect($summary)->pluck('type')->sort()->values()->all())->toBe(['OCT DE MÁCULA', 'RETINOGRAFIA'])
            ->and(collect($summary)->firstWhere('type', 'RETINOGRAFIA')['eyes'])->toBe(['OD'])
            ->and(collect($summary)->firstWhere('type', 'OCT DE MÁCULA')['eyes'])->toBe(['OE']);
    });

    it('o PDF do laudo conjunto mostra a lista de exames', function () {
        SnappyPdf::fake();

        $created = actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id, $this->oct->id],
            'content'             => '<p>Achados conjuntos.</p>',
            'confirm_open_record' => true,
        ])->assertCreated();

        actingAsDoctor($this)->get($created->json('pdf_url'))->assertOk();

        SnappyPdf::assertViewIs('pdf.documentation');
        SnappyPdf::assertSee(__('eye_images.report_exams_title'));
        SnappyPdf::assertSee('RETINOGRAFIA');
        SnappyPdf::assertSee('OCT DE MÁCULA');
    });

    it('laudo de um exame só continua funcionando e registra só ele', function () {
        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Normal.</p>',
            'confirm_open_record' => true,
        ])->assertCreated();

        expect(MedicalRecordDocumentation::query()->sole()->patientExams()->count())->toBe(1);
    });

    it('[SEGURANÇA] exame de OUTRO paciente da mesma clínica é recusado (422) e nada é gravado', function () {
        $other     = Patient::factory()->create(['entity_id' => $this->entity->id]);
        $otherExam = PatientExam::factory()->create(['patient_id' => $other->id]);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id, $otherExam->id],
            'content'             => '<p>x</p>',
            'confirm_open_record' => true,
        ])->assertStatus(422)->assertJson(['message' => __('eye_images.report_same_patient')]);

        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and(MedicalRecord::count())->toBe(0);
    });

    it('[SEGURANÇA] preview com exame de outro paciente também é recusado', function () {
        $otherExam = PatientExam::factory()->create([
            'patient_id' => Patient::factory()->create(['entity_id' => $this->entity->id])->id,
        ]);

        actingAsDoctor($this)->postJson(route('panel.exam-manager.report-templates.preview'), [
            'report_setting_content_id' => $this->content->id,
            'patient_id'                => $this->patient->id,
            'exam_ids'                  => [$otherExam->id],
        ])->assertStatus(422);
    });
});
