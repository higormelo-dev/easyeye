<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, ImportStatus, ScheduleSituation, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, PatientImport, People, Plan, PlanFeature, Schedule, ScheduleImport, Subscription, User, VisitType, WaitingList};
use App\Services\{PatientImportService, ScheduleImportService};
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Convênio inativo aceito em agendamento: a tela escondia (só ativos), mas o
 * POST/PUT direto gravava. Regra: vínculo NOVO (agendamento novo, troca de
 * convênio, entrada na lista de espera, remarcação que cria agendamento,
 * importação, paciente novo/troca de convênio do paciente) só com convênio
 * ATIVO. Reenviar o convênio que o registro JÁ tem passa mesmo inativado —
 * editar outro campo não trava a recepção. Lote de situação/data não mexe em
 * convênio e segue liberado.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);

    $user         = User::factory()->create();
    $this->member = createEntityUser($this->entity, $user, ClientRule::Secretary->value);
    $this->actingAs($user)->withSession(panelSession($this->member));

    $this->active   = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'ATIVO SAUDE', 'active' => true]);
    $this->inactive = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'INATIVO SAUDE', 'active' => false]);

    $this->when = now()->addDays(2)->setTime(9, 0);
});

function f0SchedulePayload(object $test, array $extra = []): array
{
    return array_merge([
        'doctor_id' => $test->doctor->id,
        'full_name' => 'PACIENTE CONVENIO',
        'date_time' => $test->when->format('Y-m-d H:i'),
    ], $extra);
}

describe('Agenda', function () {
    it('agendamento novo com convênio inativo é recusado (422 em covenant_id); ativo passa', function () {
        $this->postJson(route('panel.schedules.store'), f0SchedulePayload($this, ['covenant_id' => $this->inactive->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.covenant_id.0', __('validation.custom.covenant.inactive'));

        expect(Schedule::count())->toBe(0);

        $this->postJson(route('panel.schedules.store'), f0SchedulePayload($this, ['covenant_id' => $this->active->id]))
            ->assertCreated();
    });

    it('recorrência com convênio inativo não cria nenhuma ocorrência', function () {
        $this->postJson(route('panel.schedules.store'), f0SchedulePayload($this, [
            'covenant_id'      => $this->inactive->id,
            'recurrence_type'  => 'weekly',
            'recurrence_until' => $this->when->copy()->addWeeks(3)->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['covenant_id']);

        expect(Schedule::count())->toBe(0);
    });

    it('editar: trocar para convênio inativo é recusado; manter o já inativado e mudar a observação passa', function () {
        $schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'covenant_id' => $this->active->id,
            'full_name' => 'PACIENTE ANTIGO', 'date_time' => $this->when, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);

        $this->putJson(route('panel.schedules.update', $schedule), f0SchedulePayload($this, ['covenant_id' => $this->inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['covenant_id']);
        expect($schedule->fresh()->covenant_id)->toBe($this->active->id);

        // Convênio do agendamento inativado DEPOIS: editar outro campo continua permitido.
        $this->active->update(['active' => false]);

        $this->putJson(route('panel.schedules.update', $schedule), f0SchedulePayload($this, [
            'covenant_id' => $this->active->id,
            'notes'       => 'Paciente pediu horário cedo',
        ]))->assertOk();

        expect($schedule->fresh())
            ->covenant_id->toBe($this->active->id)
            ->notes->toBe('Paciente pediu horário cedo');
    });

    it('o formulário de edição recebe o convênio atual (id, nome e se está ativo) para não apagá-lo', function () {
        $schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'covenant_id' => $this->inactive->id,
            'full_name' => 'PACIENTE ANTIGO', 'date_time' => $this->when, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);

        $this->getJson(route('panel.schedules.show', $schedule))
            ->assertOk()
            ->assertJsonPath('data.covenant_id', $this->inactive->id)
            ->assertJsonPath('data.covenant_name', 'INATIVO SAUDE')
            ->assertJsonPath('data.covenant_active', false)
            ->assertJsonPath('data.full_name', 'PACIENTE ANTIGO')
            ->assertJsonPath('data.date_time_iso', $this->when->format('Y-m-d\TH:i'));
    });

    it('remarcar (cria agendamento novo) com convênio inativado é recusado com mensagem; com ativo remarca', function () {
        $make = fn (string $covenantId, string $name, $at) => Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'covenant_id' => $covenantId,
            'full_name' => $name, 'date_time' => $at, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);

        $old = $make($this->inactive->id, 'REMARCAR INATIVO', $this->when);

        $this->postJson(route('panel.schedules.reschedule', $old), ['date_time' => $this->when->copy()->addDay()->format('Y-m-d H:i')])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('validation.custom.covenant.reschedule_inactive', ['name' => 'INATIVO SAUDE']));

        expect(Schedule::count())->toBe(1)
            ->and($old->fresh()->situation)->toBe(ScheduleSituation::Scheduled);

        $ok = $make($this->active->id, 'REMARCAR ATIVO', $this->when->copy()->addHour());

        $this->postJson(route('panel.schedules.reschedule', $ok), ['date_time' => $this->when->copy()->addDays(3)->format('Y-m-d H:i')])
            ->assertCreated();
    });

    it('lote (situação e data) não mexe em convênio e segue liberado para agendamento com convênio inativado', function () {
        $schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'covenant_id' => $this->inactive->id,
            'full_name' => 'LOTE', 'date_time' => $this->when, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);

        $this->postJson(route('panel.schedules.bulk-update'), ['ids' => [$schedule->id], 'situation' => ScheduleSituation::Confirmed->value])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => [$schedule->id], 'date' => $this->when->copy()->addDays(5)->toDateString()])
            ->assertOk()->assertJsonPath('updated', 1);

        expect($schedule->fresh()->covenant_id)->toBe($this->inactive->id);
    });
});

