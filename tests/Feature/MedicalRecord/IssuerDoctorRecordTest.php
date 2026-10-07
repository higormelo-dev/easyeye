<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DocumentationType, FeatureKey, SubscriptionStatus};
use App\Models\{Covenant, Entity, MedicalRecord, MedicalRecordDocumentation, MedicalRecordEvolution, MedicalRecordFile, MedicalRecordProcedure, Patient, PatientExam, People, Plan, PlanFeature, Procedure, ReportSettingContent, Subscription, User};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Documento só por médico cadastrado (Gate IssueReport + App\Support\CurrentDoctor):
 *  - perfil médico SEM cadastro de médico ATIVO não emite nada (403 com a
 *    mensagem traduzida) — antes o documento saía no nome do médico do
 *    prontuário/exame;
 *  - quem emite assina: documento/evolução/procedimento/laudo sempre no nome
 *    do médico LOGADO, nunca no do prontuário ou do exame;
 *  - prontuário no nome do próprio médico (RecordDoctor);
 *  - prontuário ASSINADO: nenhum documento/anexo é emitido, alterado ou
 *    excluído nele (GuardedBySignedRecord).
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    // Médica A (dona do prontuário) e médico B (outro médico da clínica).
    $this->userA = User::factory()->create();
    $this->euA   = createEntityUser($this->entity, $this->userA, ClientRule::Doctor->value);
    $this->drA   = doctorRecordForEntityUser($this->euA);

    $this->userB = User::factory()->create();
    $this->euB   = createEntityUser($this->entity, $this->userB, ClientRule::Doctor->value);
    $this->drB   = doctorRecordForEntityUser($this->euB);

    // Perfil médico sem cadastro e médico com cadastro INATIVO.
    $this->orphanUser = User::factory()->create();
    $this->euOrphan   = createEntityUser($this->entity, $this->orphanUser, ClientRule::Doctor->value);

    $this->inactiveUser = User::factory()->create();
    $this->euInactive   = createEntityUser($this->entity, $this->inactiveUser, ClientRule::Doctor->value);
    doctorRecordForEntityUser($this->euInactive, active: false);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);

    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id, 'main_complaint' => 'Consulta',
    ]);
});

