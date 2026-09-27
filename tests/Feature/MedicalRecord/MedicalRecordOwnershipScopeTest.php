<?php

declare(strict_types=1);

/**
 * IDs vindos do cliente sem escopo de dono (mesmo padrão do reference_id do
 * caixa), no prontuário:
 *  - F4: schedule_id só era escopado por clínica — prontuário do paciente A
 *    podia ser vinculado ao agendamento do paciente B (Finalizar/Dilatar mexia
 *    na agenda de B e o "Atender" de B abria o prontuário de A => 404);
 *  - F7: preview de template resolvia doctor_id de QUALQUER clínica (nome/CRM
 *    de médico alheio) e valor não-UUID virava 500;
 *  - F8: PDF de tonometria filtrava doctors.entity_id (coluna inexistente) =>
 *    500 sempre que doctor_id vinha; mensagem sem tradução;
 *  - F10: restaurar prontuário não conferia paciente e ficava fora da regra
 *    "só médico escreve/exclui" (CFM 2.227/2018).
 */

use App\Enums\{ClientRule, DocumentationType, ReportSettingStatus, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, EntityUser, MedicalRecord, Patient, People, ReportCategory, ReportSetting, ReportSettingContent, Schedule, User};
use Barryvdh\Snappy\Facades\SnappyPdf;

function mrosPatient(Entity $entity): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

function mrosDoctor(EntityUser $entityUser, string $record): Doctor
{
    return Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => $record,
        'color'          => '#' . substr(md5($record), 0, 6),
        'partner'        => false,
        'active'         => true,
    ]);
}

function mrosSchedule(Entity $entity, Doctor $doctor, Patient $patient, int $hours): Schedule
{
    return Schedule::query()->create([
        'entity_id'  => $entity->id,
        'doctor_id'  => $doctor->id,
        'patient_id' => $patient->id,
        'full_name'  => 'PACIENTE ' . $hours,
        'date_time'  => now()->addHours($hours),
        'situation'  => ScheduleSituation::InProgress->value,
        'active'     => true,
    ]);
}

/** Captura o médico que iria para o PDF da tonometria, sem depender do wkhtmltopdf. */
function mrosCapturePdfDoctor(): stdClass
{
    $captured = (object) ['doctor' => null, 'rendered' => false];
    $pdf      = Mockery::mock();
    $pdf->shouldReceive('setPaper', 'setOption')->andReturnSelf();
    $pdf->shouldReceive('inline')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

    SnappyPdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data) use ($captured, $pdf) {
        $captured->doctor   = $data['doctor'] ?? null;
        $captured->rendered = true;

        return $pdf;
    });

    return $captured;
}

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);

    $this->doctorUser       = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);
    $this->doctor           = mrosDoctor($this->doctorEntityUser, '12345');

    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);

    $this->patient      = mrosPatient($this->entity);
    $this->otherPatient = mrosPatient($this->entity);

    $this->schedule      = mrosSchedule($this->entity, $this->doctor, $this->patient, 1);
    $this->otherSchedule = mrosSchedule($this->entity, $this->doctor, $this->otherPatient, 2);

    $this->otherEntity = Entity::factory()->create(['is_client' => true]);
    $this->otherDoctor = mrosDoctor(createEntityUser($this->otherEntity, User::factory()->create(), ClientRule::Doctor->value), '99999');

    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession(panelSession($this->doctorEntityUser));
    $this->asAdmin  = fn () => $this->actingAs($this->adminUser)->withSession(panelSession($this->adminEntityUser));

    $this->makeRecord = fn (array $overrides = []) => MedicalRecord::create(array_merge([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'schedule_id'    => $this->schedule->id,
        'main_complaint' => 'Baixa acuidade visual',
    ], $overrides));
});

