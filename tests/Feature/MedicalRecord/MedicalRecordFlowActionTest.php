<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, Schedule, ScheduleSituationLog, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fluxo do prontuário — Salvar ≠ Finalizar (02/09/2026).
 *
 * `flow_action` no save decide o destino do paciente: save mantém a consulta
 * aberta (volta pro edit); finish/dilate/exam transitam o agendamento
 * vinculado (Atendido/Dilatando/Em exame) e voltam pra Agenda.
 * `post_save_action` reabre o edit já com a ação da barra (?action=).
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $covenant      = Covenant::factory()->create();
    $patientPerson = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $patientPerson->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $doctorPerson     = People::factory()->create();
    $this->doctor     = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->schedule = Schedule::query()->create([
        'entity_id'  => $this->entity->id,
        'doctor_id'  => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'full_name'  => 'PACIENTE FLUXO',
        'date_time'  => now()->addHour(),
        'situation'  => ScheduleSituation::InProgress->value,
        'active'     => true,
    ]);

    $this->actingAs($this->user);
    session([
        'selected_entity_id'        => $this->entity->id,
        'selected_entity_user_id'   => $this->entityUser->id,
        'selected_entity_user_rule' => ClientRule::Doctor->value,
        'user_rule'                 => ClientRule::Doctor->value,
    ]);

    $this->payload = fn (array $extra = []) => array_merge([
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Baixa acuidade visual',
    ], $extra);

    $this->makeRecord = fn () => MedicalRecord::create([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'schedule_id'    => $this->schedule->id,
        'main_complaint' => 'Baixa acuidade visual',
    ]);
});

describe('Salvar mantém a consulta aberta', function () {
    it('1º save vindo da Agenda (schedule_id) volta pro EDIT do prontuário — não pra Agenda', function () {
        $response = $this->post(
            route('panel.patients.medicalrecords.store', $this->patient),
            ($this->payload)(['schedule_id' => $this->schedule->id]),
        );

        $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $response->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
        expect($record->schedule_id)->toBe($this->schedule->id)
            ->and($this->schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    it('update sem flow_action volta pro edit e NÃO mexe na situação do agendamento', function () {
        $record = ($this->makeRecord)();

        $response = $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'save']),
        );

        $response->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress)
            ->and(ScheduleSituationLog::where('schedule_id', $this->schedule->id)->count())->toBe(0);
    });

    it('post_save_action no 1º save reabre o edit com ?action= (emitir receita sem "salvar primeiro")', function () {
        $response = $this->post(
            route('panel.patients.medicalrecords.store', $this->patient),
            ($this->payload)(['schedule_id' => $this->schedule->id, 'post_save_action' => 'medication']),
        );

        $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->firstOrFail();

        // Vai por flash de sessão (one-shot) — nunca na URL (não replayável).
        $response->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
        $response->assertSessionHas('post_save_action', 'medication');
        expect($record->getAttributes())->not->toHaveKey('post_save_action');
    });

    it('update com schedule_id VAZIO no payload não desvincula o agendamento (regressão: form de edit mandava "")', function () {
        $record = ($this->makeRecord)();

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['schedule_id' => '', 'flow_action' => 'save']),
        )->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));

        expect($record->fresh()->schedule_id)->toBe($this->schedule->id);
    });

    it('finish com schedule_id vazio no payload ainda transita (vínculo vem do registro)', function () {
        $record = ($this->makeRecord)();

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['schedule_id' => '', 'flow_action' => 'finish']),
        )->assertRedirect(route('panel.schedules.index'));

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and($record->fresh()->schedule_id)->toBe($this->schedule->id);
    });

    it('edit expõe medicalrecord.schedule_id pro form hidratar', function () {
        $record = ($this->makeRecord)();

        $response = $this->get(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));

        expect($response->viewData('page')['props']['medicalrecord']['schedule_id'])->toBe($this->schedule->id);
    });

    it('flow_action inválido é rejeitado pela validação', function () {
        $record = ($this->makeRecord)();

        $this->from(route('panel.patients.medicalrecords.edit', [$this->patient, $record]))
            ->put(
                route('panel.patients.medicalrecords.update', [$this->patient, $record]),
                ($this->payload)(['flow_action' => 'hack']),
            )
            ->assertSessionHasErrors('flow_action');
    });
});