function issAs(object $test, $entityUser): object
{
    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

describe('emissão exige cadastro de médico ativo', function () {
    it('perfil médico sem cadastro (ou com cadastro inativo) é barrado em todos os fluxos de emissão', function () {
        Storage::fake();
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id]);
        $tpl  = ReportSettingContent::query()->where('slug', 'declaracao_medica')->firstOrFail();

        $calls = [
            ['postJson', route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medical-declaration']), ['content' => '']],
            ['postJson', route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]), ['report_setting_content_id' => $tpl->id, 'content' => '<p>x</p>']],
            ['postJson', route('panel.patients.medicalrecords.tonometry.store', [$this->patient, $this->record]), ['od' => 14, 'oe' => 15, 'time' => '10:00']],
            ['postJson', route('panel.patients.medicalrecords.evolutions.store', [$this->patient, $this->record]), ['content' => 'Evolução']],
            ['postJson', route('panel.patients.medicalrecords.files.store', [$this->patient, $this->record]), ['files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]],
            ['getJson', route('panel.patients.tonometry-pdf', [$this->patient]), []],
            ['postJson', route('panel.eye-images.reports.store'), ['patient_id' => $this->patient->id, 'exam_ids' => [$exam->id], 'content' => '<p>Laudo</p>', 'confirm_open_record' => true]],
            ['postJson', route('panel.patients.medicalrecords.store', $this->patient), ['main_complaint' => 'Nova']],
        ];

        foreach ([$this->euOrphan, $this->euInactive] as $eu) {
            foreach ($calls as [$method, $url, $data]) {
                $response = issAs($this, $eu)->{$method}($url, $data);
                expect($response->status())->toBeIn([403, 422], "{$method} {$url}");
                expect(in_array(__('schedules.doctor_missing'), [$response->json('message'), $response->json('errors.doctor_id.0')], true))
                    ->toBeTrue("{$method} {$url} => " . $response->getContent());
            }
        }

        expect(MedicalRecordDocumentation::count())->toBe(0)
            ->and(MedicalRecordEvolution::count())->toBe(0)
            ->and(MedicalRecordFile::count())->toBe(0)
            ->and(MedicalRecord::count())->toBe(1);
    });

    it('aprovação de laudo de IA por perfil médico sem cadastro: 403 com a mensagem e o run segue aguardando', function () {
        $plan = Plan::factory()->create(['active' => true]);
        PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($plan)->create();
        Subscription::factory()->create([
            'entity_id' => $this->entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $run = AiRun::query()->create([
            'entity_id'    => $this->entity->id, 'requested_by' => $this->userA->id, 'workflow' => 'report_drafting',
            'mode'         => AiRunMode::Validated->value, 'risk_level' => AiRiskLevel::Medium->value,
            'status'       => AiRunStatus::WaitingApproval->value, 'estimated_credits' => 1, 'reserved_credits' => 1,
            'final_output' => 'Rascunho',
        ]);

        issAs($this, $this->euOrphan)
            ->postJson(route('panel.ai-runs.approve', $run), ['final_output' => 'ok'])
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));

        expect($run->fresh()->status)->toBe(AiRunStatus::WaitingApproval);
    });

    it('a tela esconde as ações e mostra o aviso (prontuário e Gerenciador de Imagens)', function () {
        issAs($this, $this->euOrphan)
            ->get(route('panel.patients.medicalrecords.index', $this->patient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isDoctor', false)
                ->where('doctorMissing', true)
                ->where('doctorMissingText', __('schedules.doctor_missing')));

        issAs($this, $this->euOrphan)
            ->get(route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('isDoctor', false)->where('doctorMissing', true)->where('doctors', []));

        issAs($this, $this->euA)
            ->get(route('panel.patients.medicalrecords.index', $this->patient))
            ->assertInertia(fn (Assert $page) => $page->where('isDoctor', true)->where('doctorMissing', false));
    });
});

describe('quem emite assina', function () {
    it('médico B emitindo no prontuário da médica A: documento, evolução, procedimento e tonometria saem no nome de B', function () {
        SnappyPdf::fake();
        $tpl  = ReportSettingContent::query()->where('slug', 'declaracao_medica')->firstOrFail();
        $proc = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);

        issAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medical-declaration']), ['content' => ''])
            ->assertCreated();
        // store_doc da tela (antes: ArgumentCountError → 500).
        issAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]), [
            'report_setting_content_id' => $tpl->id, 'content' => '<p>Declaro.</p>',
        ])->assertCreated();
        // doctor_id de colega no request não escolhe o autor.
        issAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.tonometry.store', [$this->patient, $this->record]), [
            'od' => 14, 'oe' => 15, 'time' => '10:00', 'doctor_id' => $this->drA->id,
        ])->assertCreated();
        issAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.evolutions.store', [$this->patient, $this->record]), ['content' => 'Melhora'])
            ->assertCreated();
        issAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.medicalrecord-procedures.store', [$this->patient, $this->record]), [
            'procedure_id' => $proc->id,
        ])->assertCreated();

        expect(MedicalRecordDocumentation::query()->pluck('doctor_id')->unique()->values()->all())->toBe([$this->drB->id])
            ->and(MedicalRecordDocumentation::count())->toBe(3)
            ->and(MedicalRecordEvolution::query()->sole()->doctor_id)->toBe($this->drB->id)
            ->and(MedicalRecordProcedure::query()->sole()->doctor_id)->toBe($this->drB->id);
    });

    it('laudo do Gerenciador de Imagens sai no nome de quem emite, não do médico do exame', function () {
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id]);

        issAs($this, $this->euB)->postJson(route('panel.eye-images.reports.store'), [
            'patient_id' => $this->patient->id, 'exam_ids' => [$exam->id], 'content' => '<p>Normal.</p>', 'confirm_open_record' => true,
        ])->assertCreated();

        expect(MedicalRecordDocumentation::query()->sole()->doctor_id)->toBe($this->drB->id);
    });
});

describe('prontuário no nome do próprio médico', function () {
    it('novo prontuário: doctor_id de colega é recusado; sem doctor_id, sai no do próprio médico', function () {
        issAs($this, $this->euB)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), ['doctor_id' => $this->drA->id, 'main_complaint' => 'X'])
            ->assertSessionHasErrors(['doctor_id' => __('actions.medical_records.doctor_only_own')]);

        issAs($this, $this->euB)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), ['main_complaint' => 'Y'])
            ->assertSessionHasNoErrors();

        expect(MedicalRecord::query()->where('main_complaint', 'Y')->value('doctor_id'))->toBe($this->drB->id)
            ->and(MedicalRecord::query()->where('main_complaint', 'X')->exists())->toBeFalse();
    });

    it('edição: médico B não altera nem "toma" o prontuário da médica A; A edita o dela', function () {
        foreach ([$this->drA->id, $this->drB->id] as $doctorId) {
            issAs($this, $this->euB)
                ->put(route('panel.patients.medicalrecords.update', [$this->patient, $this->record]), ['doctor_id' => $doctorId, 'main_complaint' => 'Alterado por B'])
                ->assertSessionHasErrors(['doctor_id' => __('actions.medical_records.record_other_doctor')]);
        }

        expect($this->record->fresh())
            ->main_complaint->toBe('Consulta')
            ->doctor_id->toBe($this->drA->id);

        issAs($this, $this->euA)
            ->put(route('panel.patients.medicalrecords.update', [$this->patient, $this->record]), ['main_complaint' => 'Alterado por A'])
            ->assertSessionHasNoErrors();

        expect($this->record->fresh()->main_complaint)->toBe('Alterado por A');
    });

    it('o seletor de médico do formulário traz só o próprio médico', function () {
        issAs($this, $this->euB)
            ->get(route('panel.patients.medicalrecords.create', $this->patient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentDoctorId', $this->drB->id)
                ->where('doctors', fn ($doctors) => collect($doctors)->pluck('id')->all() === [$this->drB->id]));
    });

    it('admin e secretária continuam sem criar/editar prontuário (rota só de médico)', function () {
        foreach ([ClientRule::Admin, ClientRule::Secretary] as $rule) {
            $eu = createEntityUser($this->entity, User::factory()->create(), $rule->value);
            issAs($this, $eu)->postJson(route('panel.patients.medicalrecords.store', $this->patient), ['doctor_id' => $this->drA->id, 'main_complaint' => 'Z'])
                ->assertForbidden();
        }
    });
});