describe('F4 — schedule_id precisa ser do paciente da rota', function () {
    it('create: agendamento de OUTRO paciente da mesma clínica é recusado com mensagem traduzida', function () {
        ($this->asDoctor)()
            ->from(route('panel.patients.medicalrecords.create', $this->patient))
            ->post(route('panel.patients.medicalrecords.store', $this->patient), [
                'doctor_id'      => $this->doctor->id,
                'main_complaint' => 'Baixa acuidade visual',
                'schedule_id'    => $this->otherSchedule->id,
            ])
            ->assertSessionHasErrors(['schedule_id' => __('actions.medical_records.schedule_exists_validation')]);

        expect(MedicalRecord::query()->where('patient_id', $this->patient->id)->exists())->toBeFalse();
    });

    it('create: agendamento do próprio paciente continua vinculando', function () {
        ($this->asDoctor)()
            ->post(route('panel.patients.medicalrecords.store', $this->patient), [
                'doctor_id'      => $this->doctor->id,
                'main_complaint' => 'Baixa acuidade visual',
                'schedule_id'    => $this->schedule->id,
            ])
            ->assertSessionHasNoErrors();

        expect(MedicalRecord::query()->where('patient_id', $this->patient->id)->value('schedule_id'))->toBe($this->schedule->id);
    });

    it('update: trocar para agendamento de outro paciente é recusado e o vínculo original fica', function () {
        $record = ($this->makeRecord)();

        ($this->asDoctor)()
            ->from(route('panel.patients.medicalrecords.edit', [$this->patient, $record]))
            ->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
                'doctor_id'      => $this->doctor->id,
                'main_complaint' => 'Baixa acuidade visual',
                'schedule_id'    => $this->otherSchedule->id,
                'flow_action'    => 'save',
            ])
            ->assertSessionHasErrors('schedule_id');

        expect($record->fresh()->schedule_id)->toBe($this->schedule->id);
    });

    it('update: vínculo legado (já gravado) reenviado pelo form não impede o médico de salvar', function () {
        $record = ($this->makeRecord)(['schedule_id' => $this->otherSchedule->id]);

        ($this->asDoctor)()
            ->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
                'doctor_id'      => $this->doctor->id,
                'main_complaint' => 'Queixa atualizada',
                'schedule_id'    => $this->otherSchedule->id,
                'flow_action'    => 'save',
            ])
            ->assertSessionHasNoErrors();

        expect($record->fresh()->main_complaint)->toBe('Queixa atualizada');
    });
});