describe('Finalizar / Dilatar / Realizar exame', function () {
    it('finish → Atendido, log de transição e volta pra Agenda', function () {
        $record = ($this->makeRecord)();

        $response = $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'finish']),
        );

        $response->assertRedirect(route('panel.schedules.index'));
        $response->assertSessionHas('success');

        $log = ScheduleSituationLog::where('schedule_id', $this->schedule->id)->firstOrFail();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and($log->from_situation)->toBe(ScheduleSituation::InProgress)
            ->and($log->to_situation)->toBe(ScheduleSituation::Attended)
            ->and($log->entity_user_id)->toBe($this->entityUser->id)
            ->and($record->fresh()->main_complaint)->toBe('Baixa acuidade visual');
    });

    it('dilate → Dilatando (prontuário segue editável) e volta pra Agenda', function () {
        $record = ($this->makeRecord)();

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'dilate']),
        )->assertRedirect(route('panel.schedules.index'));

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Dilating)
            ->and($record->fresh()->is_locked)->toBeFalse();
    });

    it('exam → Em exame e volta pra Agenda', function () {
        $record = ($this->makeRecord)();

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'exam']),
        )->assertRedirect(route('panel.schedules.index'));

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Exam);
    });

    it('finish já no 1º save (create vindo da Agenda) também finaliza', function () {
        $this->post(
            route('panel.patients.medicalrecords.store', $this->patient),
            ($this->payload)(['schedule_id' => $this->schedule->id, 'flow_action' => 'finish']),
        )->assertRedirect(route('panel.schedules.index'));

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('finish com trava de caixa da clínica: prontuário salvo, status NÃO muda, volta pro edit com aviso', function () {
        $this->entity->update(['requires_cash_to_complete' => true]);
        $record = ($this->makeRecord)();

        $response = $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'finish', 'main_complaint' => 'Atualizada antes de finalizar']),
        );

        $response->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success'); // um único aviso, sem toast verde junto

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress)
            ->and($record->fresh()->main_complaint)->toBe('Atualizada antes de finalizar');
    });

    it('agendamento já terminal (recepção marcou Cancelado numa aba antiga): salva, NÃO reabre e avisa', function () {
        $this->schedule->update(['situation' => ScheduleSituation::Cancelled->value]);
        $record = ($this->makeRecord)();

        $response = $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'dilate', 'main_complaint' => 'Salvo mesmo assim']),
        );

        $response->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
        $response->assertSessionHas('error');

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Cancelled)
            ->and(ScheduleSituationLog::where('schedule_id', $this->schedule->id)->count())->toBe(0)
            ->and($record->fresh()->main_complaint)->toBe('Salvo mesmo assim');
    });

    it('prontuário SEM agendamento vinculado ignora flow_action e volta pro edit', function () {
        $record = MedicalRecord::create([
            'entity_id'      => $this->entity->id,
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'main_complaint' => 'Sem agenda',
        ]);

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'finish']),
        )->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
    });

    it('[SEGURANÇA] agendamento de OUTRA clínica vinculado ao prontuário nunca transita', function () {
        $otherEntity    = Entity::factory()->create(['is_client' => true]);
        $otherUser      = User::factory()->create();
        $otherEu        = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);
        $otherDocPerson = People::factory()->create();
        $otherDoctor    = Doctor::create([
            'entity_user_id' => $otherEu->id,
            'person_id'      => $otherDocPerson->id,
            'record'         => '99999',
            'color'          => '#00FF00',
            'partner'        => false,
            'active'         => true,
        ]);
        $foreign = Schedule::query()->create([
            'entity_id' => $otherEntity->id,
            'doctor_id' => $otherDoctor->id,
            'full_name' => 'OUTRA CLINICA',
            'date_time' => now()->addHour(),
            'situation' => ScheduleSituation::InProgress->value,
            'active'    => true,
        ]);

        $record = MedicalRecord::create([
            'entity_id'      => $this->entity->id,
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'schedule_id'    => $foreign->id,
            'main_complaint' => 'x',
        ]);

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)(['flow_action' => 'finish']),
        )->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));

        expect($foreign->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    it('[SEGURANÇA] schedule_id de OUTRA clínica no create é rejeitado pela validação (exists escopado por tenant)', function () {
        $otherEntity = Entity::factory()->create(['is_client' => true]);
        $otherUser   = User::factory()->create();
        $otherEu     = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);
        $otherDoctor = Doctor::create([
            'entity_user_id' => $otherEu->id,
            'person_id'      => People::factory()->create()->id,
            'record'         => '77777',
            'color'          => '#0000FF',
            'partner'        => false,
            'active'         => true,
        ]);
        $foreign = Schedule::query()->create([
            'entity_id' => $otherEntity->id,
            'doctor_id' => $otherDoctor->id,
            'full_name' => 'OUTRA CLINICA',
            'date_time' => now()->addHours(2),
            'situation' => ScheduleSituation::Waiting->value,
            'active'    => true,
        ]);

        $this->from(route('panel.patients.medicalrecords.create', $this->patient))
            ->post(
                route('panel.patients.medicalrecords.store', $this->patient),
                ($this->payload)(['schedule_id' => $foreign->id]),
            )
            ->assertSessionHasErrors('schedule_id');

        expect(MedicalRecord::where('patient_id', $this->patient->id)->exists())->toBeFalse();
    });
});

describe('Props do formulário', function () {
    it('edit expõe urls.schedules (destino de Finalizar/Dilatar/Exame)', function () {
        $record = ($this->makeRecord)();

        $response = $this->get(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
        $response->assertOk();

        expect($response->viewData('page')['props']['urls']['schedules'])->toBe(route('panel.schedules.index'));
    });
});