describe('Lista de espera', function () {
    it('entrada nova com convênio inativo é recusada', function () {
        $this->postJson(route('panel.waiting-list.store'), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'ESPERA', 'covenant_id' => $this->inactive->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['covenant_id']);

        expect(WaitingList::count())->toBe(0);
    });

    it('converter entrada antiga (convênio inativado depois) em agendamento é recusado e a entrada continua na fila', function () {
        $entry = WaitingList::create([
            'entity_id'   => $this->entity->id, 'doctor_id' => $this->doctor->id, 'full_name' => 'ESPERA ANTIGA',
            'covenant_id' => $this->inactive->id, 'position' => 1, 'active' => true,
        ]);

        $this->postJson(route('panel.schedules.store'), f0SchedulePayload($this, [
            'covenant_id'     => $this->inactive->id,
            'waiting_list_id' => $entry->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['covenant_id']);

        expect($entry->fresh()->active)->toBeTrue()
            ->and(Schedule::count())->toBe(0);
    });
});

describe('Paciente (convênio padrão)', function () {
    beforeEach(function () {
        $this->patientPayload = fn (array $extra = []) => array_merge([
            'name'              => 'PACIENTE NOVO CONVENIO',
            'birth_date'        => '1990-05-10',
            'gender'            => 1,
            'marital_status'    => 1,
            'email'             => 'convenio' . uniqid() . '@example.com',
            'national_registry' => (string) random_int(10000000000, 99999999999),
            'cellphone'         => '11999990000',
            'whatsapp'          => false,
        ], $extra);
    });

    it('cadastro novo com convênio inativo é recusado; ativo passa', function () {
        $this->postJson(route('panel.patients.store'), ($this->patientPayload)(['covenant_id' => $this->inactive->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.covenant_id.0', __('validation.custom.covenant.inactive'));

        $this->postJson(route('panel.patients.store'), ($this->patientPayload)(['covenant_id' => $this->active->id]))
            ->assertOk();
    });

    it('edição: trocar para inativo é recusado; manter o já inativado passa', function () {
        $patient = Patient::create([
            'entity_id'   => $this->entity->id, 'person_id' => People::factory()->create(['full_name' => 'PACIENTE ANTIGO'])->id,
            'covenant_id' => $this->active->id, 'active' => true,
        ]);
        $payload = fn (string $covenantId) => ($this->patientPayload)(['name' => 'PACIENTE ANTIGO', 'covenant_id' => $covenantId, 'active' => true]);

        $this->putJson(route('panel.patients.update', $patient->id), $payload($this->inactive->id))
            ->assertUnprocessable()->assertJsonValidationErrors(['covenant_id']);

        $this->active->update(['active' => false]);

        $this->putJson(route('panel.patients.update', $patient->id), $payload($this->active->id))
            ->assertOk();
        expect($patient->fresh()->covenant_id)->toBe($this->active->id);
    });

    it('a tela de Pacientes só lista convênios ativos; o atual inativo do paciente vem no editData', function () {
        $patient = Patient::create([
            'entity_id'   => $this->entity->id, 'person_id' => People::factory()->create()->id,
            'covenant_id' => $this->inactive->id, 'active' => true,
        ]);

        $this->get(route('panel.patients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('covenants', fn ($list) => ! collect($list)->contains('id', $this->inactive->id)
                && collect($list)->contains('id', $this->active->id)));

        $this->getJson(route('panel.patients.editData', $patient))
            ->assertOk()
            ->assertJsonPath('data.covenant_option', ['id' => $this->inactive->id, 'name' => 'INATIVO SAUDE', 'active' => false]);
    });
});

describe('Tipo de consulta (mesma classe de gap: a tela esconde inativo, o POST gravava)', function () {
    it('agendamento novo e lista de espera recusam tipo de consulta inativo; editar mantendo o já inativado passa', function () {
        $inactiveVisit = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'RETORNO ANTIGO', 'active' => false]);
        $activeVisit   = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'CONSULTA', 'active' => true]);

        $this->postJson(route('panel.schedules.store'), f0SchedulePayload($this, ['visit_id' => $inactiveVisit->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit_id.0', __('validation.custom.visit_type.inactive'));
        $this->postJson(route('panel.waiting-list.store'), ['doctor_id' => $this->doctor->id, 'full_name' => 'X', 'visit_id' => $inactiveVisit->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['visit_id']);

        $schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'visit_id' => $activeVisit->id,
            'full_name' => 'TIPO ANTIGO', 'date_time' => $this->when, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $activeVisit->update(['active' => false]);

        $this->putJson(route('panel.schedules.update', $schedule), f0SchedulePayload($this, ['visit_id' => $activeVisit->id, 'notes' => 'ok']))
            ->assertOk();
    });
});

describe('Importações', function () {
    it('agenda: linha com convênio inativo vira erro da linha e não grava', function () {
        $this->doctor->update(['record' => '445566']);
        Storage::fake();
        $path = "imports/schedules/{$this->entity->id}/f0.csv";
        Storage::disk()->put($path, "crm_medico;nome_paciente;data_hora;situacao;convenio\n"
            . "445566;Linha Inativa;15/06/2026 14:30;Agendado;inativo saude\n"
            . "445566;Linha Ativa;15/06/2026 15:00;Agendado;ativo saude\n");

        $import = ScheduleImport::create([
            'entity_id' => $this->entity->id, 'user_id' => User::factory()->create()->id,
            'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'f0.csv',
        ]);

        app(ScheduleImportService::class)->process($import);
        $import->refresh();

        expect($import->imported_rows)->toBe(1)
            ->and($import->error_rows)->toBe(1)
            ->and(Schedule::withoutGlobalScopes()->where('full_name', 'LINHA INATIVA')->exists())->toBeFalse()
            ->and(Schedule::withoutGlobalScopes()->where('full_name', 'LINHA ATIVA')->value('covenant_id'))->toBe($this->active->id)
            ->and(Storage::disk()->get($import->errors_file_path))->toContain('inativo saude""')->toContain('está inativo — escolha um convênio ativo.');
    });

    it('pacientes: linha com convênio inativo vira erro da linha e não grava', function () {
        $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $entity->skipAutoTrial = true;
        $entity->save();
        $plan = Plan::factory()->create(['active' => true]);
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
        Subscription::factory()->create([
            'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        Covenant::factory()->create(['entity_id' => $entity->id, 'name' => 'Velho Plano', 'active' => false]);
        $ok = Covenant::factory()->create(['entity_id' => $entity->id, 'name' => 'Novo Plano', 'active' => true]);

        Storage::fake();
        $path = "imports/patients/{$entity->id}/f0.csv";
        Storage::disk()->put($path, "nome;celular;convenio\nPaciente Velho;11911112222;Velho Plano\nPaciente Novo;11933334444;Novo Plano\n");

        $import = PatientImport::create([
            'entity_id' => $entity->id, 'user_id' => User::factory()->create()->id,
            'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'f0.csv',
        ]);

        app(PatientImportService::class)->process($import);
        $import->refresh();

        expect($import->imported_rows)->toBe(1)
            ->and($import->error_rows)->toBe(1)
            ->and(Patient::withoutGlobalScopes()->where('entity_id', $entity->id)->sole()->covenant_id)->toBe($ok->id)
            ->and(Storage::disk()->get($import->errors_file_path))->toContain('Velho Plano""')->toContain('está inativo — escolha um convênio ativo.');
    });
});