describe('F7 — preview de template só resolve médico da clínica ativa', function () {
    beforeEach(function () {
        $setting = ReportSetting::create([
            'entity_id'          => $this->entity->id,
            'report_category_id' => ReportCategory::firstOrCreate(['slug' => 'mros-fixture'], ['name' => 'Fixture'])->id,
            'title'              => 'ATESTADO (fixture)',
            'active'             => true,
            'status'             => ReportSettingStatus::Published,
        ]);

        $this->content = ReportSettingContent::create([
            'report_setting_id' => $setting->id,
            'type'              => DocumentationType::Report,
            'slug'              => 'mros-fixture',
            'label'             => 'Padrão',
            'content'           => '<p>Médico: {{MEDICO_NOME}}</p>',
            'active'            => true,
        ]);

        // Caminho real em que o doctor_id do request entra: o médico do
        // prontuário foi excluído (soft delete) e a relação volta null.
        $retired = mrosDoctor(createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value), '55555');
        $record  = ($this->makeRecord)(['doctor_id' => $retired->id]);
        $retired->delete();

        $this->previewUrl = route('panel.patients.medicalrecords.template-preview', [$this->patient, $record]);
    });

    it('doctor_id de OUTRA clínica não vaza nome do médico alheio: 422 traduzido', function () {
        $response = ($this->asAdmin)()->postJson($this->previewUrl, [
            'report_setting_content_id' => $this->content->id,
            'doctor_id'                 => $this->otherDoctor->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', __('actions.medical_records.doctor_required_for_template'));

        expect($response->getContent())->not->toContain((string) $this->otherDoctor->person->full_name);
    });

    it('doctor_id da própria clínica resolve o nome no template', function () {
        ($this->asAdmin)()->postJson($this->previewUrl, [
            'report_setting_content_id' => $this->content->id,
            'doctor_id'                 => $this->doctor->id,
        ])
            ->assertOk()
            ->assertJsonPath('content', '<p>Médico: ' . $this->doctor->person->full_name . '</p>');
    });

    it('médico do prontuário excluído e nenhum no request: 422 traduzido (antes: TypeError 500)', function () {
        ($this->asAdmin)()->postJson($this->previewUrl, ['report_setting_content_id' => $this->content->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('actions.medical_records.doctor_required_for_template'));
    });

    it('doctor_id que não é UUID vira erro de validação (antes: 500 do banco)', function () {
        ($this->asAdmin)()->postJson($this->previewUrl, [
            'report_setting_content_id' => $this->content->id,
            'doctor_id'                 => 'abc',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('doctor_id');
    });
});

describe('F8 — PDF de tonometria', function () {
    it('admin informando médico da clínica gera o PDF com esse médico (antes: 500, coluna inexistente)', function () {
        $captured = mrosCapturePdfDoctor();

        ($this->asAdmin)()
            ->get(route('panel.patients.tonometry-pdf', [$this->patient, 'doctor_id' => $this->doctor->id]))
            ->assertOk();

        expect($captured->doctor?->id)->toBe($this->doctor->id);
    });

    it('médico de OUTRA clínica é ignorado: sem médico próprio, 422 traduzido e nenhum PDF gerado', function () {
        $captured = mrosCapturePdfDoctor();

        ($this->asAdmin)()
            ->getJson(route('panel.patients.tonometry-pdf', [$this->patient, 'doctor_id' => $this->otherDoctor->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('actions.medical_records.doctor_required_for_print'));

        expect($captured->rendered)->toBeFalse();
    });
});

describe('F10 — restaurar prontuário', function () {
    it('médico restaura prontuário excluído do próprio paciente', function () {
        $record = ($this->makeRecord)();
        $record->delete();

        ($this->asDoctor)()
            ->patch(route('panel.patients.medicalrecords.restore', [$this->patient, $record->id]))
            ->assertRedirect(route('panel.patients.medicalrecords.index', $this->patient));

        expect(MedicalRecord::query()->find($record->id))->not->toBeNull();
    });

    it('prontuário de OUTRO paciente pela URL deste paciente: 404 e continua excluído', function () {
        $record = ($this->makeRecord)(['patient_id' => $this->otherPatient->id, 'schedule_id' => $this->otherSchedule->id]);
        $record->delete();

        ($this->asDoctor)()
            ->patch(route('panel.patients.medicalrecords.restore', [$this->patient, $record->id]))
            ->assertNotFound();

        expect(MedicalRecord::query()->find($record->id))->toBeNull();
    });

    it('admin/secretária não restauram (mesma regra do excluir: só médico)', function () {
        $record = ($this->makeRecord)();
        $record->delete();

        ($this->asAdmin)()
            ->patchJson(route('panel.patients.medicalrecords.restore', [$this->patient, $record->id]))
            ->assertForbidden();

        expect(MedicalRecord::query()->find($record->id))->toBeNull();
    });

    it('médico de OUTRA clínica não restaura (404) e id inválido não vira 500', function () {
        $record = ($this->makeRecord)();
        $record->delete();

        $otherEntityUser = $this->otherDoctor->entityUser;

        $this->actingAs($otherEntityUser->user)->withSession(panelSession($otherEntityUser))
            ->patch(route('panel.patients.medicalrecords.restore', [$this->patient, $record->id]))
            ->assertNotFound();

        ($this->asDoctor)()
            ->patch(route('panel.patients.medicalrecords.restore', [$this->patient, 'abc']))
            ->assertNotFound();

        expect(MedicalRecord::query()->find($record->id))->toBeNull();
    });
});
