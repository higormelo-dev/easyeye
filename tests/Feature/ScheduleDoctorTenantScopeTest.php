<?php

use App\Enums\{ClientRule, PaymentMethod};
use App\Enums\ScheduleSituation;
use App\Models\{Entity, FinancialCashEntry, ScheduleEvent, User};

/*
|--------------------------------------------------------------------------
| Achado de segurança (auditoria panel.* IDOR — doctor_id via request body)
|--------------------------------------------------------------------------
|
| Doctor não tem entity_id direto (só via entity_user_id -> entity_users.
| entity_id). Endpoints que validavam doctor_id só com 'uuid' (sem escopar
| por tenant) aceitavam um médico de OUTRA clínica vindo do body da request.
| Este teste cobre os dois pontos corrigidos:
|   (a) ScheduleEventsController::update()
|   (b) SchedulesController::storeCashEntry() (ScheduleCashEntryRequest)
|
*/

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user   = User::factory()->create();

    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);

    $this->otherEntity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->foreignDoctor = createDoctorForEntity($this->otherEntity);
});

describe('PUT /panel/schedule-events/{scheduleEvent}', function () {
    beforeEach(function () {
        $this->ownDoctor = createDoctorForEntity($this->entity);

        $this->scheduleEvent = ScheduleEvent::create([
            'entity_id'  => $this->entity->id,
            'doctor_id'  => $this->ownDoctor->id,
            'title'      => 'Reunião de equipe',
            'type'       => 'meeting',
            'starts_at'  => now()->addDay(),
            'ends_at'    => now()->addDay()->addHour(),
            'created_by' => $this->entityUser->id,
        ]);
    });

    it('rejeita doctor_id de outra entity', function () {
        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->putJson(route('panel.schedule-events.update', $this->scheduleEvent), [
                'doctor_id' => $this->foreignDoctor->id,
                'title'     => 'Reunião de equipe',
                'type'      => 'meeting',
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at'   => now()->addDay()->addHour()->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id']);

        expect($this->scheduleEvent->fresh()->doctor_id)->toBe($this->ownDoctor->id);
    });

    it('aceita doctor_id da própria entity', function () {
        $anotherOwnDoctor = createDoctorForEntity($this->entity);

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->putJson(route('panel.schedule-events.update', $this->scheduleEvent), [
                'doctor_id' => $anotherOwnDoctor->id,
                'title'     => 'Reunião de equipe',
                'type'      => 'meeting',
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at'   => now()->addDay()->addHour()->toDateTimeString(),
            ])
            ->assertOk();

        expect($this->scheduleEvent->fresh()->doctor_id)->toBe($anotherOwnDoctor->id);
    });
});

describe('POST /panel/schedules/{schedule}/cash-entry', function () {
    beforeEach(function () {
        ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
            'situation' => ScheduleSituation::Waiting->value,
        ]);
    });

    it('rejeita doctor_id de outra entity', function () {
        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->postJson(route('panel.schedules.cash-entry.store', $this->schedule), [
                'entry_date'     => now()->toDateString(),
                'description'    => 'Consulta — Paciente Teste',
                'payment_method' => PaymentMethod::Cash->value,
                'amount'         => 250.00,
                'doctor_id'      => $this->foreignDoctor->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id']);

        expect(FinancialCashEntry::query()->count())->toBe(0);
    });

    it('aceita doctor_id da própria entity', function () {
        $ownDoctor = createDoctorForEntity($this->entity);

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->postJson(route('panel.schedules.cash-entry.store', $this->schedule), [
                'entry_date'     => now()->toDateString(),
                'description'    => 'Consulta — Paciente Teste',
                'payment_method' => PaymentMethod::Cash->value,
                'amount'         => 250.00,
                'doctor_id'      => $ownDoctor->id,
            ])
            ->assertOk();

        expect(FinancialCashEntry::query()->firstOrFail()->doctor_id)->toBe($ownDoctor->id);
    });
});