describe('prontuário assinado', function () {
    beforeEach(function () {
        Storage::fake();
        $this->doc = MedicalRecordDocumentation::create([
            'medical_record_id' => $this->record->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id,
            'type'              => DocumentationType::Report, 'title' => 'Antes da assinatura', 'content' => '<p>x</p>',
        ]);
        $this->file = MedicalRecordFile::create([
            'medical_record_id' => $this->record->id, 'patient_id' => $this->patient->id, 'file_path' => 'x.pdf',
            'original_name'     => 'x.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        ]);
        $this->record->sign($this->euA);
    });

    it('nenhum endpoint emite, altera ou exclui documento/anexo nele (403 traduzido)', function () {
        $tpl  = ReportSettingContent::query()->where('slug', 'declaracao_medica')->firstOrFail();
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id]);
        $this->record->forceFill(['schedule_id' => null])->saveQuietly();

        $calls = [
            ['postJson', route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medical-declaration']), ['content' => '']],
            ['postJson', route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]), ['report_setting_content_id' => $tpl->id, 'content' => '<p>x</p>']],
            ['postJson', route('panel.patients.medicalrecords.tonometry.store', [$this->patient, $this->record]), ['od' => 14, 'oe' => 15, 'time' => '10:00']],
            ['deleteJson', route('panel.patients.medicalrecords.documentations.destroy', [$this->patient, $this->record, $this->doc]), []],
            ['postJson', route('panel.patients.medicalrecords.files.store', [$this->patient, $this->record]), ['files' => [UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]]],
            ['deleteJson', route('panel.patients.medicalrecords.files.destroy', [$this->patient, $this->record, $this->file]), []],
            // Laudo do Gerenciador de Imagens cairia no prontuário assinado do dia.
            ['postJson', route('panel.eye-images.reports.store'), ['patient_id' => $this->patient->id, 'exam_ids' => [$exam->id], 'content' => '<p>Laudo</p>']],
        ];

        foreach ($calls as [$method, $url, $data]) {
            issAs($this, $this->euA)->{$method}($url, $data)
                ->assertForbidden()
                ->assertJsonPath('message', __('actions.medical_records.documentation_locked'));
        }

        expect(MedicalRecordDocumentation::count())->toBe(1)
            ->and(MedicalRecordDocumentation::find($this->doc->id))->not->toBeNull()
            ->and(MedicalRecordFile::count())->toBe(1)
            ->and(MedicalRecordFile::find($this->file->id))->not->toBeNull();
    });

    it('laudo de IA aprovado para prontuário assinado: 403 e o run continua aguardando aprovação', function () {
        $plan = Plan::factory()->create(['active' => true]);
        PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($plan)->create();
        Subscription::factory()->create([
            'entity_id' => $this->entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $run = AiRun::query()->create([
            'entity_id'    => $this->entity->id, 'requested_by' => $this->userA->id, 'workflow' => 'report_drafting',
            'mode'         => AiRunMode::Validated->value, 'risk_level' => AiRiskLevel::Medium->value,
            'status'       => AiRunStatus::WaitingApproval->value, 'estimated_credits' => 1, 'reserved_credits' => 1,
            'final_output' => 'Rascunho', 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        ]);

        issAs($this, $this->euA)
            ->postJson(route('panel.ai-runs.approve', $run), ['final_output' => 'Aprovado'])
            ->assertForbidden()
            ->assertJsonPath('message', __('actions.medical_records.documentation_locked'));

        expect($run->fresh()->status)->toBe(AiRunStatus::WaitingApproval)
            ->and(MedicalRecordDocumentation::count())->toBe(1);
    });
});
