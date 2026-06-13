<?php

use App\Enums\{CashEntryNature, ClientRule, PaymentMethod, ScheduleSituation};
use App\Models\{Covenant, Entity, FinancialCashEntry, Procedure, ProcedurePrice, User};

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);
});

function actAttended($test, $schedule)
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->patchJson(route('panel.schedules.situation', $schedule), [
            'situation' => ScheduleSituation::Attended->value,
        ]);
}

function postArrivalCash($test, $schedule, array $payload)
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->postJson(route('panel.schedules.cash-entry.store', $schedule), array_merge([
            'entry_date'     => now()->toDateString(),
            'description'    => 'Paciente Teste',
            'payment_method' => PaymentMethod::Cash->value,
            'amount'         => 200.00,
        ], $payload));
}

describe('desacoplamento clínico × financeiro (item 1)', function () {
    it('marca Atendido sem exigir caixa por padrão (flag off)', function () {
        ['schedule' => $schedule] = createScheduleForEntity($this->entity, [
            'situation' => ScheduleSituation::InProgress->value,
        ]);

        actAttended($this, $schedule)->assertOk();

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('exige caixa para Atendido quando a entidade habilita a flag', function () {
        $this->entity->update(['requires_cash_to_complete' => true]);

        ['schedule' => $schedule] = createScheduleForEntity($this->entity, [
            'situation' => ScheduleSituation::InProgress->value,
        ]);

        actAttended($this, $schedule)
            ->assertStatus(422)
            ->assertJsonFragment(['requires_cash_entry' => true]);

        // Após lançar no caixa, a conclusão é liberada.
        postArrivalCash($this, $schedule, [])->assertOk();
        actAttended($this, $schedule)->assertOk();

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });
});

describe('natureza do lançamento (item 2/3)', function () {
    it('marca como Desk (balcão) para atendimento particular', function () {
        ['schedule' => $schedule] = createScheduleForEntity($this->entity, [
            'situation' => ScheduleSituation::Waiting->value,
        ]);

        postArrivalCash($this, $schedule, ['amount' => 180.00])->assertOk();

        expect(FinancialCashEntry::query()->firstOrFail()->nature)
            ->toBe(CashEntryNature::Desk);
    });

    it('marca como Copay (co-participação) para convênio cobrável', function () {
        $covenant  = Covenant::factory()->create(['entity_id' => null, 'active' => true]);
        $procedure = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        ProcedurePrice::factory()->create([
            'entity_id'    => $this->entity->id,
            'covenant_id'  => $covenant->id,
            'procedure_id' => $procedure->id,
            'price'        => 300.00,
            'charging'     => true,
            'active'       => true,
        ]);

        ['schedule' => $schedule] = createScheduleForEntity($this->entity, [
            'situation'   => ScheduleSituation::Waiting->value,
            'covenant_id' => $covenant->id,
        ]);

        postArrivalCash($this, $schedule, [
            'procedure_id' => $procedure->id,
            'amount'       => 40.00, // co-participação
        ])->assertOk();

        expect(FinancialCashEntry::query()->firstOrFail()->nature)
            ->toBe(CashEntryNature::Copay);
    });
});
